<?php

namespace App\Policies;

use App\Models\Card;
use App\Models\User;

class CardPolicy
{
    public function view(User $user, Card $card): bool
    {
        return $card->user_id === $user->id;
    }

    public function grade(User $user, Card $card): bool
    {
        return $card->user_id === $user->id && ! $card->isSuspended();
    }
}
