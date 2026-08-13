<?php

namespace Tests\Unit;

use App\Enums\CardQueue;
use App\Enums\ReviewGrade;
use App\Services\Scheduler;
use App\Support\SchedulingState;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class SchedulerTest extends TestCase
{
    private Scheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scheduler = new Scheduler;

        config([
            'flashai.scheduler.learning_steps' => [1, 10],
            'flashai.scheduler.relearning_steps' => [10],
            'flashai.scheduler.graduating_interval' => 1,
            'flashai.scheduler.easy_interval' => 4,
            'flashai.scheduler.hard_multiplier' => 1.2,
            'flashai.scheduler.easy_bonus' => 1.3,
            'flashai.scheduler.starting_easiness' => 2.5,
            'flashai.scheduler.minimum_easiness' => 1.3,
            'flashai.scheduler.maximum_easiness' => 3.5,
            'flashai.scheduler.lapse_multiplier' => 0.0,
            'flashai.scheduler.minimum_lapse_interval' => 1,
            'flashai.scheduler.maximum_interval' => 365,
            // Off by default so interval assertions are exact; there is a
            // dedicated test for the jitter itself.
            'flashai.scheduler.fuzz_percent' => 0,
            'flashai.scheduler.leech_threshold' => 8,
            'flashai.review.day_starts_at_hour' => 4,
        ]);

        // Mid-morning, so the 4am rollover never lands on the same day and the
        // interval assertions read as plain day counts.
        Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function newCard(): SchedulingState
    {
        return SchedulingState::forNewCard();
    }

    private function reviewCard(int $intervalDays = 10, float $easiness = 2.5, int $lapses = 0): SchedulingState
    {
        return new SchedulingState(
            queue: CardQueue::Review,
            learningStep: null,
            repetitions: 4,
            easiness: $easiness,
            intervalDays: $intervalDays,
            lapses: $lapses,
            nextReviewAt: now(),
        );
    }

    // ------------------------------------------------------- learning steps

    public function test_a_new_card_answered_good_moves_to_the_first_learning_step(): void
    {
        $state = $this->scheduler->grade($this->newCard(), ReviewGrade::Good);

        $this->assertSame(CardQueue::Learning, $state->queue);
        $this->assertSame(1, $state->learningStep);
        $this->assertSame('2026-03-10 10:10:00', $state->nextReviewAt->toDateTimeString());
    }

    public function test_a_new_card_answered_again_stays_on_the_first_step(): void
    {
        $state = $this->scheduler->grade($this->newCard(), ReviewGrade::Again);

        $this->assertSame(CardQueue::Learning, $state->queue);
        $this->assertSame(0, $state->learningStep);
        $this->assertSame('2026-03-10 10:01:00', $state->nextReviewAt->toDateTimeString());
    }

    public function test_hard_repeats_the_current_learning_step(): void
    {
        $onSecondStep = new SchedulingState(
            queue: CardQueue::Learning,
            learningStep: 1,
            repetitions: 0,
            easiness: 2.5,
            intervalDays: 0,
            lapses: 0,
            nextReviewAt: now(),
        );

        $state = $this->scheduler->grade($onSecondStep, ReviewGrade::Hard);

        $this->assertSame(1, $state->learningStep);
        $this->assertSame('2026-03-10 10:10:00', $state->nextReviewAt->toDateTimeString());
    }

    public function test_good_on_the_last_learning_step_graduates_the_card(): void
    {
        $onLastStep = new SchedulingState(
            queue: CardQueue::Learning,
            learningStep: 1,
            repetitions: 0,
            easiness: 2.5,
            intervalDays: 0,
            lapses: 0,
            nextReviewAt: now(),
        );

        $state = $this->scheduler->grade($onLastStep, ReviewGrade::Good);

        $this->assertSame(CardQueue::Review, $state->queue);
        $this->assertNull($state->learningStep);
        $this->assertSame(1, $state->intervalDays);
        $this->assertSame('2026-03-11 04:00:00', $state->nextReviewAt->toDateTimeString());
    }

    public function test_easy_skips_the_learning_steps_entirely(): void
    {
        $state = $this->scheduler->grade($this->newCard(), ReviewGrade::Easy);

        $this->assertSame(CardQueue::Review, $state->queue);
        $this->assertNull($state->learningStep);
        $this->assertSame(4, $state->intervalDays);
        $this->assertSame('2026-03-14 04:00:00', $state->nextReviewAt->toDateTimeString());
    }

    /**
     * The whole point of the steps: a new card must not vanish for a day after
     * one lucky answer.
     */
    public function test_a_new_card_is_seen_again_within_the_same_session(): void
    {
        $state = $this->scheduler->grade($this->newCard(), ReviewGrade::Good);

        $this->assertTrue($state->nextReviewAt->lessThan(now()->addHour()));
    }

    public function test_easiness_does_not_move_during_the_learning_steps(): void
    {
        foreach (ReviewGrade::cases() as $grade) {
            $state = $this->scheduler->grade($this->newCard(), $grade);

            $this->assertSame(2.5, $state->easiness, $grade->label());
        }
    }

    public function test_a_step_index_beyond_the_configured_steps_is_clamped(): void
    {
        config(['flashai.scheduler.learning_steps' => [1, 10]]);

        $strayed = new SchedulingState(
            queue: CardQueue::Learning,
            learningStep: 7,
            repetitions: 0,
            easiness: 2.5,
            intervalDays: 0,
            lapses: 0,
            nextReviewAt: now(),
        );

        $state = $this->scheduler->grade($strayed, ReviewGrade::Hard);

        $this->assertSame(1, $state->learningStep);
    }

    // -------------------------------------------------------------- reviews

    public function test_good_multiplies_the_interval_by_easiness(): void
    {
        $state = $this->scheduler->grade($this->reviewCard(intervalDays: 10), ReviewGrade::Good);

        // 10 * 2.5, and Good leaves easiness alone.
        $this->assertSame(25, $state->intervalDays);
        $this->assertSame(2.5, $state->easiness);
        $this->assertSame(5, $state->repetitions);
    }

    public function test_hard_grows_the_interval_slowly_and_lowers_easiness(): void
    {
        $state = $this->scheduler->grade($this->reviewCard(intervalDays: 10), ReviewGrade::Hard);

        // 10 * 1.2, not 10 * easiness.
        $this->assertSame(12, $state->intervalDays);
        $this->assertSame(2.35, $state->easiness);
    }

    public function test_easy_adds_a_bonus_on_top_of_easiness(): void
    {
        $state = $this->scheduler->grade($this->reviewCard(intervalDays: 10), ReviewGrade::Easy);

        // easiness rises to 2.65 first, then 10 * 2.65 * 1.3 = 34.45 -> 34.
        $this->assertSame(2.65, $state->easiness);
        $this->assertSame(34, $state->intervalDays);
    }

    /**
     * The distinction the fourth button exists for: on the same card, Hard and
     * Good must not produce the same schedule.
     */
    public function test_hard_and_good_pull_the_schedule_apart(): void
    {
        $card = $this->reviewCard(intervalDays: 30);

        $hard = $this->scheduler->grade($card, ReviewGrade::Hard);
        $good = $this->scheduler->grade($card, ReviewGrade::Good);

        $this->assertSame(36, $hard->intervalDays);
        $this->assertSame(75, $good->intervalDays);
        $this->assertLessThan($good->easiness, $hard->easiness);
    }

    public function test_intervals_are_capped(): void
    {
        config(['flashai.scheduler.maximum_interval' => 365]);

        $state = $this->scheduler->grade($this->reviewCard(intervalDays: 300), ReviewGrade::Easy);

        $this->assertSame(365, $state->intervalDays);
    }

    public function test_an_interval_never_collapses_to_zero(): void
    {
        // A one-day card at the easiness floor: 1 * 1.3 rounds to 1, not 0.
        $state = $this->scheduler->grade(
            $this->reviewCard(intervalDays: 1, easiness: 1.3),
            ReviewGrade::Good,
        );

        $this->assertGreaterThanOrEqual(1, $state->intervalDays);
    }

    // -------------------------------------------------------------- easiness

    public function test_each_grade_moves_easiness_by_its_own_amount(): void
    {
        $expected = [
            ReviewGrade::Again->name => 2.30,
            ReviewGrade::Hard->name => 2.35,
            ReviewGrade::Good->name => 2.50,
            ReviewGrade::Easy->name => 2.65,
        ];

        foreach (ReviewGrade::cases() as $grade) {
            $state = $this->scheduler->grade($this->reviewCard(easiness: 2.5), $grade);

            $this->assertSame($expected[$grade->name], $state->easiness, $grade->label());
        }
    }

    public function test_easiness_never_falls_below_the_floor(): void
    {
        $easiness = 2.5;

        for ($i = 0; $i < 20; $i++) {
            $easiness = $this->scheduler
                ->grade($this->reviewCard(intervalDays: 5, easiness: $easiness), ReviewGrade::Hard)
                ->easiness;
        }

        $this->assertSame(1.3, $easiness);
    }

    public function test_easiness_never_rises_above_the_ceiling(): void
    {
        $easiness = 2.5;

        for ($i = 0; $i < 20; $i++) {
            $easiness = $this->scheduler
                ->grade($this->reviewCard(intervalDays: 5, easiness: $easiness), ReviewGrade::Easy)
                ->easiness;
        }

        $this->assertSame(3.5, $easiness);
    }

    // --------------------------------------------------------------- lapses

    public function test_forgetting_a_graduated_card_sends_it_to_relearning(): void
    {
        $state = $this->scheduler->grade($this->reviewCard(intervalDays: 60), ReviewGrade::Again);

        $this->assertSame(CardQueue::Relearning, $state->queue);
        $this->assertSame(0, $state->learningStep);
        $this->assertSame(1, $state->lapses);
        $this->assertSame(0, $state->repetitions);
        $this->assertSame(1, $state->intervalDays);
        $this->assertSame('2026-03-10 10:10:00', $state->nextReviewAt->toDateTimeString());
    }

    public function test_a_lapse_keeps_the_easiness_penalty(): void
    {
        $state = $this->scheduler->grade($this->reviewCard(easiness: 2.5), ReviewGrade::Again);

        $this->assertSame(2.30, $state->easiness);
    }

    public function test_the_lapse_multiplier_can_preserve_part_of_the_interval(): void
    {
        config(['flashai.scheduler.lapse_multiplier' => 0.5]);

        $state = $this->scheduler->grade($this->reviewCard(intervalDays: 60), ReviewGrade::Again);

        $this->assertSame(30, $state->intervalDays);
    }

    public function test_good_on_the_last_relearning_step_restores_the_lapse_interval(): void
    {
        config(['flashai.scheduler.lapse_multiplier' => 0.5]);

        $lapsed = $this->scheduler->grade($this->reviewCard(intervalDays: 60), ReviewGrade::Again);
        $recovered = $this->scheduler->grade($lapsed, ReviewGrade::Good);

        $this->assertSame(CardQueue::Review, $recovered->queue);
        $this->assertSame(30, $recovered->intervalDays);
    }

    public function test_easiness_does_not_fall_again_while_relearning(): void
    {
        $lapsed = $this->scheduler->grade($this->reviewCard(easiness: 2.5), ReviewGrade::Again);
        $stillRelearning = $this->scheduler->grade($lapsed, ReviewGrade::Again);

        // The penalty is charged once, at the lapse.
        $this->assertSame(2.30, $stillRelearning->easiness);
    }

    // --------------------------------------------------------------- leeches

    public function test_a_card_forgotten_too_often_is_suspended_as_a_leech(): void
    {
        config(['flashai.scheduler.leech_threshold' => 8]);

        $state = $this->scheduler->grade(
            $this->reviewCard(intervalDays: 10, lapses: 7),
            ReviewGrade::Again,
        );

        $this->assertSame(CardQueue::Suspended, $state->queue);
        $this->assertSame(8, $state->lapses);
        $this->assertTrue($state->isLeech());
    }

    public function test_a_card_below_the_leech_threshold_keeps_going(): void
    {
        config(['flashai.scheduler.leech_threshold' => 8]);

        $state = $this->scheduler->grade(
            $this->reviewCard(intervalDays: 10, lapses: 6),
            ReviewGrade::Again,
        );

        $this->assertSame(CardQueue::Relearning, $state->queue);
        $this->assertFalse($state->isLeech());
    }

    public function test_a_suspended_card_cannot_be_graded(): void
    {
        $suspended = new SchedulingState(
            queue: CardQueue::Suspended,
            learningStep: null,
            repetitions: 2,
            easiness: 2.5,
            intervalDays: 10,
            lapses: 8,
            nextReviewAt: now(),
        );

        $this->expectException(InvalidArgumentException::class);

        $this->scheduler->grade($suspended, ReviewGrade::Good);
    }

    // ------------------------------------------------------------------ fuzz

    public function test_fuzz_spreads_intervals_without_moving_them_far(): void
    {
        config(['flashai.scheduler.fuzz_percent' => 5]);

        $results = [];

        for ($i = 0; $i < 60; $i++) {
            $results[] = $this->scheduler
                ->grade($this->reviewCard(intervalDays: 100), ReviewGrade::Good)
                ->intervalDays;
        }

        // 100 * 2.5 = 250, jittered by up to 5%.
        $this->assertGreaterThan(1, count(array_unique($results)), 'fuzz produced no spread');

        foreach ($results as $interval) {
            $this->assertGreaterThanOrEqual(237, $interval);
            $this->assertLessThanOrEqual(263, $interval);
        }
    }

    public function test_short_intervals_are_left_exact(): void
    {
        config(['flashai.scheduler.fuzz_percent' => 5]);

        for ($i = 0; $i < 20; $i++) {
            $state = $this->scheduler->grade($this->newCard(), ReviewGrade::Good);
            $graduated = $this->scheduler->grade($state, ReviewGrade::Good);

            $this->assertSame(1, $graduated->intervalDays);
        }
    }

    // ------------------------------------------------------------ due dates

    public function test_day_scale_cards_fall_due_at_the_start_of_a_review_day(): void
    {
        $state = $this->scheduler->grade($this->reviewCard(intervalDays: 10), ReviewGrade::Good);

        $this->assertSame('04:00:00', $state->nextReviewAt->toTimeString());
    }

    public function test_an_evening_review_is_still_due_in_the_morning_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 21:30:00'));

        $state = $this->scheduler->grade($this->newCard(), ReviewGrade::Easy);

        $this->assertSame('2026-03-14 04:00:00', $state->nextReviewAt->toDateTimeString());
    }

    public function test_a_review_before_the_rollover_hour_does_not_come_due_the_same_morning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 02:00:00'));

        $onLastStep = new SchedulingState(
            queue: CardQueue::Learning,
            learningStep: 1,
            repetitions: 0,
            easiness: 2.5,
            intervalDays: 0,
            lapses: 0,
            nextReviewAt: now(),
        );

        $state = $this->scheduler->grade($onLastStep, ReviewGrade::Good);

        $this->assertSame('2026-03-11 04:00:00', $state->nextReviewAt->toDateTimeString());
    }

    /**
     * Intra-day steps are wall-clock, not day-boundary — a card due in ten
     * minutes must come back in ten minutes.
     */
    public function test_learning_steps_are_not_pushed_to_the_day_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 23:50:00'));

        $state = $this->scheduler->grade($this->newCard(), ReviewGrade::Good);

        $this->assertSame('2026-03-11 00:00:00', $state->nextReviewAt->toDateTimeString());
    }

    // ------------------------------------------------------ full lifecycles

    public function test_a_card_learned_and_kept_produces_a_growing_schedule(): void
    {
        $state = $this->newCard();
        $intervals = [];

        // Through the learning steps.
        $state = $this->scheduler->grade($state, ReviewGrade::Good);
        $state = $this->scheduler->grade($state, ReviewGrade::Good);
        $intervals[] = $state->intervalDays;

        // Then five reviews, all Good.
        for ($i = 0; $i < 5; $i++) {
            $state = $this->scheduler->grade($state, ReviewGrade::Good);
            $intervals[] = $state->intervalDays;
        }

        $this->assertSame([1, 3, 8, 20, 50, 125], $intervals);
        $this->assertSame(CardQueue::Review, $state->queue);
    }

    /**
     * The mirror of the run above: grading Hard every time must tighten the
     * schedule rather than stretch it.
     */
    public function test_a_card_always_graded_hard_stays_on_a_short_leash(): void
    {
        $state = $this->newCard();

        $state = $this->scheduler->grade($state, ReviewGrade::Good);
        $state = $this->scheduler->grade($state, ReviewGrade::Good);

        $intervals = [];
        for ($i = 0; $i < 5; $i++) {
            $state = $this->scheduler->grade($state, ReviewGrade::Hard);
            $intervals[] = $state->intervalDays;
        }

        // 1*1.2 rounds back to 1, so the minimum-growth rule is what moves this
        // card at all — one day at a time, which is the point of Hard.
        $this->assertSame([2, 3, 4, 5, 6], $intervals);
        $this->assertSame(1.75, $state->easiness);
    }

    /**
     * A passing grade always buys at least one more day. Without it, Hard on a
     * short interval rounds back to where it started and the card is stuck
     * coming up daily forever however well it is answered.
     */
    public function test_a_passing_grade_always_advances_a_short_interval(): void
    {
        foreach ([1, 2, 3] as $interval) {
            $state = $this->scheduler->grade(
                $this->reviewCard(intervalDays: $interval, easiness: 1.3),
                ReviewGrade::Hard,
            );

            $this->assertGreaterThan($interval, $state->intervalDays);
        }
    }

    public function test_forgetting_and_relearning_returns_a_card_to_review(): void
    {
        $state = $this->reviewCard(intervalDays: 40);

        $state = $this->scheduler->grade($state, ReviewGrade::Again);
        $this->assertSame(CardQueue::Relearning, $state->queue);

        $state = $this->scheduler->grade($state, ReviewGrade::Good);
        $this->assertSame(CardQueue::Review, $state->queue);
        $this->assertSame(1, $state->intervalDays);

        $state = $this->scheduler->grade($state, ReviewGrade::Good);
        $this->assertSame(2, $state->intervalDays);
    }
}
