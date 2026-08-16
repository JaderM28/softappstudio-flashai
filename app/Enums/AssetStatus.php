<?php

namespace App\Enums;

/**
 * Progress of one generated asset on a note.
 *
 * Tracked per asset rather than once per note because three external services
 * are involved and they fail independently — Unsplash rate-limits far sooner
 * than the others, and the speech tier is measured in single-digit requests a
 * minute. A note needs both before it becomes a card, but it needs to be able
 * to say which half it is still waiting on, and one global flag cannot.
 */
enum AssetStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Processing => 'Generating…',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
        };
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::Ready, self::Failed], true);
    }
}
