<?php

namespace Database\Factories;

use App\Enums\CardQueue;
use App\Enums\CardType;
use App\Models\Card;
use App\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    /**
     * Defaults to a brand new card: never studied, waiting in the new queue.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'note_id' => Note::factory(),
            'user_id' => fn (array $attributes) => Note::findOrFail($attributes['note_id'])->user_id,
            'deck_id' => fn (array $attributes) => Note::findOrFail($attributes['note_id'])->deck_id,
            'type' => CardType::Cloze,
            'queue' => CardQueue::New,
            'learning_step' => null,
            'repetitions' => 0,
            'easiness' => Card::DEFAULT_EASINESS,
            'interval_days' => 0,
            'next_review_at' => now(),
            'lapses' => 0,
            'buried_until' => null,
            'last_grade' => null,
            'last_reviewed_at' => null,
        ];
    }

    public function ofType(CardType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }

    public function inNote(Note $note): static
    {
        return $this->state(fn (array $attributes) => [
            'note_id' => $note->id,
            'user_id' => $note->user_id,
            'deck_id' => $note->deck_id,
        ]);
    }

    /**
     * Partway through the learning steps, due in minutes.
     */
    public function learning(int $step = 0): static
    {
        return $this->state(fn (array $attributes) => [
            'queue' => CardQueue::Learning,
            'learning_step' => $step,
            'next_review_at' => now()->addMinutes(10),
            'last_grade' => 3,
            'last_reviewed_at' => now(),
        ]);
    }

    /**
     * Graduated onto the day-scale ladder.
     */
    public function review(int $intervalDays = 6): static
    {
        return $this->state(fn (array $attributes) => [
            'queue' => CardQueue::Review,
            'learning_step' => null,
            'repetitions' => 3,
            'interval_days' => $intervalDays,
            'next_review_at' => now()->addDays($intervalDays),
            'last_grade' => 3,
            'last_reviewed_at' => now(),
        ]);
    }

    /**
     * Past the maturity threshold the stats screen counts as mastered.
     */
    public function mastered(): static
    {
        return $this->review(30)->state(fn (array $attributes) => [
            'repetitions' => 6,
            'easiness' => 2.65,
        ]);
    }

    /**
     * Failed after graduating, being drilled back up.
     */
    public function relearning(): static
    {
        return $this->state(fn (array $attributes) => [
            'queue' => CardQueue::Relearning,
            'learning_step' => 0,
            'repetitions' => 0,
            'easiness' => 2.15,
            'interval_days' => 1,
            'lapses' => 1,
            'next_review_at' => now()->addMinutes(10),
            'last_grade' => 1,
            'last_reviewed_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'queue' => CardQueue::Suspended,
        ]);
    }

    /**
     * A leech: failed often enough that the sentence itself is the problem.
     */
    public function leech(int $lapses = 8): static
    {
        return $this->relearning()->state(fn (array $attributes) => [
            'lapses' => $lapses,
            'easiness' => Card::DEFAULT_EASINESS,
        ]);
    }

    public function dueAt(Carbon $at): static
    {
        return $this->state(fn (array $attributes) => [
            'next_review_at' => $at,
        ]);
    }

    public function dueIn(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'next_review_at' => now()->addDays($days),
        ]);
    }

    public function buriedUntil(Carbon $at): static
    {
        return $this->state(fn (array $attributes) => [
            'buried_until' => $at,
        ]);
    }
}
