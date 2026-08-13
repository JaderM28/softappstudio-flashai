<?php

namespace Tests\Unit;

use App\Services\SpacedRepetitionService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class SpacedRepetitionServiceTest extends TestCase
{
    private SpacedRepetitionService $scheduler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scheduler = new SpacedRepetitionService;

        // Mid-morning, so the 4am rollover never lands on the same day and the
        // interval assertions read as plain day counts.
        Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_first_successful_review_schedules_one_day_out(): void
    {
        $state = $this->scheduler->next(quality: 5, repetitions: 0, easiness: 2.5, intervalDays: 0);

        $this->assertSame(1, $state->intervalDays);
        $this->assertSame(1, $state->repetitions);
    }

    public function test_the_second_successful_review_schedules_six_days_out(): void
    {
        $state = $this->scheduler->next(quality: 5, repetitions: 1, easiness: 2.5, intervalDays: 1);

        $this->assertSame(6, $state->intervalDays);
        $this->assertSame(2, $state->repetitions);
    }

    public function test_later_reviews_multiply_the_interval_by_easiness(): void
    {
        $state = $this->scheduler->next(quality: 5, repetitions: 2, easiness: 2.5, intervalDays: 6);

        // 6 * 2.5 = 15
        $this->assertSame(15, $state->intervalDays);
        $this->assertSame(3, $state->repetitions);
    }

    public function test_the_interval_uses_the_easiness_from_before_this_review(): void
    {
        $state = $this->scheduler->next(quality: 5, repetitions: 2, easiness: 2.5, intervalDays: 10);

        // 10 * 2.5 = 25, not 10 * 2.6 (the post-review easiness).
        $this->assertSame(25, $state->intervalDays);
        $this->assertSame(2.6, $state->easiness);
    }

    public function test_a_failed_review_resets_the_ladder(): void
    {
        $state = $this->scheduler->next(quality: 2, repetitions: 7, easiness: 2.5, intervalDays: 120);

        $this->assertSame(0, $state->repetitions);
        $this->assertSame(1, $state->intervalDays);
    }

    public function test_a_failed_review_keeps_the_easiness_penalty(): void
    {
        $state = $this->scheduler->next(quality: 2, repetitions: 7, easiness: 2.5, intervalDays: 120);

        // 2.5 + (0.1 - 3 * (0.08 + 3 * 0.02)) = 2.5 - 0.32
        $this->assertSame(2.18, $state->easiness);
    }

    /**
     * The exact SM-2 curve: EF + (0.1 - (5-q) * (0.08 + (5-q) * 0.02)).
     *
     * These numbers are the reason the plan's linearised variant was not used —
     * on a Hard grade it would give 2.44 where the real formula gives 2.36.
     */
    public function test_easiness_follows_the_sm2_curve(): void
    {
        $cases = [
            5 => 2.60,
            4 => 2.50,
            3 => 2.36,
            2 => 2.18,
            1 => 1.96,
            0 => 1.70,
        ];

        foreach ($cases as $quality => $expected) {
            $state = $this->scheduler->next(
                quality: $quality,
                repetitions: 3,
                easiness: 2.5,
                intervalDays: 10,
            );

            $this->assertSame($expected, $state->easiness, "quality {$quality}");
        }
    }

    public function test_easiness_never_falls_below_the_floor(): void
    {
        $state = $this->scheduler->next(quality: 0, repetitions: 3, easiness: 1.3, intervalDays: 10);

        $this->assertSame(SpacedRepetitionService::MINIMUM_EASINESS, $state->easiness);
    }

    public function test_repeated_failures_cannot_drive_easiness_below_the_floor(): void
    {
        $easiness = 2.5;

        for ($i = 0; $i < 20; $i++) {
            $easiness = $this->scheduler
                ->next(quality: 0, repetitions: 0, easiness: $easiness, intervalDays: 1)
                ->easiness;
        }

        $this->assertSame(SpacedRepetitionService::MINIMUM_EASINESS, $easiness);
    }

    public function test_the_interval_never_collapses_to_zero(): void
    {
        // A card at the easiness floor: 1 * 1.3 rounds to 1, not 0.
        $state = $this->scheduler->next(quality: 3, repetitions: 5, easiness: 1.3, intervalDays: 1);

        $this->assertGreaterThanOrEqual(1, $state->intervalDays);
    }

    public function test_quality_outside_the_sm2_scale_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->scheduler->next(quality: 6, repetitions: 0, easiness: 2.5, intervalDays: 0);
    }

    public function test_negative_quality_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->scheduler->next(quality: -1, repetitions: 0, easiness: 2.5, intervalDays: 0);
    }

    public function test_a_new_card_starts_due_immediately(): void
    {
        $state = $this->scheduler->initial();

        $this->assertSame(0, $state->repetitions);
        $this->assertSame(0, $state->intervalDays);
        $this->assertSame(2.5, $state->easiness);
        $this->assertTrue($state->nextReviewAt->lessThanOrEqualTo(now()));
    }

    public function test_cards_fall_due_at_the_start_of_the_review_day(): void
    {
        $state = $this->scheduler->next(quality: 5, repetitions: 0, easiness: 2.5, intervalDays: 0);

        $this->assertSame('2026-03-11 04:00:00', $state->nextReviewAt->toDateTimeString());
    }

    /**
     * The bug day-boundary scheduling exists to prevent: without it, a card
     * graded at 21:00 comes due at 21:00 the next day and is invisible to the
     * morning session, and the drift compounds on every review.
     */
    public function test_an_evening_review_is_still_due_in_the_morning_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 21:30:00'));

        $state = $this->scheduler->next(quality: 5, repetitions: 0, easiness: 2.5, intervalDays: 0);

        $this->assertSame('2026-03-11 04:00:00', $state->nextReviewAt->toDateTimeString());
        $this->assertTrue($state->nextReviewAt->lessThan(Carbon::parse('2026-03-11 08:00:00')));
    }

    /**
     * A review in the small hours still belongs to the previous review day, so
     * the card must not come due a couple of hours later the same morning.
     */
    public function test_a_review_before_the_rollover_hour_does_not_come_due_the_same_morning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 02:00:00'));

        $state = $this->scheduler->next(quality: 5, repetitions: 0, easiness: 2.5, intervalDays: 0);

        $this->assertSame('2026-03-11 04:00:00', $state->nextReviewAt->toDateTimeString());
    }

    public function test_the_rollover_hour_is_configurable(): void
    {
        config(['flashai.review.day_starts_at_hour' => 6]);

        $state = $this->scheduler->next(quality: 5, repetitions: 0, easiness: 2.5, intervalDays: 0);

        $this->assertSame('2026-03-11 06:00:00', $state->nextReviewAt->toDateTimeString());
    }

    /**
     * A full learning run, to check the pieces compose into a sane curve rather
     * than only behaving correctly one step at a time.
     *
     * Each perfect grade also raises easiness by 0.1, so the multiplier grows
     * as the run goes on:
     *
     *   1, 6, round(6*2.7)=16, round(16*2.8)=45, round(45*2.9)=131, round(131*3.0)=393
     */
    public function test_a_run_of_easy_reviews_produces_a_growing_schedule(): void
    {
        $repetitions = 0;
        $easiness = 2.5;
        $interval = 0;
        $intervals = [];

        for ($i = 0; $i < 6; $i++) {
            $state = $this->scheduler->next(
                quality: 5,
                repetitions: $repetitions,
                easiness: $easiness,
                intervalDays: $interval,
            );

            $repetitions = $state->repetitions;
            $easiness = $state->easiness;
            $interval = $state->intervalDays;
            $intervals[] = $interval;
        }

        $this->assertSame([1, 6, 16, 45, 131, 393], $intervals);
        $this->assertSame(3.1, $easiness);
    }

    /**
     * The mirror of the run above: grading Hard every time must tighten the
     * schedule, not stretch it. This is the behaviour the plan's linearised
     * easiness formula would have got wrong.
     */
    public function test_a_run_of_hard_reviews_keeps_the_schedule_tight(): void
    {
        $repetitions = 0;
        $easiness = 2.5;
        $interval = 0;
        $intervals = [];

        for ($i = 0; $i < 6; $i++) {
            $state = $this->scheduler->next(
                quality: 3,
                repetitions: $repetitions,
                easiness: $easiness,
                intervalDays: $interval,
            );

            $repetitions = $state->repetitions;
            $easiness = $state->easiness;
            $interval = $state->intervalDays;
            $intervals[] = $interval;
        }

        // Easiness falls 0.14 per Hard grade and drags the multiplier down with
        // it: round(6*2.22)=13, round(13*2.08)=27, round(27*1.94)=52, round(52*1.8)=94.
        $this->assertSame([1, 6, 13, 27, 52, 94], $intervals);
        $this->assertSame(1.66, $easiness);

        // The point of the exercise: six Hard grades leave the card on a
        // three-month horizon, where six Easy grades reach over a year.
        $this->assertLessThan(0.25, end($intervals) / 393);
    }
}
