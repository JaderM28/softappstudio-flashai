<?php

namespace Tests\Feature;

use App\Actions\SpeakWord;
use App\Contracts\ImageProvider;
use App\Contracts\SpeechSynthesizer;
use App\Enums\CardQueue;
use App\Enums\CardType;
use App\Exceptions\MediaFetchFailed;
use App\Models\Card;
use App\Models\Deck;
use App\Models\Note;
use App\Models\User;
use App\Services\Media\FakeImageProvider;
use App\Services\Media\FakeSpeechSynthesizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tapping a word in a sentence to hear it.
 */
class WordAudioTest extends TestCase
{
    use RefreshDatabase;

    private FakeSpeechSynthesizer $speech;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('flashai.media.disk'));

        $this->speech = new FakeSpeechSynthesizer;
        $this->app->instance(SpeechSynthesizer::class, $this->speech);
        $this->app->instance(ImageProvider::class, new FakeImageProvider);

        config(['services.cloudflare.tts.voice' => 'angus']);
    }

    // ------------------------------------------------------------- the action

    /**
     * The economy of the whole feature: `coffee` is `coffee` in every sentence
     * that contains it, and in everybody's. Paid for once, ever.
     */
    public function test_a_word_is_synthesised_once_and_then_served_from_storage(): void
    {
        $speak = app(SpeakWord::class);

        $first = $speak->handle('coffee', 'en');
        $second = $speak->handle('coffee', 'en');

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->speech->calls, 'The word was synthesised twice.');
    }

    public function test_the_same_word_is_shared_across_notes_and_people(): void
    {
        $speak = app(SpeakWord::class);

        $speak->handle('coffee', 'en');

        // A different learner, a different card, the same word.
        $this->actingAs(User::factory()->create());
        $speak->handle('coffee', 'en');

        $this->assertCount(1, $this->speech->calls);
    }

    /**
     * `coffee.` read aloud carries a falling intonation the word does not have
     * on its own — and would be a second cache entry for the same word.
     */
    public function test_punctuation_is_not_part_of_the_word(): void
    {
        $speak = app(SpeakWord::class);

        $bare = $speak->handle('coffee', 'en');
        $stopped = $speak->handle('coffee.', 'en');
        $quoted = $speak->handle('"coffee"', 'en');

        $this->assertSame($bare, $stopped);
        $this->assertSame($bare, $quoted);
        $this->assertCount(1, $this->speech->calls);
        $this->assertSame('coffee', $this->speech->calls[0]['text']);
    }

    public function test_the_same_word_in_two_languages_is_two_clips(): void
    {
        $speak = app(SpeakWord::class);

        $this->assertNotSame(
            $speak->handle('no', 'en'),
            $speak->handle('no', 'es'),
        );

        $this->assertCount(2, $this->speech->calls);
    }

    /**
     * Changing the voice in config must not leave a learner hearing the
     * sentence in one voice and its words in another.
     */
    public function test_changing_the_voice_produces_a_different_clip(): void
    {
        $speak = app(SpeakWord::class);

        $angus = $speak->handle('coffee', 'en');

        config(['services.cloudflare.tts.voice' => 'luna']);

        $this->assertNotSame($angus, $speak->handle('coffee', 'en'));
    }

    // --------------------------------------------------------- the endpoint

    public function test_a_guest_cannot_spend_quota(): void
    {
        $this->postJson(route('speak'), ['word' => 'coffee', 'language' => 'en'])
            ->assertUnauthorized();
    }

    public function test_it_answers_with_the_url_of_the_clip(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('speak'), ['word' => 'coffee', 'language' => 'en'])
            ->assertOk()
            ->assertJsonStructure(['url']);
    }

    /**
     * Bounded so this cannot be used to read a paragraph aloud on our quota.
     */
    public function test_it_refuses_anything_that_is_not_a_word(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('speak'), ['word' => str_repeat('a', 200), 'language' => 'en'])
            ->assertJsonValidationErrors('word');

        $this->actingAs($user)
            ->postJson(route('speak'), ['language' => 'en'])
            ->assertJsonValidationErrors('word');
    }

    /**
     * A word that cannot be spoken is a disappointment, not a broken review.
     */
    public function test_a_failure_answers_rather_than_breaking_the_session(): void
    {
        $this->speech->willFail(MediaFetchFailed::rejected('Cloudflare speech', 429, 'out of neurons'));

        $this->actingAs(User::factory()->create())
            ->postJson(route('speak'), ['word' => 'coffee', 'language' => 'en'])
            ->assertStatus(503)
            ->assertJsonPath('error', "Cloudflare speech's free limit is used up for now.");
    }

    // ------------------------------------------------------- the review screen

    /**
     * The property that matters most on this feature.
     *
     * Words are tappable only once the answer is revealed. A tappable word in
     * the blanked prompt would read the answer aloud — the one thing a
     * flashcard must never do.
     */
    public function test_the_blanked_prompt_has_nothing_to_tap(): void
    {
        $user = User::factory()->create();
        $note = Note::factory()->inDeck(Deck::factory()->for($user)->create())->create([
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'borrowed',
        ]);
        Card::factory()->inNote($note)->ofType(CardType::Cloze)->create([
            'queue' => CardQueue::New,
            'next_review_at' => now(),
        ]);

        $html = $this->actingAs($user)->get(route('review.show'))->assertOk()->getContent();

        // The prompt with the blank in it, exactly as rendered before reveal.
        $this->assertMatchesRegularExpression(
            '/x-show="!revealed"[^>]*>\s*She _+ my umbrella yesterday\.\s*</',
            $html,
            'The blanked prompt is not plain text — a word in it could be tapped and heard.',
        );

        // And the revealed one is the tappable version.
        $this->assertStringContainsString('spokenSentence(', $html);
        $this->assertStringContainsString('>borrowed</button>', $html);
    }
}
