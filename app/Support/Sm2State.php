<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The scheduling state SM-2 produces for a card: what the algorithm decided,
 * with nothing persisted yet.
 */
readonly class Sm2State
{
    public function __construct(
        public int $repetitions,
        public float $easiness,
        public int $intervalDays,
        public Carbon $nextReviewAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'repetitions' => $this->repetitions,
            'easiness' => $this->easiness,
            'interval_days' => $this->intervalDays,
            'next_review_at' => $this->nextReviewAt,
        ];
    }
}
