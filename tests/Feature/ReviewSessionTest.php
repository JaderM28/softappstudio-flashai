<?php

namespace Tests\Feature;

use App\Enums\CardQueue;
use App\Enums\CardType;
use App\Enums\ReviewGrade;
use App\Models\Card;
use App\Models\CardReview;
use App\Models\Deck;
use App\Models\Note;
use App\Models\User;
use App\Contracts\ImageProvider;
use App\Contracts\SpeechSynthesizer;
use App\Services\Media\FakeImageProvider;
use App\Services\Media\FakeSpeechSynthesizer;
use App\Services\ReviewQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReviewSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'flashai.scheduler.learning_steps' => [1, 10],
            'flashai.scheduler.fuzz_percent' => 0,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function cardFor(User $user, array $state = []): Card
    {
        $note = Note::factory()->inDeck(Deck::factory()->for($user)->create())->create();

        return Card::factory()->inNote($note)->create($state);
    }

    // ------------------------------------------------------------- ordering

    public function test_learning_cards_are_served_before_reviews_and_new_ones(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();

        $new = Card::factory()->inNote(Note::factory()->inDeck($deck)->create())->create();
        $review = Card::factory()->inNote(Note::factory()->inDeck($deck)->create())
            ->review()->dueAt(now()->subDay())->create();
        $learning = Card::factory()->inNote(Note::factory()->inDeck($deck)->create())
            ->learning()->dueAt(now()->subMinutes(5))->create();

        $queue = app(ReviewQueue::class);

        $this->assertTrue($queue->nextCard($user)->is($learning));

        $learning->update(['next_review_at' => now()->addHour()]);
        $this->assertTrue($queue->nextCard($user)->is($review));

        $review->update(['next_review_at' => now()->addDay()]);
        $this->assertTrue($queue->nextCard($user)->is($new));
    }

    public function test_within_a_queue_the_longest_waiting_card_goes_first(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();

        Card::factory()->inNote(Note::factory()->inDeck($deck)->create())
            ->review()->dueAt(now()->subHour())->create();
        $oldest = Card::factory()->inNote(Note::factory()->inDeck($deck)->create())
            ->review()->dueAt(now()->subWeek())->create();

        $this->assertTrue(app(ReviewQueue::class)->nextCard($user)->is($oldest));
    }

    public function test_the_queue_never_reaches_another_users_cards(): void
    {
        $user = User::factory()->create();
        $this->cardFor(User::factory()->create());

        $this->assertNull(app(ReviewQueue::class)->nextCard($user));
    }

    public function test_a_card_whose_note_cannot_ask_its_question_is_skipped(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();

        // A listening card whose audio never generated.
        $silent = Note::factory()->inDeck($deck)->withoutAudio()->create();
        Card::factory()->inNote($silent)->ofType(CardType::Listening)->create();

        $this->assertNull(app(ReviewQueue::class)->nextCard($user));
    }

    // ------------------------------------------------------------- screens

    public function test_a_guest_cannot_reach_the_review_screen(): void
    {
        $this->get(route('review.show'))->assertRedirect(route('login'));
    }

    public function test_the_review_screen_shows_the_cloze_prompt_not_the_answer(): void
    {
        $user = User::factory()->create();
        $note = Note::factory()->inDeck(Deck::factory()->for($user)->create())->create([
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'borrowed',
        ]);
        Card::factory()->inNote($note)->ofType(CardType::Cloze)->create();

        $this->actingAs($user)
            ->get(route('review.show'))
            ->assertOk()
            ->assertSee('She _____ my umbrella yesterday.');
    }

    public function test_the_done_screen_appears_when_nothing_is_due(): void
    {
        $user = User::factory()->create();
        $this->cardFor($user, ['next_review_at' => now()->addWeek()]);

        $this->actingAs($user)
            ->get(route('review.show'))
            ->assertOk()
            ->assertSee('Nothing left due');
    }

    // -------------------------------------------------------------- grading

    public function test_grading_advances_the_card_and_returns_to_the_session(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);

        $this->actingAs($user)
            ->post(route('review.grade', $card), ['grade' => ReviewGrade::Good->value])
            ->assertRedirect(route('review.show'));

        $card->refresh();

        $this->assertSame(CardQueue::Learning, $card->queue);
        $this->assertSame(1, $card->learning_step);
        $this->assertSame(1, CardReview::query()->count());
    }

    public function test_the_answer_time_is_recorded(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);

        $this->actingAs($user)->post(route('review.grade', $card), [
            'grade' => ReviewGrade::Good->value,
            'duration_ms' => 4200,
        ]);

        $this->assertSame(4200, CardReview::query()->sole()->duration_ms);
    }

    public function test_an_invalid_grade_is_rejected(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);

        $this->actingAs($user)
            ->post(route('review.grade', $card), ['grade' => 9])
            ->assertSessionHasErrors('grade');

        $this->assertSame(0, CardReview::query()->count());
    }

    public function test_a_user_cannot_grade_someone_elses_card(): void
    {
        $user = User::factory()->create();
        $others = $this->cardFor(User::factory()->create());

        $this->actingAs($user)
            ->post(route('review.grade', $others), ['grade' => ReviewGrade::Good->value])
            ->assertForbidden();

        $this->assertSame(0, CardReview::query()->count());
    }

    public function test_a_suspended_card_cannot_be_graded(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);
        $card->update(['queue' => CardQueue::Suspended]);

        $this->actingAs($user)
            ->post(route('review.grade', $card), ['grade' => ReviewGrade::Good->value])
            ->assertForbidden();
    }

    // ------------------------------------------------------------ dashboard

    public function test_the_dashboard_counts_what_is_due(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();

        // A note can only hold one card per type, so three cards means three
        // sentences.
        foreach (range(1, 3) as $ignored) {
            Card::factory()->inNote(Note::factory()->inDeck($deck)->create())
                ->ofType(CardType::Cloze)->create();
        }

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Due now')
            ->assertSee('Start review');
    }

    public function test_the_dashboard_says_so_when_there_is_nothing_to_do(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Nothing here yet');
    }

    /**
     * The whole loop, end to end: add a sentence, review it through the
     * learning steps, and watch it graduate.
     */
    /**
     * The whole journey, end to end: type a sentence, watch its picture and
     * clip arrive, approve them, and study the card to graduation.
     *
     * The approval step is the part worth guarding. Before it existed, saving
     * a sentence produced a card immediately — with no picture and no audio —
     * and this test passed just the same, which is precisely how the app came
     * to be full of cards missing the two things it is built around.
     */
    public function test_a_sentence_becomes_a_card_only_after_its_media_is_approved(): void
    {
        $user = User::factory()->create();

        // The services, stood in for. The point here is the journey, not the
        // clients — MediaProviderTest exercises those against recorded replies.
        $this->app->instance(ImageProvider::class, new FakeImageProvider);
        $this->app->instance(SpeechSynthesizer::class, new FakeSpeechSynthesizer);
        Storage::fake(config('flashai.media.disk'));
        Http::fake(fn () => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']));
        config(['services.pixabay.key' => 'test-key', 'services.gemini.key' => 'test-key']);

        $this->actingAs($user)->post(route('notes.store'), [
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'borrowed',
            'meaning' => 'took something to use and give back later',
        ]);

        $note = Note::query()->sole();

        // Saved, and not yet a card.
        $this->assertSame(0, $note->cards()->count());

        // QUEUE_CONNECTION is sync here, so the media jobs have already run.
        $note->refresh();
        $this->assertTrue($note->isComplete());
        $this->assertNotNull($note->image_path);
        $this->assertNotNull($note->audio_sentence_path);

        // Nothing to study until somebody has looked at it and pressed the
        // button, which is the entire point of the compose screen.
        $this->assertNull(app(ReviewQueue::class)->nextCard($user));

        $this->actingAs($user)->get(route('notes.compose', $note))->assertOk();
        $this->actingAs($user)->post(route('notes.complete', $note))->assertRedirect();

        $this->assertSame(2, $note->fresh()->cards()->count());

        // And from here the scheduler behaves as it always did.
        $card = Card::query()->where('type', CardType::Cloze)->sole();

        $this->actingAs($user)->get(route('review.show'))->assertOk();
        $this->actingAs($user)->post(route('review.grade', $card), ['grade' => ReviewGrade::Good->value]);
        $this->assertSame(CardQueue::Learning, $card->fresh()->queue);

        // Ten minutes later the card is back.
        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertTrue(app(ReviewQueue::class)->nextCard($user)->is($card));

        $this->actingAs($user)->post(route('review.grade', $card), ['grade' => ReviewGrade::Good->value]);

        $card->refresh();
        $this->assertSame(CardQueue::Review, $card->queue);
        $this->assertSame(1, $card->interval_days);
    }
}
