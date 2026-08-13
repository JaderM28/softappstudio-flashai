<?php

namespace Database\Factories;

use App\Enums\CardType;
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
            'target_language' => 'en',
            'native_language' => 'es',
            'prompt_instructions' => null,
            'card_types' => array_map(fn (CardType $type) => $type->value, CardType::defaults()),
            'show_translation' => true,
            'new_per_day' => null,
            'reviews_per_day' => null,
            'is_default' => false,
        ];
    }

    public function isDefault(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
            'name' => 'Inbox',
        ]);
    }

    /**
     * @param  array<int, CardType>  $types
     */
    public function generating(array $types): static
    {
        return $this->state(fn (array $attributes) => [
            'card_types' => array_map(fn (CardType $type) => $type->value, $types),
        ]);
    }

    public function withLimits(?int $newPerDay = null, ?int $reviewsPerDay = null): static
    {
        return $this->state(fn (array $attributes) => [
            'new_per_day' => $newPerDay,
            'reviews_per_day' => $reviewsPerDay,
        ]);
    }
}
