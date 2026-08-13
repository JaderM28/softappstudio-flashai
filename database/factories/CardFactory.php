<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Card;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    /**
     * Defaults to a fully generated card, since that is the state most tests
     * and screens care about.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $word = fake()->word();

        return [
            'user_id' => User::factory(),
            'deck_id' => null,
            'front_text' => $word,
            'back_text' => fake()->word(),
            'example_sentence' => fake()->sentence(),
            'pronunciation_note' => fake()->optional()->lexify('/????/'),
            'image_url' => fake()->imageUrl(),
            'image_path' => "cards/images/{$word}.jpg",
            'image_attribution' => [
                'photographer' => fake()->name(),
                'profile_url' => fake()->url(),
                'source' => 'unsplash',
            ],
            'audio_front_path' => "cards/audio/{$word}-front.mp3",
            'audio_back_path' => "cards/audio/{$word}-back.mp3",
            'generation_status' => GenerationStatus::Completed,
            'generation_error' => null,
        ];
    }

    /**
     * A card the user has just submitted, before any job has run.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'back_text' => null,
            'example_sentence' => null,
            'pronunciation_note' => null,
            'image_url' => null,
            'image_path' => null,
            'image_attribution' => null,
            'audio_front_path' => null,
            'audio_back_path' => null,
            'generation_status' => GenerationStatus::Pending,
        ]);
    }

    public function failed(string $error = 'Gemini API unreachable'): static
    {
        return $this->pending()->state(fn (array $attributes) => [
            'generation_status' => GenerationStatus::Failed,
            'generation_error' => $error,
        ]);
    }

    public function inDeck(Deck $deck): static
    {
        return $this->state(fn (array $attributes) => [
            'deck_id' => $deck->id,
            'user_id' => $deck->user_id,
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
