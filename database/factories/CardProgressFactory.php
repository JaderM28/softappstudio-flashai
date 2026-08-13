<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\CardProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardProgress>
 */
class CardProgressFactory extends Factory
{
    /**
     * Defaults to a brand new card: never reviewed, due now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            // Progress always belongs to whoever owns the card; card_id is already
            // resolved to an id by the time this closure runs.
            'user_id' => fn (array $attributes) => Card::findOrFail($attributes['card_id'])->user_id,
            'repetitions' => 0,
            'easiness' => CardProgress::DEFAULT_EASINESS,
            'interval_days' => 0,
            'next_review_at' => now(),
            'last_quality' => null,
            'last_reviewed_at' => null,
        ];
    }

    public function dueIn(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'next_review_at' => now()->addDays($days),
        ]);
    }

    /**
     * A card partway through learning: a few successful reps, short interval.
     */
    public function learning(): static
    {
        return $this->state(fn (array $attributes) => [
            'repetitions' => 2,
            'interval_days' => 6,
            'last_quality' => 4,
            'last_reviewed_at' => now()->subDays(6),
            'next_review_at' => now(),
        ]);
    }

    /**
     * A card past the 21-day maturity threshold.
     */
    public function mastered(): static
    {
        return $this->state(fn (array $attributes) => [
            'repetitions' => 5,
            'easiness' => 2.6,
            'interval_days' => 30,
            'last_quality' => 5,
            'last_reviewed_at' => now()->subDays(30),
            'next_review_at' => now()->addDays(30),
        ]);
    }
}
