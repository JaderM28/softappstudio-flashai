<?php

namespace App\Enums;

/**
 * The four buttons shown after an answer.
 *
 * The draft had three. The fourth earns its place on cards you got right:
 * without it, a recall that cost you real effort stretches the interval by the
 * same multiplier as one that came instantly, and the schedule stops tracking
 * how well you actually know the card.
 *
 * The numbers are Anki's, not SM-2's 0-5 self-rating scale.
 */
enum ReviewGrade: int
{
    case Again = 1;
    case Hard = 2;
    case Good = 3;
    case Easy = 4;

    public function label(): string
    {
        return match ($this) {
            self::Again => 'Again',
            self::Hard => 'Hard',
            self::Good => 'Good',
            self::Easy => 'Easy',
        };
    }

    /**
     * Whether the answer counted as recalled. Everything above this line keeps
     * the card moving forward; Again sends it back to the steps.
     */
    public function isPass(): bool
    {
        return $this !== self::Again;
    }

    /**
     * How this grade moves the easiness factor.
     *
     * Fixed adjustments rather than SM-2's quadratic curve, which was designed
     * for a 0-5 self-rating. With four buttons these behave better and are what
     * a decade of Anki use has tuned.
     */
    public function easinessDelta(): float
    {
        return match ($this) {
            self::Again => -0.20,
            self::Hard => -0.15,
            self::Good => 0.0,
            self::Easy => 0.15,
        };
    }
}
