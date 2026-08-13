<?php

namespace App\Services\Generation;

use App\Contracts\SentenceGenerator;
use App\Exceptions\GenerationFailed;
use App\Support\GeneratedNote;
use App\Support\GenerationRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

class GeminiSentenceGenerator implements SentenceGenerator
{
    private const SERVICE = 'Gemini';

    public function generate(GenerationRequest $request): GeneratedNote
    {
        $key = config('services.gemini.key');

        if (blank($key)) {
            throw GenerationFailed::notConfigured(self::SERVICE);
        }

        $model = config('services.gemini.model');

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout((int) config('services.gemini.timeout'))
                ->retry(2, 500, throw: false)
                ->post(
                    rtrim(config('services.gemini.base_url'), '/')."/models/{$model}:generateContent",
                    $this->body($request),
                );
        } catch (ConnectionException $e) {
            throw GenerationFailed::unreachable(self::SERVICE, $e->getMessage());
        }

        if ($response->failed()) {
            throw GenerationFailed::rejected(self::SERVICE, $response->status(), $response->body());
        }

        return $this->parse($response->json());
    }

    /**
     * @return array<string, mixed>
     */
    private function body(GenerationRequest $request): array
    {
        return [
            'systemInstruction' => [
                'parts' => [['text' => $this->systemInstruction($request)]],
            ],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $request->input]]],
            ],
            'generationConfig' => [
                // A schema is a structural guarantee; asking for "only JSON, no
                // markdown" in the prompt is a request the model can decline,
                // and it declines often enough to matter.
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->schema(),
                // Low, but not zero: the sentences should be natural rather
                // than the same three templates forever.
                'temperature' => 0.4,
            ],
        ];
    }

    private function systemInstruction(GenerationRequest $request): string
    {
        $target = $request->targetLanguage;
        $native = $request->nativeLanguage;

        $task = $request->looksLikeSentence()
            ? "The user pasted a sentence in {$target} that they met in the wild. Keep it as it is, "
                .'correcting only clear typos, and pick out the single word or fixed phrase in it that is '
                .'most worth learning.'
            : "The user gave a word or phrase in {$target}. Write one natural, everyday sentence that uses "
                .'it, short enough to read at a glance, and containing nothing else unusual — the sentence '
                .'should have exactly one thing in it worth learning.';

        $lines = [
            "You prepare flashcards for someone learning {$target}. Their first language is {$native}.",
            $task,
            '',
            'Rules:',
            '- "target" must appear in "sentence" exactly as written there, character for character, '
                .'including its inflection. Do not return a dictionary form as the target.',
            '- "target_lemma" is the dictionary form, used only to spot duplicates.',
            "- \"meaning\" is a short gloss in {$target}, not a translation.",
            "- \"translation\" translates the whole sentence into {$native}.",
            '- "pronunciation" is IPA for the target, or null when it adds nothing.',
            '- "image_query" is 2 to 5 English words describing the SCENE the sentence pictures, for a '
                .'stock photo search. Describe what a photo of this moment would show, never the abstract '
                .'meaning of the word. For "She borrowed my umbrella yesterday" that is "umbrella rain '
                .'street", not "borrowing" or "lending".',
        ];

        if (filled($request->instructions)) {
            $lines[] = '';
            $lines[] = 'The user also asked for: '.$request->instructions;
        }

        return implode("\n", $lines);
    }

    /**
     * Gemini takes an OpenAPI subset, with uppercase type names.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'sentence' => ['type' => 'STRING'],
                'target' => ['type' => 'STRING'],
                'target_lemma' => ['type' => 'STRING'],
                'meaning' => ['type' => 'STRING'],
                'translation' => ['type' => 'STRING'],
                'pronunciation' => ['type' => 'STRING', 'nullable' => true],
                'image_query' => ['type' => 'STRING'],
            ],
            'required' => ['sentence', 'target', 'target_lemma', 'meaning', 'translation', 'image_query'],
            'propertyOrdering' => [
                'sentence', 'target', 'target_lemma', 'meaning', 'translation', 'pronunciation', 'image_query',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function parse(?array $payload): GeneratedNote
    {
        $text = data_get($payload, 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            $reason = data_get($payload, 'candidates.0.finishReason')
                ?? data_get($payload, 'promptFeedback.blockReason')
                ?? 'no content in the response';

            throw GenerationFailed::unusableAnswer(self::SERVICE, (string) $reason);
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw GenerationFailed::unusableAnswer(self::SERVICE, 'the JSON did not parse: '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw GenerationFailed::unusableAnswer(self::SERVICE, 'the response was not an object');
        }

        $note = GeneratedNote::fromArray($decoded);

        // The schema guarantees the fields exist, not that they are filled in.
        if ($note->sentence === '' || $note->target === '') {
            throw GenerationFailed::unusableAnswer(self::SERVICE, 'the sentence or target came back empty');
        }

        return $note;
    }
}
