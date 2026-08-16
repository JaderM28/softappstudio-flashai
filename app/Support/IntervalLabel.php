<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * How long until a card comes back, in the shorthand a grade button can wear.
 *
 * Anki's vocabulary, and it is worth copying exactly: these are read a hundred
 * times a day at a glance, so they have to be short enough to sit under a word
 * and consistent enough to be recognised without reading.
 */
final class IntervalLabel
{
    private const MINUTES_IN_DAY = 1440;

    public static function between(Carbon $from, Carbon $to): string
    {
        $minutes = $from->diffInMinutes($to, absolute: false);

        // A step that has already come due — the card is waiting right now.
        if ($minutes < 1) {
            return '<1m';
        }

        if ($minutes < 60) {
            return round($minutes).'m';
        }

        if ($minutes < self::MINUTES_IN_DAY) {
            return round($minutes / 60).'h';
        }

        $days = $minutes / self::MINUTES_IN_DAY;

        if ($days < 30) {
            return round($days).'d';
        }

        if ($days < 365) {
            return self::trim($days / 30.44).'mo';
        }

        return self::trim($days / 365.25).'y';
    }

    /**
     * One decimal, but only when it says something: "2.5mo" is useful, "12.0mo"
     * is noise on a button four characters wide.
     */
    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
