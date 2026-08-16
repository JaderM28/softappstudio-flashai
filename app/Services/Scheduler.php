<?php

namespace App\Services;

use App\Enums\CardQueue;
use App\Enums\ReviewGrade;
use App\Support\SchedulingState;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * SM-2 with learning steps — the shape Anki actually runs.
 *
 * Pure calculation: it reads no database and writes nothing, so every rule
 * here is testable on its own. Persisting the result is GradeCard's job.
 *
 * Two departures from the 1987 algorithm, both because this app grades on four
 * buttons rather than a 0-5 self-rating:
 *
 *  - Easiness moves by fixed amounts per grade instead of following SM-2's
 *    quadratic curve, which was calibrated for that 0-5 scale.
 *  - New cards are drilled through short intra-day steps before they graduate,
 *    rather than jumping straight to a one-day interval.
 */
class Scheduler
{
    /**
     * Below this many days, fuzz cannot move an interval without changing it
     * out of proportion, so short intervals are left exact.
     */
    private const FUZZ_MINIMUM_DAYS = 3;

    /**
     * Whether the jitter is applied. Off only while previewing.
     */
    private bool $fuzz = true;

    /**
     * What each button would do, so the four of them can say so.
     *
     * Anki shows the interval on every button, and it is not decoration: "Hard"
     * and "Good" are a choice between two futures, and choosing blind is how a
     * card ends up years out because a tap landed one button to the right.
     *
     * Computed with the jitter off. It is ±5% of a number the user is reading
     * as a label, and a preview that disagreed with what actually happened
     * would be worse than no preview at all. Running the real grade() rather
     * than reimplementing the arithmetic is the point — a second copy would
     * drift from the first the day either changed.
     *
     * @return array<int, SchedulingState> keyed by ReviewGrade->value
     */
    public function previewAll(SchedulingState $state, ?Carbon $now = null): array
    {
        $this->fuzz = false;

        try {
            $previews = [];

            foreach (ReviewGrade::cases() as $grade) {
                $previews[$grade->value] = $this->grade($state, $grade, $now);
            }

            return $previews;
        } finally {
            $this->fuzz = true;
        }
    }

    public function grade(SchedulingState $state, ReviewGrade $grade, ?Carbon $now = null): SchedulingState
    {
        $now ??= now();

        return match ($state->queue) {
            CardQueue::New, CardQueue::Learning => $this->gradeLearning($state, $grade, $now),
            CardQueue::Relearning => $this->gradeRelearning($state, $grade, $now),
            CardQueue::Review => $this->gradeReview($state, $grade, $now),
            CardQueue::Suspended => throw new InvalidArgumentException(
                'A suspended card cannot be graded; unsuspend it first.'
            ),
        };
    }

    /**
     * A card being drilled through the learning steps, minutes apart.
     *
     * Easiness does not move here. It describes how hard a card is to keep in
     * memory over days, and a card that has not graduated has not yet said
     * anything about that.
     */
    private function gradeLearning(SchedulingState $state, ReviewGrade $grade, Carbon $now): SchedulingState
    {
        $steps = $this->learningSteps();
        $step = $state->learningStep ?? 0;

        return match ($grade) {
            ReviewGrade::Again => $this->atStep($state, $steps, CardQueue::Learning, 0, $now),
            ReviewGrade::Hard => $this->atStep($state, $steps, CardQueue::Learning, $step, $now),
            ReviewGrade::Good => $step + 1 >= count($steps)
                ? $this->graduate($state, (int) config('flashai.scheduler.graduating_interval'), $now)
                : $this->atStep($state, $steps, CardQueue::Learning, $step + 1, $now),
            // Easy skips the remaining steps entirely: there is nothing left to
            // drill on a card that came back instantly.
            ReviewGrade::Easy => $this->graduate($state, (int) config('flashai.scheduler.easy_interval'), $now),
        };
    }

    /**
     * A graduated card that was forgotten and is being drilled back up. The
     * easiness penalty was already applied at the lapse, so it stays put here.
     */
    private function gradeRelearning(SchedulingState $state, ReviewGrade $grade, Carbon $now): SchedulingState
    {
        $steps = $this->relearningSteps();
        $step = $state->learningStep ?? 0;

        return match ($grade) {
            ReviewGrade::Again => $this->atStep($state, $steps, CardQueue::Relearning, 0, $now),
            ReviewGrade::Hard => $this->atStep($state, $steps, CardQueue::Relearning, $step, $now),
            // Back to the interval the lapse left the card on, not to a fresh
            // graduating interval — it has been through this material before.
            ReviewGrade::Good => $step + 1 >= count($steps)
                ? $this->graduate($state, $state->intervalDays, $now)
                : $this->atStep($state, $steps, CardQueue::Relearning, $step + 1, $now),
            ReviewGrade::Easy => $this->graduate(
                $state,
                max($state->intervalDays, (int) config('flashai.scheduler.easy_interval')),
                $now,
            ),
        };
    }

    /**
     * A card on the day-scale ladder. This is where easiness moves and where
     * the interval actually grows.
     */
    private function gradeReview(SchedulingState $state, ReviewGrade $grade, Carbon $now): SchedulingState
    {
        $easiness = $this->adjustEasiness($state->easiness, $grade);

        if ($grade === ReviewGrade::Again) {
            return $this->lapse($state, $easiness, $now);
        }

        // The interval is computed from the easiness this review produced, so a
        // grade's effect on the schedule shows up immediately rather than one
        // review later.
        $interval = match ($grade) {
            ReviewGrade::Hard => $state->intervalDays * (float) config('flashai.scheduler.hard_multiplier'),
            ReviewGrade::Good => $state->intervalDays * $easiness,
            ReviewGrade::Easy => $state->intervalDays * $easiness * (float) config('flashai.scheduler.easy_bonus'),
            ReviewGrade::Again => $state->intervalDays,
        };

        // A passing grade must always buy at least one more day. Without this,
        // Hard on a one-day card computes 1 * 1.2 = 1.2, rounds back to 1, and
        // the card is stuck coming up every single day no matter how many times
        // it is answered correctly. Intervals of 1 and 2 days would be
        // absorbing states.
        $interval = max($state->intervalDays + 1, (int) round($interval));

        $interval = $this->clampInterval($this->applyFuzz($interval));

        return new SchedulingState(
            queue: CardQueue::Review,
            learningStep: null,
            repetitions: $state->repetitions + 1,
            easiness: $easiness,
            intervalDays: $interval,
            lapses: $state->lapses,
            nextReviewAt: $this->dueAfterDays($interval, $now),
        );
    }

    /**
     * Forgetting a graduated card. The interval restarts, the lapse is counted,
     * and the card drops into the relearning steps — or out of rotation
     * entirely once it has been forgotten often enough to be a leech.
     */
    private function lapse(SchedulingState $state, float $easiness, Carbon $now): SchedulingState
    {
        $lapses = $state->lapses + 1;

        $interval = max(
            (int) config('flashai.scheduler.minimum_lapse_interval'),
            (int) round($state->intervalDays * (float) config('flashai.scheduler.lapse_multiplier')),
        );

        $isLeech = $lapses >= (int) config('flashai.scheduler.leech_threshold');
        $steps = $this->relearningSteps();

        return new SchedulingState(
            queue: $isLeech ? CardQueue::Suspended : CardQueue::Relearning,
            learningStep: $isLeech ? null : 0,
            // Repetitions counts consecutive successful reviews, so forgetting
            // the card resets it. The lifetime history lives in card_reviews.
            repetitions: 0,
            easiness: $easiness,
            intervalDays: $interval,
            lapses: $lapses,
            nextReviewAt: $isLeech
                ? $this->dueAfterDays($interval, $now)
                : $now->copy()->addMinutes($steps[0]),
        );
    }

    /**
     * Park the card on a learning or relearning step, due minutes from now.
     *
     * @param  array<int, int>  $steps
     */
    private function atStep(
        SchedulingState $state,
        array $steps,
        CardQueue $queue,
        int $step,
        Carbon $now,
    ): SchedulingState {
        $step = min($step, count($steps) - 1);

        return new SchedulingState(
            queue: $queue,
            learningStep: $step,
            repetitions: $state->repetitions,
            easiness: $state->easiness,
            intervalDays: $state->intervalDays,
            lapses: $state->lapses,
            nextReviewAt: $now->copy()->addMinutes($steps[$step]),
        );
    }

    /**
     * Move the card onto the day-scale ladder.
     */
    private function graduate(SchedulingState $state, int $intervalDays, Carbon $now): SchedulingState
    {
        $interval = $this->clampInterval(max(1, $intervalDays));

        return new SchedulingState(
            queue: CardQueue::Review,
            learningStep: null,
            repetitions: $state->repetitions + 1,
            easiness: $state->easiness,
            intervalDays: $interval,
            lapses: $state->lapses,
            nextReviewAt: $this->dueAfterDays($interval, $now),
        );
    }

    private function adjustEasiness(float $easiness, ReviewGrade $grade): float
    {
        $adjusted = $easiness + $grade->easinessDelta();

        return round(
            min(
                (float) config('flashai.scheduler.maximum_easiness'),
                max((float) config('flashai.scheduler.minimum_easiness'), $adjusted),
            ),
            2,
        );
    }

    /**
     * Jitter the interval so cards added together do not stay clumped together
     * for the rest of their lives.
     */
    private function applyFuzz(int $days): int
    {
        $percent = (int) config('flashai.scheduler.fuzz_percent');

        if (! $this->fuzz || $percent <= 0 || $days < self::FUZZ_MINIMUM_DAYS) {
            return $days;
        }

        $spread = max(1, (int) round($days * $percent / 100));

        return random_int($days - $spread, $days + $spread);
    }

    private function clampInterval(int $days): int
    {
        return max(1, min((int) config('flashai.scheduler.maximum_interval'), $days));
    }

    /**
     * Day-scale intervals land on the start of a review day rather than an
     * exact wall-clock offset. Without this, a card graded at 21:00 comes due
     * at 21:00 the next day, misses the morning session, and the drift
     * compounds on every review. See config/flashai.php for the rollover hour.
     */
    private function dueAfterDays(int $days, Carbon $now): Carbon
    {
        $rolloverHour = (int) config('flashai.review.day_starts_at_hour');

        $due = $now->copy()
            ->addDays($days)
            ->startOfDay()
            ->addHours($rolloverHour);

        // A review before the rollover hour still belongs to the previous
        // review day, so without this a card graded at 02:00 with a one-day
        // interval would fall due at 04:00 the same morning.
        if ($due->lessThanOrEqualTo($now)) {
            $due->addDay();
        }

        return $due;
    }

    /**
     * @return array<int, int>
     */
    private function learningSteps(): array
    {
        return $this->steps('flashai.scheduler.learning_steps');
    }

    /**
     * @return array<int, int>
     */
    private function relearningSteps(): array
    {
        return $this->steps('flashai.scheduler.relearning_steps');
    }

    /**
     * @return array<int, int>
     */
    private function steps(string $key): array
    {
        $steps = array_values(array_map('intval', (array) config($key)));

        // An empty step list would mean a card graduates the moment it is seen,
        // which is the behaviour these steps exist to prevent.
        return $steps === [] ? [1] : $steps;
    }
}
