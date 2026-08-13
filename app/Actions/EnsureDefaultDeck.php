<?php

namespace App\Actions;

use App\Models\Deck;
use App\Models\User;

/**
 * Every user has a deck new sentences land in, so quick capture from a phone
 * never has to stop and ask which deck this belongs in.
 */
class EnsureDefaultDeck
{
    public const NAME = 'Inbox';

    public function handle(User $user): Deck
    {
        $existing = $user->decks()->where('is_default', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        $deck = new Deck([
            'name' => $this->availableName($user),
            'description' => 'Sentences land here when no other deck is chosen.',
        ]);

        $deck->user_id = $user->id;
        $deck->is_default = true;
        $deck->save();

        return $deck;
    }

    /**
     * Deck names are unique per user, so a hand-made deck already called Inbox
     * must not stop the default one from being created.
     */
    private function availableName(User $user): string
    {
        $name = self::NAME;
        $suffix = 2;

        while ($user->decks()->where('name', $name)->exists()) {
            $name = self::NAME.' '.$suffix++;
        }

        return $name;
    }
}
