<?php

namespace App\Services;

use App\Models\Card;
use App\Support\Sm2State;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * SM-2, the SuperMemo 2 scheduling algorithm.
 *
 * Pure calculation: it reads no database and writes nothing. Persisting the
 * result is RecordCardReview's job, which keeps the scheduling rules testable
 * on their own.
 */
class SpacedRepetitionService
{
    /**
     * SM-2 never lets a card get harder than this, otherwise a run of bad
     * reviews would collapse the interval towards zero and the card would
     * reappear forever.
     */
    public const MINIMUM_EASINESS = 1.3;

    /**
     * The grade at or above which the answer counts as recalled.
     */
    public const PASSING_QUALITY = 3;

    /**
     * @param  int  $quality  0-5, where 5 is a perfect recall
     */
    public function next(
        int $quality,
        int $repetitions,
        float $easiness,
        int $intervalDays,
        ?Carbon $now = null,
    ): Sm2State {
        if ($quality < 0 || $quality > 5) {
            throw new InvalidArgumentException("SM-2 quality must be between 0 and 5, got {$quality}.");
        }

        if ($quality >= self::PASSING_QUALITY) {
            // Note the order: the interval uses the easiness the card had going
            // *into* this review, and only then does easiness update. That is
            // what the original algorithm specifies.
            $intervalDays = match ($repetitions) {
                0 => 1,
                1 => 6,
                default => max(1, (int) round($intervalDays * $easiness)),
            };
            $repetitions++;
        } else {
            // A failed card goes back to the start of the ladder, but keeps its
            // easiness penalty so it is treated as a hard card from now on.
            $repetitions = 0;
            $intervalDays = 1;
        }

        return new Sm2State(
            repetitions: $repetitions,
            easiness: $this->adjustEasiness($easiness, $quality),
            intervalDays: $intervalDays,
            nextReviewAt: $this->dueDateAfter($intervalDays, $now),
        );
    }

    /**
     * The starting state for a card that has never been reviewed: due
     * immediately, so it shows up in the next session.
     */
    public function initial(?Carbon $now = null): Sm2State
    {
        return new Sm2State(
            repetitions: 0,
            easiness: Card::DEFAULT_EASINESS,
            intervalDays: 0,
            nextReviewAt: $now?->copy() ?? now(),
        );
    }

    /**
     * EF' = EF + (0.1 - (5-q) * (0.08 + (5-q) * 0.02)), floored at 1.3.
     *
     * This is the original SM-2 formula. The project plan carries a linearised
     * variant, EF + 0.1 - (5-q) * 0.08, which drops the quadratic term and so
     * barely punishes a struggling card: on a "Hard" grade it moves easiness by
     * -0.06 where the real formula moves it by -0.14. Cards graded Hard over and
     * over would keep stretching their intervals instead of tightening them,
     * which is the one behaviour spaced repetition exists to prevent.
     */
    private function adjustEasiness(float $easiness, int $quality): float
    {
        $miss = 5 - $quality;

        $adjusted = $easiness + (0.1 - $miss * (0.08 + $miss * 0.02));

        return round(max(self::MINIMUM_EASINESS, $adjusted), 2);
    }

    /**
     * Intervals are counted in days, so a card falls due at the start of a
     * review day rather than at an exact wall-clock offset. See the reasoning
     * in config/flashai.php.
     */
    private function dueDateAfter(int $intervalDays, ?Carbon $now = null): Carbon
    {
        $rolloverHour = (int) config('flashai.review.day_starts_at_hour');

        $due = ($now?->copy() ?? now())
            ->addDays($intervalDays)
            ->startOfDay()
            ->addHours($rolloverHour);

        // Reviewing before the rollover hour still belongs to the previous
        // review day, so without this a card graded at 02:00 with a one-day
        // interval would fall due at 04:00 the same morning.
        if ($due->lessThanOrEqualTo($now ?? now())) {
            $due->addDay();
        }

        return $due;
    }
}
