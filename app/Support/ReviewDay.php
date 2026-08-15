<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Where one review day ends and the next begins.
 *
 * Not midnight: someone studying at 00:30 is finishing the previous day, and
 * counting that session against a fresh daily allowance would let them do twice
 * the work and see cards they were meant to see tomorrow. The scheduler uses
 * the same boundary for due dates, so the two never disagree.
 */
final class ReviewDay
{
    public static function rolloverHour(): int
    {
        return (int) config('flashai.review.day_starts_at_hour');
    }

    /**
     * The moment the current review day began.
     */
    public static function start(?Carbon $now = null): Carbon
    {
        $now = ($now ?? now())->copy();

        $start = $now->copy()->startOfDay()->addHours(self::rolloverHour());

        return $start->greaterThan($now) ? $start->subDay() : $start;
    }

    /**
     * The moment the next review day begins — when buried cards come back and
     * the daily allowances reset.
     */
    public static function next(?Carbon $now = null): Carbon
    {
        return self::start($now)->addDay();
    }
}
