<?php

namespace Tests\Feature;

use App\Enums\CardQueue;
use App\Enums\ReviewGrade;
use App\Models\Card;
use App\Models\Deck;
use App\Models\Note;
use App\Models\User;
use App\Services\ReviewQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The bug that started all of this: press "Hard" on a card you are learning and
 * the session declares itself over.
 *
 * Nothing was broken underneath. The scheduler parked the card ten minutes out,
 * exactly as Anki would, and the session had no way to say "not yet" — so it
 * said "nothing left", which is the one thing it did not mean.
 */
class LearnAheadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'flashai.scheduler.learning_steps' => [1, 10],
            'flashai.scheduler.fuzz_percent' => 0,
            'flashai.session.learn_ahead_minutes' => 20,
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

    /**
     * The whole complaint, as a test.
     */
    public function test_grading_hard_does_not_end_the_session(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user, ['queue' => CardQueue::New, 'next_review_at' => now()]);

        $this->actingAs($user)
            ->post(route('review.grade', $card), ['grade' => ReviewGrade::Hard->value])
            ->assertRedirect(route('review.show'));

        // Parked a minute out by the scheduler, and still the next card served.
        $this->assertSame(CardQueue::Learning, $card->fresh()->queue);
        $this->assertTrue(app(ReviewQueue::class)->nextCard($user)->is($card));

        $this->actingAs($user)
            ->get(route('review.show'))
            ->assertOk()
            ->assertDontSee('Nothing left due');
    }

    public function test_a_learning_card_due_within_the_window_is_served_early(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user, [
            'queue' => CardQueue::Learning,
            'learning_step' => 1,
            'next_review_at' => now()->addMinutes(9),
        ]);

        $this->assertTrue(app(ReviewQueue::class)->nextCard($user)->is($card));
        $this->assertSame(1, app(ReviewQueue::class)->counts($user)[CardQueue::Learning->value]);
    }

    public function test_a_learning_card_beyond_the_window_still_waits(): void
    {
        $user = User::factory()->create();
        $this->cardFor($user, [
            'queue' => CardQueue::Learning,
            'learning_step' => 1,
            'next_review_at' => now()->addMinutes(45),
        ]);

        $this->assertNull(app(ReviewQueue::class)->nextCard($user));
    }

    /**
     * Reviews are the algorithm. Serving one early would make its interval —
     * the number the whole of SM-2 exists to compute — mean nothing.
     */
    public function test_a_review_card_is_never_brought_forward(): void
    {
        $user = User::factory()->create();
        $this->cardFor($user, [
            'queue' => CardQueue::Review,
            'interval_days' => 3,
            'next_review_at' => now()->addMinutes(5),
        ]);

        $this->assertNull(app(ReviewQueue::class)->nextCard($user));
    }

    public function test_the_window_can_be_switched_off(): void
    {
        config(['flashai.session.learn_ahead_minutes' => 0]);

        $user = User::factory()->create();
        $this->cardFor($user, [
            'queue' => CardQueue::Learning,
            'next_review_at' => now()->addMinutes(5),
        ]);

        $this->assertNull(app(ReviewQueue::class)->nextCard($user));
    }

    /**
     * The counter has to agree with the session. A header reading "Learning 0"
     * above a learning card is the same confusion in a smaller font.
     */
    public function test_the_counter_agrees_with_what_the_session_will_serve(): void
    {
        $user = User::factory()->create();
        $this->cardFor($user, [
            'queue' => CardQueue::Learning,
            'next_review_at' => now()->addMinutes(9),
        ]);

        $counts = app(ReviewQueue::class)->counts($user);

        $this->assertSame(1, $counts[CardQueue::Learning->value]);
        $this->assertSame(1, app(ReviewQueue::class)->dueCount($user));
    }
}
