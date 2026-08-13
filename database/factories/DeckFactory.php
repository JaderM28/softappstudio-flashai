<?php

namespace Database\Factories;

use App\Models\Deck;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deck>
 */
class DeckFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Unique because decks are unique per (user_id, name).
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
