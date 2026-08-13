<?php

namespace App\Support;

use App\Enums\CardQueue;
use App\Models\Card;
use Illuminate\Support\Carbon;

/**
 * Everything the scheduler needs to know about a card, and everything it
 * decides. Passing this around instead of a model keeps the scheduling rules
 * testable without touching the database.
 */
readonly class SchedulingState
{
    public function __construct(
        public CardQueue $queue,
        public ?int $learningStep,
        public int $repetitions,
        public float $easiness,
        public int $intervalDays,
        public int $lapses,
        public Carbon $nextReviewAt,
    ) {}

    /**
     * A card that has never been studied: waiting in the new queue, and due as
     * soon as the daily allowance has room for it.
     */
    public static function forNewCard(?Carbon $now = null): self
    {
        return new self(
            queue: CardQueue::New,
            learningStep: null,
            repetitions: 0,
            easiness: (float) config('flashai.scheduler.starting_easiness'),
            intervalDays: 0,
            lapses: 0,
            nextReviewAt: $now?->copy() ?? now(),
        );
    }

    public static function fromCard(Card $card): self
    {
        return new self(
            queue: $card->queue,
            learningStep: $card->learning_step,
            repetitions: $card->repetitions,
            easiness: $card->easiness,
            intervalDays: $card->interval_days,
            lapses: $card->lapses,
            nextReviewAt: $card->next_review_at,
        );
    }

    /**
     * Shaped for filling a Card.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'queue' => $this->queue,
            'learning_step' => $this->learningStep,
            'repetitions' => $this->repetitions,
            'easiness' => $this->easiness,
            'interval_days' => $this->intervalDays,
            'lapses' => $this->lapses,
            'next_review_at' => $this->nextReviewAt,
        ];
    }

    public function isLeech(): bool
    {
        return $this->lapses >= (int) config('flashai.scheduler.leech_threshold');
    }
}
