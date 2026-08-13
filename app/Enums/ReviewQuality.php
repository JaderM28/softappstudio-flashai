<?php

namespace App\Enums;

/**
 * SM-2 grades a review from 0 to 5, but three buttons is all a phone screen
 * wants. These are the three the UI exposes, mapped onto the SM-2 scale.
 *
 * The boundary that matters is 3: at or above it the card advances, below it
 * the card resets to a one-day interval.
 */
enum ReviewQuality: int
{
    case Wrong = 2;
    case Hard = 3;
    case Easy = 5;

    public function label(): string
    {
        return match ($this) {
            self::Wrong => 'Wrong',
            self::Hard => 'Hard',
            self::Easy => 'Easy',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Wrong => '❌',
            self::Hard => '😐',
            self::Easy => '✅',
        };
    }

    /**
     * Whether the answer counted as recalled, and so whether the interval grows.
     */
    public function isSuccessful(): bool
    {
        return $this->value >= 3;
    }
}
