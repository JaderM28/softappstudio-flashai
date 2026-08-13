<?php

namespace Database\Factories;

use App\Enums\CardQueue;
use App\Enums\ReviewGrade;
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
        $grade = fake()->randomElement(ReviewGrade::cases());

        return [
            'card_id' => Card::factory(),
            'user_id' => fn (array $attributes) => Card::findOrFail($attributes['card_id'])->user_id,
            'grade' => $grade,
            'queue_before' => CardQueue::New,
            'queue_after' => $grade === ReviewGrade::Easy ? CardQueue::Review : CardQueue::Learning,
            'learning_step_before' => null,
            'learning_step_after' => $grade === ReviewGrade::Easy ? null : 0,
            'repetitions_before' => 0,
            'repetitions_after' => $grade->isPass() ? 1 : 0,
            'easiness_before' => Card::DEFAULT_EASINESS,
            'easiness_after' => Card::DEFAULT_EASINESS + $grade->easinessDelta(),
            'interval_days_before' => 0,
            'interval_days_after' => $grade === ReviewGrade::Easy ? 4 : 0,
            'lapses_after' => 0,
            'duration_ms' => fake()->numberBetween(1200, 9000),
            'reviewed_at' => now(),
        ];
    }

    public function reviewedAt(Carbon $at): static
    {
        return $this->state(fn (array $attributes) => [
            'reviewed_at' => $at,
        ]);
    }

    public function withGrade(ReviewGrade $grade): static
    {
        return $this->state(fn (array $attributes) => [
            'grade' => $grade,
            'easiness_after' => Card::DEFAULT_EASINESS + $grade->easinessDelta(),
        ]);
    }

    public function forCard(Card $card): static
    {
        return $this->state(fn (array $attributes) => [
            'card_id' => $card->id,
            'user_id' => $card->user_id,
        ]);
    }
}
