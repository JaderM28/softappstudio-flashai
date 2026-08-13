<?php

namespace App\Listeners;

use App\Actions\EnsureDefaultDeck;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

/**
 * A brand new account has somewhere to put its first sentence before it has
 * made any decks.
 */
class CreateDefaultDeckForNewUser
{
    public function __construct(
        private readonly EnsureDefaultDeck $ensureDefaultDeck,
    ) {}

    public function handle(Registered $event): void
    {
        if ($event->user instanceof User) {
            $this->ensureDefaultDeck->handle($event->user);
        }
    }
}
