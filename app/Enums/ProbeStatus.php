<?php

namespace App\Enums;

/**
 * How a single diagnostic probe came out.
 */
enum ProbeStatus: string
{
    case Ok = 'ok';

    /** Tried, and the service said no. */
    case Failed = 'failed';

    /** Not tried: no key, no binary, nothing to test against. */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Failed => 'Failed',
            self::Skipped => 'Not set up',
        };
    }

    /**
     * A skipped probe is deliberately not a failure. Openverse needs no key and
     * Unsplash is the last resort of three, so a collection that works fine
     * would otherwise light up red for something nobody is missing.
     */
    public function isProblem(): bool
    {
        return $this === self::Failed;
    }
}
