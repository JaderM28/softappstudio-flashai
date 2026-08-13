<?php

namespace App\Enums;

/**
 * The buckets the stats screen reports on. Derived from the card's scheduling
 * state rather than stored, so it can never drift out of sync with it.
 */
enum CardState: string
{
    case New = 'new';
    case Learning = 'learning';
    case Mastered = 'mastered';
    case Suspended = 'suspended';

    /**
     * A card counts as mastered once its interval reaches three weeks — the
     * same maturity threshold Anki uses, and a better signal than a repetition
     * count because it accounts for the easiness factor.
     */
    public const MASTERED_INTERVAL_DAYS = 21;

    public static function fromCard(CardQueue $queue, int $intervalDays): self
    {
        return match (true) {
            $queue === CardQueue::Suspended => self::Suspended,
            $queue === CardQueue::New => self::New,
            $queue === CardQueue::Review && $intervalDays >= self::MASTERED_INTERVAL_DAYS => self::Mastered,
            default => self::Learning,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Learning => 'Learning',
            self::Mastered => 'Mastered',
            self::Suspended => 'Suspended',
        };
    }
}
