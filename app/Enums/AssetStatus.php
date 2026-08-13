<?php

namespace App\Enums;

/**
 * Progress of one generated asset on a note.
 *
 * Tracked per asset rather than once per note because three external services
 * are involved and they fail independently — Unsplash rate-limits far sooner
 * than the others. A note with text and audio but no picture is perfectly
 * studiable, and a single global flag would force us to call it broken.
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
