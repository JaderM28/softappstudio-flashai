<?php

namespace App\Enums;

/**
 * The buckets CU-05 reports on. Derived from SM-2 state rather than stored, so
 * it can never drift out of sync with the card's actual progress.
 */
enum CardState: string
{
    case New = 'new';
    case Learning = 'learning';
    case Mastered = 'mastered';

    /**
     * A card is mastered once its interval reaches three weeks — the same
     * "mature card" threshold Anki uses, and a better signal than a raw
     * repetition count because it accounts for the easiness factor.
     */
    public const MASTERED_INTERVAL_DAYS = 21;

    public static function fromProgress(int $repetitions, int $intervalDays): self
    {
        return match (true) {
            $repetitions === 0 => self::New,
            $intervalDays >= self::MASTERED_INTERVAL_DAYS => self::Mastered,
            default => self::Learning,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nueva',
            self::Learning => 'Aprendiendo',
            self::Mastered => 'Dominada',
        };
    }
}
