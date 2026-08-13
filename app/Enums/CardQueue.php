<?php

namespace App\Enums;

/**
 * Where a card sits in the scheduler. Plain SM-2 has no notion of this — it is
 * what learning steps need in order to exist, because a card being drilled in
 * minutes has to be told apart from one scheduled in months.
 */
enum CardQueue: string
{
    /** Never studied. */
    case New = 'new';

    /** Being drilled through the learning steps, minutes apart. */
    case Learning = 'learning';

    /** Graduated. Intervals are counted in days. */
    case Review = 'review';

    /** Was in review and failed. Being drilled back up through the relearning steps. */
    case Relearning = 'relearning';

    /** Out of rotation: a leech, or set aside by hand. */
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Learning => 'Learning',
            self::Review => 'Review',
            self::Relearning => 'Relearning',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * Whether cards in this queue can turn up in a session at all.
     */
    public function isStudiable(): bool
    {
        return $this !== self::Suspended;
    }

    /**
     * Whether the card is being drilled in minutes rather than days. These are
     * time-sensitive, which is why a session serves them before anything else.
     */
    public function isIntraday(): bool
    {
        return in_array($this, [self::Learning, self::Relearning], true);
    }

    /**
     * The queues a session draws from, in the order it draws them.
     *
     * @return array<int, self>
     */
    public static function sessionOrder(): array
    {
        return [self::Learning, self::Relearning, self::Review, self::New];
    }
}
