<?php

namespace Tests\Feature;

use App\Contracts\SentenceGenerator;
use App\Exceptions\GenerationFailed;
use App\Models\Deck;
use App\Models\Note;
use App\Models\User;
use App\Services\Generation\CachedSentenceGenerator;
use App\Services\Generation\FakeSentenceGenerator;
use App\Services\Generation\GeminiSentenceGenerator;
use App\Support\GeneratedNote;
use App\Support\GenerationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SentenceGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'test-key',
            'services.gemini.base_url' => 'https://generativelanguage.example/v1beta',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
            'services.gemini.cache_ttl' => 0,
        ]);
    }

    /**
     * Shaped like a real Interactions API reply: the answer arrives inside a
     * `steps` array rather than `candidates`.
     *
     * @param  array<string, mixed>  $payload
     */
    private function geminiReturns(array $payload): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => 'v1_test',
                'model' => 'gemini-3.5-flash-lite',
                'object' => 'interaction',
                'status' => 'completed',
                'steps' => [
                    [
                        'type' => 'model_output',
                        'content' => [['type' => 'text', 'text' => json_encode($payload)]],
                    ],
                ],
            ]),
        ]);
    }

    private function samplePayload(array $overrides = []): array
    {
        return array_merge([
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'borrowed',
            'target_lemma' => 'borrow',
            'meaning' => 'took something to use and give back later',
            'translation' => 'Ayer me pidió prestado el paraguas.',
            'pronunciation' => '/ˈbɒrəʊd/',
            'image_query' => 'umbrella rain street',
        ], $overrides);
    }

    private function request(string $input = 'borrow'): GenerationRequest
    {
        return new GenerationRequest($input, 'en', 'es');
    }

    // ------------------------------------------------------------ the client

    public function test_it_asks_gemini_for_schema_valid_json(): void
    {
        $this->geminiReturns($this->samplePayload());

        (new GeminiSentenceGenerator)->generate($this->request());

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            // A schema is a structural guarantee; prompting for "only JSON" is
            // a request the model can decline.
            $this->assertSame('application/json', $body['response_format']['mime_type']);
            $this->assertSame('object', $body['response_format']['schema']['type']);
            $this->assertContains('target', $body['response_format']['schema']['required']);

            // Interactions API: the model travels in the body, not the URL.
            $this->assertSame('gemini-3.5-flash-lite', $body['model']);
            $this->assertSame('test-key', $request->header('x-goog-api-key')[0]);

            return str_ends_with($request->url(), '/v1beta/interactions');
        });
    }

    /**
     * The reply can carry more than one step — the user's own input comes back
     * as one, and reasoning models add their own — so the answer is picked out
     * by step type rather than by position.
     */
    public function test_the_answer_is_found_among_several_steps(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'completed',
                'steps' => [
                    ['type' => 'user_input', 'content' => [['type' => 'text', 'text' => 'borrow']]],
                    ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode($this->samplePayload())]]],
                ],
            ]),
        ]);

        $note = (new GeminiSentenceGenerator)->generate($this->request());

        $this->assertSame('borrowed', $note->target);
    }

    public function test_a_reply_with_no_model_output_is_reported(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'failed',
                'steps' => [['type' => 'user_input', 'content' => [['type' => 'text', 'text' => 'borrow']]]],
            ]),
        ]);

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessage('failed');

        (new GeminiSentenceGenerator)->generate($this->request());
    }

    public function test_it_maps_the_answer_onto_a_note(): void
    {
        $this->geminiReturns($this->samplePayload());

        $note = (new GeminiSentenceGenerator)->generate($this->request());

        $this->assertSame('She borrowed my umbrella yesterday.', $note->sentence);
        $this->assertSame('borrowed', $note->target);
        $this->assertSame('borrow', $note->targetLemma);
        $this->assertSame('umbrella rain street', $note->imageQuery);
        $this->assertTrue($note->hasUsableTarget());
    }

    /**
     * The prompt has to tell the model which job it is doing, because writing a
     * sentence around a word and picking the key part out of one are opposite
     * tasks.
     */
    public function test_the_prompt_differs_for_a_word_and_for_a_pasted_sentence(): void
    {
        $this->geminiReturns($this->samplePayload());
        $generator = new GeminiSentenceGenerator;

        $generator->generate($this->request('borrow'));
        $generator->generate($this->request('She borrowed my umbrella yesterday.'));

        $instructions = [];

        Http::assertSent(function (Request $request) use (&$instructions) {
            $instructions[] = $request->data()['system_instruction'];

            return true;
        });

        // Asserted on the distinction rather than the wording: the two tasks are
        // genuinely different — one writes a sentence, the other keeps the
        // user's own and picks the word out of it — and the prose around that
        // gets tuned often.
        $this->assertStringContainsString('Write one sentence that uses it', $instructions[0]);
        $this->assertStringNotContainsString('Keep their wording', $instructions[0]);

        $this->assertStringContainsString('Keep their wording', $instructions[1]);
        $this->assertStringContainsString('met in the wild', $instructions[1]);
    }

    public function test_deck_instructions_are_folded_into_the_prompt(): void
    {
        $this->geminiReturns($this->samplePayload());

        (new GeminiSentenceGenerator)->generate(
            new GenerationRequest('borrow', 'en', 'es', 'medical vocabulary, formal register')
        );

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['system_instruction'],
            'medical vocabulary, formal register',
        ));
    }

    /**
     * Searching a stock library for "borrowed" returns handshakes and loan
     * paperwork, so the model is asked for the scene instead.
     */
    public function test_the_prompt_asks_for_a_scene_not_the_word(): void
    {
        $this->geminiReturns($this->samplePayload());

        (new GeminiSentenceGenerator)->generate($this->request());

        Http::assertSent(function (Request $request) {
            $prompt = $request->data()['system_instruction'];

            return str_contains($prompt, 'SCENE')
                && str_contains($prompt, 'umbrella rain street');
        });
    }

    // ---------------------------------------------------------------- failure

    public function test_a_missing_key_is_reported_rather_than_called(): void
    {
        config(['services.gemini.key' => null]);
        Http::fake();

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessage('no API key');

        (new GeminiSentenceGenerator)->generate($this->request());
    }

    public function test_an_http_error_is_reported(): void
    {
        Http::fake(['*' => Http::response(['error' => 'quota'], 429)]);

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessage('HTTP 429');

        (new GeminiSentenceGenerator)->generate($this->request());
    }

    public function test_a_blocked_response_is_reported(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'blocked',
            'steps' => [],
        ])]);

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessage('blocked');

        (new GeminiSentenceGenerator)->generate($this->request());
    }

    public function test_json_that_does_not_parse_is_reported(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'completed',
            'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{not json']]]],
        ])]);

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessage('did not parse');

        (new GeminiSentenceGenerator)->generate($this->request());
    }

    public function test_an_empty_sentence_is_reported(): void
    {
        $this->geminiReturns($this->samplePayload(['sentence' => '', 'target' => '']));

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessage('came back empty');

        (new GeminiSentenceGenerator)->generate($this->request());
    }

    /**
     * The one thing the model can get wrong while returning perfectly valid
     * JSON: a dictionary form where the sentence uses an inflected one, which
     * leaves nothing for a fill-in-the-blank card to hide.
     */
    public function test_a_target_missing_from_the_sentence_is_detected(): void
    {
        $this->geminiReturns($this->samplePayload(['target' => 'borrow']));

        $note = (new GeminiSentenceGenerator)->generate($this->request());

        $this->assertFalse($note->hasUsableTarget());
    }

    // ---------------------------------------------------------------- caching

    public function test_the_same_input_is_only_generated_once(): void
    {
        config(['services.gemini.cache_ttl' => 600]);
        Cache::flush();

        $inner = new FakeSentenceGenerator;
        $cached = new CachedSentenceGenerator($inner);

        $first = $cached->generate($this->request('borrow'));
        $second = $cached->generate($this->request('borrow'));

        $this->assertSame(1, $inner->callCount());
        $this->assertEquals($first, $second);
    }

    public function test_deck_settings_are_part_of_the_cache_key(): void
    {
        config(['services.gemini.cache_ttl' => 600]);
        Cache::flush();

        $inner = new FakeSentenceGenerator;
        $cached = new CachedSentenceGenerator($inner);

        $cached->generate(new GenerationRequest('borrow', 'en', 'es'));
        $cached->generate(new GenerationRequest('borrow', 'en', 'es', 'medical vocabulary'));

        // The same word in a medical deck must not reuse the general answer.
        $this->assertSame(2, $inner->callCount());
    }

    public function test_a_failure_is_not_cached(): void
    {
        config(['services.gemini.cache_ttl' => 600]);
        Cache::flush();

        $inner = (new FakeSentenceGenerator)->willFail();
        $cached = new CachedSentenceGenerator($inner);

        foreach (range(1, 2) as $ignored) {
            try {
                $cached->generate($this->request('borrow'));
            } catch (GenerationFailed) {
                // Expected; the point is that it is retried rather than stored.
            }
        }

        $this->assertSame(2, $inner->callCount());
    }

    // ----------------------------------------------------------------- screen

    public function test_generating_fills_the_form_without_saving_anything(): void
    {
        $user = User::factory()->create();
        $fake = (new FakeSentenceGenerator)->willReturn(
            GeneratedNote::fromArray($this->samplePayload())
        );
        $this->app->instance(SentenceGenerator::class, $fake);

        $this->actingAs($user)
            ->post(route('notes.generate'), ['input' => 'borrow'])
            ->assertRedirect(route('notes.create', ['deck' => $user->defaultDeck()->id]))
            ->assertSessionHas('generated.sentence', 'She borrowed my umbrella yesterday.')
            ->assertSessionHas('generated.image_query', 'umbrella rain street');

        // Nothing is saved until the user approves it.
        $this->assertDatabaseCount('notes', 0);
        $this->assertTrue($fake->called());
    }

    public function test_the_generated_values_appear_in_the_form(): void
    {
        $user = User::factory()->create();
        $this->app->instance(
            SentenceGenerator::class,
            (new FakeSentenceGenerator)->willReturn(GeneratedNote::fromArray($this->samplePayload()))
        );

        $this->actingAs($user)
            ->post(route('notes.generate'), ['input' => 'borrow']);

        $this->actingAs($user)
            ->get(route('notes.create'))
            ->assertOk()
            ->assertSee('She borrowed my umbrella yesterday.')
            ->assertSee('Nothing is saved yet')
            ->assertSee('umbrella rain street');
    }

    public function test_a_target_the_model_got_wrong_is_flagged_on_the_form(): void
    {
        $user = User::factory()->create();
        $this->app->instance(
            SentenceGenerator::class,
            (new FakeSentenceGenerator)->willReturn(
                GeneratedNote::fromArray($this->samplePayload(['target' => 'borrow']))
            )
        );

        $this->actingAs($user)->post(route('notes.generate'), ['input' => 'borrow']);

        $this->actingAs($user)
            ->get(route('notes.create'))
            ->assertOk()
            ->assertSee('does not appear in the sentence');
    }

    public function test_a_generation_failure_leaves_the_user_able_to_type_it_themselves(): void
    {
        $user = User::factory()->create();
        $this->app->instance(SentenceGenerator::class, (new FakeSentenceGenerator)->willFail());

        $this->actingAs($user)
            ->post(route('notes.generate'), ['input' => 'borrow'])
            ->assertRedirect()
            ->assertSessionHasErrors(['input' => 'Could not reach Gemini. You can still write it yourself below.']);

        $this->assertDatabaseCount('notes', 0);
    }

    /**
     * The screen must never show raw API JSON, which is exactly what the
     * exception message itself carries for the log.
     */
    public function test_api_errors_are_translated_before_they_reach_the_screen(): void
    {
        $raw = '{"error":{"code":400,"message":"API key not valid"}}';
        $failure = GenerationFailed::rejected('Gemini', 400, $raw);

        $this->assertStringContainsString($raw, $failure->getMessage());
        $this->assertStringNotContainsString('{"error"', $failure->userMessage());
        $this->assertStringContainsString('GEMINI_API_KEY', $failure->userMessage());
    }

    public function test_each_kind_of_failure_says_something_useful(): void
    {
        $messages = [
            GenerationFailed::rejected('Gemini', 429, 'RESOURCE_EXHAUSTED')->userMessage() => 'free daily limit',
            GenerationFailed::rejected('Gemini', 503, 'unavailable')->userMessage() => 'having trouble',
            GenerationFailed::notConfigured('Gemini')->userMessage() => 'not set up yet',
            GenerationFailed::unreachable('Gemini')->userMessage() => 'Could not reach',
        ];

        foreach ($messages as $shown => $expected) {
            $this->assertStringContainsString($expected, $shown);
            // Every one of them points back at the escape hatch.
            $this->assertStringContainsString('write it yourself', $shown);
        }
    }

    public function test_a_used_up_quota_says_so_plainly_on_the_screen(): void
    {
        $user = User::factory()->create();
        $this->app->instance(SentenceGenerator::class, (new FakeSentenceGenerator)->willFail(
            GenerationFailed::rejected('Gemini', 429, '{"error":"RESOURCE_EXHAUSTED"}')
        ));

        $this->actingAs($user)
            ->post(route('notes.generate'), ['input' => 'borrow'])
            ->assertSessionHasErrors([
                'input' => "Gemini's free daily limit is used up. Try again tomorrow. You can still write it yourself below.",
            ]);
    }

    public function test_generation_needs_something_to_work_from(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('notes.generate'), ['input' => ''])
            ->assertSessionHasErrors('input');
    }

    public function test_saving_an_approved_note_keeps_the_image_query_and_lemma(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('notes.store'), [
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'borrowed',
            'target_lemma' => 'borrow',
            'meaning' => 'took something to use and give back later',
            'image_query' => 'umbrella rain street',
        ]);

        $note = Note::query()->sole();

        $this->assertSame('umbrella rain street', $note->image_query);
        // The model's dictionary form, not the one guessed from "borrowed".
        $this->assertSame('borrow', $note->target_lemma);
    }

    public function test_the_generate_box_is_hidden_without_an_api_key(): void
    {
        config(['services.gemini.key' => null]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('notes.create'))
            ->assertOk()
            ->assertDontSee('Word or sentence')
            ->assertSee('Sentence');
    }

    public function test_a_user_cannot_generate_into_someone_elses_deck(): void
    {
        $user = User::factory()->create();
        $othersDeck = Deck::factory()->create();

        $this->actingAs($user)
            ->post(route('notes.generate'), ['input' => 'borrow', 'deck_id' => $othersDeck->id])
            ->assertSessionHasErrors('deck_id');
    }
    // ------------------------------------------------- what the prompt asks for

    /**
     * The rule the whole design rests on, and the one nothing enforced before.
     */
    public function test_the_prompt_asks_for_exactly_one_new_thing_in_plain_language(): void
    {
        $this->geminiReturns($this->payload());

        app(GeminiSentenceGenerator::class)->generate($this->generationRequest('borrowed'));

        Http::assertSent(function ($request) {
            $prompt = $request['system_instruction'];

            return str_contains($prompt, 'exactly ONE thing this person does not already know')
                && str_contains($prompt, 'most common words')
                && str_contains($prompt, 'six to twelve words');
        });
    }

    /**
     * "Never give up on your dreams" is what the old prompt produced for a
     * phrasal verb: a poster caption nobody says, teaching nothing about how
     * the words behave.
     */
    public function test_the_prompt_rules_out_slogans_and_dictionary_examples(): void
    {
        $this->geminiReturns($this->payload());

        app(GeminiSentenceGenerator::class)->generate($this->generationRequest('give up'));

        Http::assertSent(function ($request) {
            $prompt = $request['system_instruction'];

            return str_contains($prompt, 'motivational line')
                && str_contains($prompt, 'A moment, not a definition')
                && str_contains($prompt, 'Contracted where a speaker would contract');
        });
    }

    /**
     * The point the user pushed back on: a level cage would stop them learning
     * what they actually want. Only the surrounding words are constrained.
     */
    public function test_the_target_itself_is_never_simplified(): void
    {
        $this->geminiReturns($this->payload());

        app(GeminiSentenceGenerator::class)->generate($this->generationRequest('serendipity'));

        Http::assertSent(fn ($request) => str_contains($request['system_instruction'], 'The target itself is never simplified')
            && str_contains($request['system_instruction'], 'exactly that word'));
    }

    public function test_expressions_are_asked_for_as_carefully_as_words(): void
    {
        $this->geminiReturns($this->payload());

        app(GeminiSentenceGenerator::class)->generate($this->generationRequest('run into'));

        Http::assertSent(function ($request) {
            $prompt = $request['system_instruction'];

            return str_contains($prompt, 'phrasal verb, an idiom or a collocation')
                && str_contains($prompt, 'keep it whole');
        });
    }

    // ------------------------------------------------------- what is known

    /**
     * Told to "prefer" the known words, the model wedged them in and produced
     * "Don't give up on that coffee" — grammatical, and not something anyone
     * would say. The list is a ceiling on unfamiliar vocabulary, never a palette.
     */
    public function test_known_words_are_offered_as_a_ceiling_not_a_palette(): void
    {
        $this->geminiReturns($this->payload());

        app(GeminiSentenceGenerator::class)->generate(
            $this->generationRequest('give up', ['umbrella', 'coffee', 'postman']),
        );

        Http::assertSent(function ($request) {
            $prompt = $request['system_instruction'];

            return str_contains($prompt, 'do not reach for them')
                && str_contains($prompt, 'a natural sentence always wins')
                && str_contains($prompt, 'umbrella, coffee, postman');
        });
    }

    public function test_nothing_about_known_words_is_said_when_there_are_none(): void
    {
        $this->geminiReturns($this->payload());

        app(GeminiSentenceGenerator::class)->generate($this->generationRequest('borrowed'));

        Http::assertSent(fn ($request) => ! str_contains($request['system_instruction'], 'already studying'));
    }

    /**
     * The known words shape the answer, so they are part of the question. Without
     * this, one learner's sentence is handed to another whose vocabulary it was
     * never built around.
     */
    public function test_the_cache_can_tell_two_learners_apart(): void
    {
        $bare = $this->generationRequest('give up');
        $withContext = $this->generationRequest('give up', ['umbrella', 'coffee']);

        $this->assertNotSame($bare->fingerprint(), $withContext->fingerprint());

        $this->assertSame(
            $withContext->fingerprint(),
            $this->generationRequest('give up', ['umbrella', 'coffee'])->fingerprint(),
        );
    }

    /**
     * @param  array<int, string>  $knownWords
     */
    private function generationRequest(string $input, array $knownWords = []): \App\Support\GenerationRequest
    {
        return \App\Support\GenerationRequest::forDeck(
            $input,
            new \App\Models\Deck(['target_language' => 'en', 'native_language' => 'es']),
            $knownWords,
        );
    }

    /**
     * @return array<string, string>
     */
    private function payload(): array
    {
        return [
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'borrowed',
            'target_lemma' => 'borrow',
            'meaning' => 'took and gave back',
            'translation' => 'Ayer me pidio prestado el paraguas.',
            'image_query' => 'umbrella rain street',
        ];
    }

}
