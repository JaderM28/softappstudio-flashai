<?php

namespace Tests\Feature;

use App\Actions\GradeCard;
use App\Enums\CardQueue;
use App\Enums\ReviewGrade;
use App\Models\Card;
use App\Models\CardReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GradeCardTest extends TestCase
{
    use RefreshDatabase;

    private GradeCard $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(GradeCard::class);

        config([
            'flashai.scheduler.learning_steps' => [1, 10],
            'flashai.scheduler.relearning_steps' => [10],
            'flashai.scheduler.fuzz_percent' => 0,
            'flashai.scheduler.leech_threshold' => 8,
            'flashai.review.day_starts_at_hour' => 4,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_grading_a_new_card_moves_it_into_the_learning_queue(): void
    {
        $card = Card::factory()->create();

        $graded = $this->action->handle($card, ReviewGrade::Good);

        $this->assertSame(CardQueue::Learning, $graded->queue);
        $this->assertSame(1, $graded->learning_step);
        $this->assertSame(ReviewGrade::Good->value, $graded->last_grade);
        $this->assertSame('2026-03-10 10:00:00', $graded->last_reviewed_at->toDateTimeString());
        $this->assertSame('2026-03-10 10:10:00', $graded->next_review_at->toDateTimeString());
    }

    public function test_grading_a_card_appends_one_review_with_both_sides(): void
    {
        $card = Card::factory()->review(intervalDays: 10)->create();

        $this->action->handle($card, ReviewGrade::Hard, durationMs: 4200);

        $review = CardReview::query()->sole();

        $this->assertSame($card->id, $review->card_id);
        $this->assertSame($card->user_id, $review->user_id);
        $this->assertSame(ReviewGrade::Hard, $review->grade);
        $this->assertSame(CardQueue::Review, $review->queue_before);
        $this->assertSame(CardQueue::Review, $review->queue_after);
        $this->assertSame(10, $review->interval_days_before);
        $this->assertSame(12, $review->interval_days_after);
        $this->assertSame(2.5, $review->easiness_before);
        $this->assertSame(2.35, $review->easiness_after);
        $this->assertSame(4200, $review->duration_ms);
    }

    public function test_the_log_records_the_queue_transition_on_a_lapse(): void
    {
        $card = Card::factory()->review(intervalDays: 40)->create();

        $this->action->handle($card, ReviewGrade::Again);

        $review = CardReview::query()->sole();

        $this->assertSame(CardQueue::Review, $review->queue_before);
        $this->assertSame(CardQueue::Relearning, $review->queue_after);
        $this->assertNull($review->learning_step_before);
        $this->assertSame(0, $review->learning_step_after);
        $this->assertSame(1, $review->lapses_after);
    }

    public function test_a_leech_is_suspended_and_leaves_the_queue(): void
    {
        $card = Card::factory()->review(intervalDays: 10)->create(['lapses' => 7]);

        $graded = $this->action->handle($card, ReviewGrade::Again);

        $this->assertSame(CardQueue::Suspended, $graded->queue);
        $this->assertSame(8, $graded->lapses);
        $this->assertSame(0, Card::query()->due()->count());
    }

    public function test_each_grade_adds_exactly_one_review(): void
    {
        $card = Card::factory()->create();

        $card = $this->action->handle($card, ReviewGrade::Good);
        $card = $this->action->handle($card, ReviewGrade::Good);
        $this->action->handle($card, ReviewGrade::Good);

        $this->assertSame(3, CardReview::query()->count());
        $this->assertSame(1, Card::query()->count());
    }

    public function test_a_graded_card_leaves_the_due_queue(): void
    {
        $card = Card::factory()->dueAt(now()->subMinute())->create();

        $this->assertSame(1, Card::query()->due()->count());

        $this->action->handle($card, ReviewGrade::Good);

        $this->assertSame(0, Card::query()->due()->count());
    }

    /**
     * The point of the learning steps: a new card comes back inside the same
     * session rather than disappearing until tomorrow.
     */
    public function test_a_new_card_returns_within_the_session(): void
    {
        $card = Card::factory()->create();

        $graded = $this->action->handle($card, ReviewGrade::Good);

        $this->assertSame(0, Card::query()->due()->count());
        $this->assertSame(1, Card::query()->due(now()->addMinutes(11))->count());
        $this->assertTrue($graded->next_review_at->lessThan(now()->addHour()));
    }

    public function test_grading_works_on_a_stale_model_instance(): void
    {
        $card = Card::factory()->create();

        // Something else advanced the card since this instance was loaded.
        Card::query()->whereKey($card->id)->update([
            'queue' => CardQueue::Review,
            'interval_days' => 10,
            'repetitions' => 3,
        ]);

        $graded = $this->action->handle($card, ReviewGrade::Good);

        // The action reloads under a lock, so it scheduled from the row's real
        // state rather than the stale copy it was handed.
        $this->assertSame(25, $graded->interval_days);
        $this->assertSame(10, CardReview::query()->sole()->interval_days_before);
    }

    public function test_a_full_lifecycle_is_persisted_end_to_end(): void
    {
        $card = Card::factory()->create();

        $card = $this->action->handle($card, ReviewGrade::Good);
        $this->assertSame(CardQueue::Learning, $card->queue);

        $card = $this->action->handle($card, ReviewGrade::Good);
        $this->assertSame(CardQueue::Review, $card->queue);
        $this->assertSame(1, $card->interval_days);

        $card = $this->action->handle($card, ReviewGrade::Good);
        $this->assertSame(3, $card->interval_days);

        $card = $this->action->handle($card, ReviewGrade::Again);
        $this->assertSame(CardQueue::Relearning, $card->queue);
        $this->assertSame(1, $card->lapses);

        $card = $this->action->handle($card, ReviewGrade::Good);
        $this->assertSame(CardQueue::Review, $card->queue);

        $this->assertSame(5, CardReview::query()->count());
        $this->assertSame(5, $card->reviews()->count());
    }
}
