<?php

namespace App\Policies;

use App\Models\Deck;
use App\Models\User;

class DeckPolicy
{
    public function view(User $user, Deck $deck): bool
    {
        return $deck->user_id === $user->id;
    }

    public function update(User $user, Deck $deck): bool
    {
        return $deck->user_id === $user->id;
    }

    public function delete(User $user, Deck $deck): bool
    {
        // The default deck is where sentences land when none is chosen, so it
        // cannot be removed without leaving quick capture nowhere to go.
        return $deck->user_id === $user->id && ! $deck->is_default;
    }
}
