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
            ? "The user pasted something in {$target} that they met in the wild — a series, a book, a "
                .'conversation. Keep their wording, correcting only clear typos, and pick out the one word '
                .'or expression in it that is most worth learning.'
            : "The user gave a word or an expression in {$target}. Write one sentence that uses it.";

        $lines = [
            "You prepare flashcards for someone learning {$target}. Their first language is {$native}.",
            $task,
            '',
            // The rule everything else serves. Without it a sentence arrives
            // with three unknown words in it, the learner fails it for reasons
            // they cannot diagnose, and the scheduler shows it forever.
            'The sentence must contain exactly ONE thing this person does not already know: the target.',
            '',
            'What the sentence has to be:',
            '- Something a real person would actually say this week. Ordinary, spoken, contemporary.',
            '- Short enough to read at a glance — around six to twelve words.',
            '- Built from the most common words in the language. Everything that is not the target should '
                .'be vocabulary a beginner already has.',
            '- In an everyday tense and register. Not literary, not formal, not a proverb.',
            '- Contracted where a speaker would contract. "I didn\'t expect", not "I did not expect" — '
                .'the uncontracted form is written English and teaches the wrong rhythm to say aloud.',
            // Stated as a contrast rather than as a model sentence: given a
            // finished example, the model hands it straight back the next time
            // that target comes up.
            '- A moment, not a definition. Prefer a sentence that describes something happening at a '
                .'particular time to one that explains when a word applies. If it reads like the "usage" '
                .'line in a dictionary entry, rewrite it as something that happened.',
            '',
            'What it must NOT be:',
            '- A motivational line, a slogan, a quotation or a poster caption. "Never give up on your '
                .'dreams" is exactly wrong: nobody says it, and it teaches nothing about how the words '
                .'behave.',
            '- A dictionary example built to demonstrate grammar.',
            '- A sentence that needs any other unusual word to make sense.',
            '',
            // The point they pushed back on: the learner's own choice is never
            // second-guessed. Only the words around it are constrained.
            'The target itself is never simplified. If the user asked for a rare or advanced word, use '
                .'exactly that word — it is what they want to learn. The plain-language rule applies only '
                .'to the rest of the sentence.',
            '',
            'Expressions matter as much as single words:',
            '- If the target is a phrasal verb, an idiom or a collocation, keep it whole and show it doing '
                .'its normal job — with the preposition, particle or complement it really takes.',
            '- Put it where it naturally falls. For a separable phrasal verb, use the placement people '
                .'actually use rather than the one that is easiest to parse.',
            '- If the user typed a bare word that is far more common as part of an expression, prefer the '
                .'expression.',
            '',
            'Fields:',
            '- "target" must appear in "sentence" exactly as written there, character for character, '
                .'including its inflection and any particle. Do not return a dictionary form as the target.',
            '- "target_lemma" is the dictionary form, used only to spot duplicates.',
            "- \"meaning\" is a short gloss in {$target}, not a translation. Say how the word is used, not "
                .'just what it denotes.',
            "- \"translation\" translates the whole sentence into {$native}.",
            '- "pronunciation" is IPA for the target, or an empty string when it adds nothing.',
            '- "image_query" is 2 to 5 English words describing the SCENE the sentence pictures, for a '
                .'stock photo search. Describe what a photo of this moment would show, never the abstract '
                .'meaning of the word. For "She borrowed my umbrella yesterday" that is "umbrella rain '
                .'street", not "borrowing" or "lending". Stock libraries match tags, so use concrete '
                .'nouns and no more than a few.',
        ];

        if ($request->knownWords !== []) {
            $lines[] = '';
            // A ceiling, not a palette. Told to "prefer" these, the model wedges
            // them in and produces sentences like "Don't give up on that coffee"
            // — grammatical, and not something anyone would ever say. The list
            // is here to say what may safely appear, never to say what should.
            $lines[] = 'This person is already studying the words below. They are safe to use, but do not '
                .'reach for them: a natural sentence always wins over one built out of this list. Use it '
                .'only as a guide to what is already familiar, so that nothing unusual beyond the target '
                .'slips in. '
                .implode(', ', $request->knownWords);
        }

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
