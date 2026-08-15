<?php

namespace App\Actions;

use App\Enums\CardQueue;
use App\Models\Card;
use App\Support\ReviewDay;
use Illuminate\Support\Carbon;

/**
 * Holds a sentence's other cards back until tomorrow.
 *
 * Answer the fill-in-the-blank for a sentence and its listening card is one
 * scroll away with the answer still on screen — grading it would measure
 * nothing but short-term memory, and the scheduler would believe it.
 *
 * Cards being drilled through the learning steps are left alone: they were
 * promised back in minutes, and burying them would strand them until tomorrow.
 */
class BurySiblings
{
    public function handle(Card $card, ?Carbon $now = null): int
    {
        if (! config('flashai.session.bury_siblings')) {
            return 0;
        }

        $now ??= now();

        return $card->siblings()
            ->whereIn('queue', [CardQueue::New->value, CardQueue::Review->value])
            ->where(fn ($query) => $query
                ->whereNull('buried_until')
                ->orWhere('buried_until', '<', ReviewDay::next($now)))
            ->update(['buried_until' => ReviewDay::next($now)]);
    }
}
