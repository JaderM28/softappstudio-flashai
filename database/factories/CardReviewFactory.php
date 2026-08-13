<?php

namespace Database\Factories;

use App\Enums\ReviewQuality;
use App\Models\Card;
use App\Models\CardReview;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CardReview>
 */
class CardReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quality = fake()->randomElement(ReviewQuality::cases());

        return [
            'card_id' => Card::factory(),
            'user_id' => fn (array $attributes) => Card::findOrFail($attributes['card_id'])->user_id,
            'quality' => $quality->value,
            'repetitions_before' => 0,
            'repetitions_after' => $quality->isSuccessful() ? 1 : 0,
            'easiness_before' => 2.5,
            'easiness_after' => 2.5,
            'interval_days_before' => 0,
            'interval_days_after' => 1,
            'reviewed_at' => now(),
        ];
    }

    public function reviewedAt(Carbon $at): static
    {
        return $this->state(fn (array $attributes) => [
            'reviewed_at' => $at,
        ]);
    }

    public function withQuality(ReviewQuality $quality): static
    {
        return $this->state(fn (array $attributes) => [
            'quality' => $quality->value,
        ]);
    }
}
