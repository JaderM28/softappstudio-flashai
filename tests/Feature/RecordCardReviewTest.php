<?php

namespace Tests\Feature;

use App\Actions\RecordCardReview;
use App\Enums\ReviewQuality;
use App\Models\Card;
use App\Models\CardProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecordCardReviewTest extends TestCase
{
    use RefreshDatabase;

    private RecordCardReview $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(RecordCardReview::class);

        Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_grading_a_card_advances_its_stored_progress(): void
    {
        $card = Card::factory()->create();
        CardProgress::factory()->create(['card_id' => $card->id]);

        $progress = $this->action->handle($card, ReviewQuality::Easy);

        $this->assertSame(1, $progress->repetitions);
        $this->assertSame(1, $progress->interval_days);
        $this->assertSame(2.6, $progress->easiness);
        $this->assertSame(ReviewQuality::Easy->value, $progress->last_quality);
        $this->assertSame('2026-03-10 10:00:00', $progress->last_reviewed_at->toDateTimeString());
        $this->assertSame('2026-03-11 04:00:00', $progress->next_review_at->toDateTimeString());
    }

    public function test_grading_a_card_appends_to_the_review_log(): void
    {
        $card = Card::factory()->create();
        CardProgress::factory()->learning()->create(['card_id' => $card->id]);

        $this->action->handle($card, ReviewQuality::Hard);

        $this->assertDatabaseHas('card_reviews', [
            'card_id' => $card->id,
            'user_id' => $card->user_id,
            'quality' => ReviewQuality::Hard->value,
            'repetitions_before' => 2,
            'repetitions_after' => 3,
            'interval_days_before' => 6,
        ]);
    }

    public function test_the_review_log_records_the_easiness_on_both_sides(): void
    {
        $card = Card::factory()->create();
        CardProgress::factory()->create(['card_id' => $card->id]);

        $this->action->handle($card, ReviewQuality::Wrong);

        $review = $card->reviews()->sole();

        $this->assertSame(2.5, $review->easiness_before);
        $this->assertSame(2.18, $review->easiness_after);
    }

    public function test_a_failed_grade_resets_progress_but_keeps_the_history(): void
    {
        $card = Card::factory()->create();
        CardProgress::factory()->mastered()->create(['card_id' => $card->id]);

        $progress = $this->action->handle($card, ReviewQuality::Wrong);

        $this->assertSame(0, $progress->repetitions);
        $this->assertSame(1, $progress->interval_days);
        $this->assertDatabaseHas('card_reviews', [
            'card_id' => $card->id,
            'repetitions_before' => 5,
            'repetitions_after' => 0,
            'interval_days_before' => 30,
            'interval_days_after' => 1,
        ]);
    }

    public function test_a_card_without_progress_gets_a_row_on_its_first_review(): void
    {
        $card = Card::factory()->create();

        $this->assertDatabaseCount('card_progress', 0);

        $progress = $this->action->handle($card, ReviewQuality::Easy);

        $this->assertDatabaseCount('card_progress', 1);
        $this->assertSame($card->user_id, $progress->user_id);
        $this->assertSame(1, $progress->repetitions);
    }

    public function test_each_review_adds_exactly_one_log_row(): void
    {
        $card = Card::factory()->create();
        CardProgress::factory()->create(['card_id' => $card->id]);

        $this->action->handle($card, ReviewQuality::Easy);
        $this->action->handle($card, ReviewQuality::Hard);
        $this->action->handle($card, ReviewQuality::Wrong);

        $this->assertSame(3, $card->reviews()->count());
        $this->assertSame(1, CardProgress::query()->where('card_id', $card->id)->count());
    }

    public function test_a_reviewed_card_leaves_the_due_queue(): void
    {
        $card = Card::factory()->create();
        CardProgress::factory()->create(['card_id' => $card->id, 'next_review_at' => now()]);

        $this->assertSame(1, CardProgress::query()->due()->count());

        $this->action->handle($card, ReviewQuality::Easy);

        $this->assertSame(0, CardProgress::query()->due()->count());
    }

    /**
     * A failed card comes back tomorrow, not in the same session — otherwise
     * the session never ends for a card the user cannot recall.
     */
    public function test_a_failed_card_does_not_reappear_in_the_same_session(): void
    {
        $card = Card::factory()->create();
        CardProgress::factory()->create(['card_id' => $card->id, 'next_review_at' => now()]);

        $this->action->handle($card, ReviewQuality::Wrong);

        $this->assertSame(0, CardProgress::query()->due()->count());
        $this->assertTrue($card->progress->fresh()->next_review_at->isTomorrow());
    }
}
