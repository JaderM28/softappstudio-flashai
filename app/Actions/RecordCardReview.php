<?php

namespace App\Actions;

use App\Enums\ReviewQuality;
use App\Models\Card;
use App\Models\CardProgress;
use App\Models\CardReview;
use App\Services\SpacedRepetitionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a grade to a card: advances its SM-2 state and appends to the review
 * log. The two happen together or not at all, so the stats screen can never
 * disagree with the schedule.
 */
class RecordCardReview
{
    public function __construct(
        private readonly SpacedRepetitionService $scheduler,
    ) {}

    public function handle(Card $card, ReviewQuality $quality, ?Carbon $now = null): CardProgress
    {
        $now ??= now();

        return DB::transaction(function () use ($card, $quality, $now): CardProgress {
            // Lock the row: a double-tap on the grade buttons would otherwise
            // schedule the card twice off the same starting state.
            $progress = CardProgress::query()
                ->where('card_id', $card->id)
                ->lockForUpdate()
                ->firstOr(fn () => $this->startProgressFor($card, $now));

            $before = [
                'repetitions' => $progress->repetitions,
                'easiness' => $progress->easiness,
                'interval_days' => $progress->interval_days,
            ];

            $next = $this->scheduler->next(
                quality: $quality->value,
                repetitions: $before['repetitions'],
                easiness: $before['easiness'],
                intervalDays: $before['interval_days'],
                now: $now,
            );

            $progress->fill($next->toArray());
            $progress->last_quality = $quality->value;
            $progress->last_reviewed_at = $now;
            $progress->save();

            $review = new CardReview([
                'quality' => $quality->value,
                'repetitions_before' => $before['repetitions'],
                'repetitions_after' => $next->repetitions,
                'easiness_before' => $before['easiness'],
                'easiness_after' => $next->easiness,
                'interval_days_before' => $before['interval_days'],
                'interval_days_after' => $next->intervalDays,
                'reviewed_at' => $now,
            ]);
            $review->card_id = $card->id;
            $review->user_id = $card->user_id;
            $review->save();

            return $progress;
        });
    }

    /**
     * Cards created before their progress row exists — or whose row was lost
     * with a deck — still need to be gradeable.
     */
    private function startProgressFor(Card $card, Carbon $now): CardProgress
    {
        $progress = new CardProgress($this->scheduler->initial($now)->toArray());
        $progress->card_id = $card->id;
        $progress->user_id = $card->user_id;
        $progress->save();

        return $progress;
    }
}
