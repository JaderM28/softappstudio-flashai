<?php

namespace App\Services\Generation;

use App\Contracts\SentenceGenerator;
use App\Exceptions\GenerationFailed;
use App\Support\GeneratedNote;
use App\Support\GenerationRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * Talks to the Gemini Interactions API.
 *
 * Not generateContent, which still works but which Google now describes as the
 * older path — building on the recommended one costs a class today and avoids
 * a migration later. The differences that matter here: the model goes in the
 * body rather than the URL, the prompt is `input` rather than a `contents`
 * array, the schema lives under `response_format`, and the answer comes back
 * inside a `steps` array rather than `candidates`.
 */
class GeminiSentenceGenerator implements SentenceGenerator
{
    private const SERVICE = 'Gemini';

    public function generate(GenerationRequest $request): GeneratedNote
    {
        $key = config('services.gemini.key');

        if (blank($key)) {
            throw GenerationFailed::notConfigured(self::SERVICE);
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout((int) config('services.gemini.timeout'))
                ->retry(2, 500, throw: false)
                ->post(
                    rtrim(config('services.gemini.base_url'), '/').'/interactions',
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
            'model' => config('services.gemini.model'),
            'system_instruction' => $this->systemInstruction($request),
            'input' => $request->input,
            'response_format' => [
                // A schema is a structural guarantee; asking for "only JSON, no
                // markdown" in the prompt is a request the model can decline,
                // and it declines often enough to matter.
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => $this->schema(),
            ],
            'generation_config' => [
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
            '- "pronunciation" is IPA for the target, or an empty string when it adds nothing.',
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
     * Standard JSON Schema, with lowercase type names — unlike generateContent,
     * which took an OpenAPI subset with uppercase ones.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sentence' => ['type' => 'string', 'description' => 'The full sentence to study.'],
                'target' => ['type' => 'string', 'description' => 'The word or phrase being learned, exactly as it appears in the sentence.'],
                'target_lemma' => ['type' => 'string', 'description' => 'Dictionary form of the target.'],
                'meaning' => ['type' => 'string', 'description' => 'Short gloss in the target language.'],
                'translation' => ['type' => 'string', 'description' => 'The sentence translated into the native language.'],
                'pronunciation' => ['type' => 'string', 'description' => 'IPA for the target, or empty.'],
                'image_query' => ['type' => 'string', 'description' => 'Two to five English words describing the scene, for a stock photo search.'],
            ],
            'required' => ['sentence', 'target', 'target_lemma', 'meaning', 'translation', 'image_query'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function parse(?array $payload): GeneratedNote
    {
        $text = $this->extractText($payload);

        if ($text === null) {
            $status = data_get($payload, 'status') ?? 'no text in the response';

            throw GenerationFailed::unusableAnswer(self::SERVICE, (string) $status);
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

    /**
     * The answer sits in a `steps` array, which can hold more than the reply —
     * the user's own input comes back as a step too, and reasoning models add
     * their own. So the model_output steps are picked out by name rather than
     * by position.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function extractText(?array $payload): ?string
    {
        // The SDKs surface this convenience field; take it when it is there.
        $direct = data_get($payload, 'output_text');

        if (is_string($direct) && trim($direct) !== '') {
            return $direct;
        }

        $collected = '';

        foreach ((array) data_get($payload, 'steps', []) as $step) {
            if (data_get($step, 'type') !== 'model_output') {
                continue;
            }

            foreach ((array) data_get($step, 'content', []) as $part) {
                if (data_get($part, 'type') === 'text' && is_string(data_get($part, 'text'))) {
                    $collected .= data_get($part, 'text');
                }
            }
        }

        return trim($collected) === '' ? null : $collected;
    }
}
