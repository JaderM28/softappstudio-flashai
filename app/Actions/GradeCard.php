<?php

namespace App\Actions;

use App\Enums\ReviewGrade;
use App\Models\Card;
use App\Models\CardReview;
use App\Services\Scheduler;
use App\Support\SchedulingState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a grade to a card: advances its schedule and appends to the review
 * log. Both happen together or not at all, so the stats screen can never
 * disagree with the scheduler.
 */
class GradeCard
{
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly BurySiblings $burySiblings,
    ) {}

    public function handle(
        Card $card,
        ReviewGrade $grade,
        ?int $durationMs = null,
        ?Carbon $now = null,
    ): Card {
        $now ??= now();

        return DB::transaction(function () use ($card, $grade, $durationMs, $now): Card {
            // Lock the row: a double-tap on the grade buttons would otherwise
            // schedule the card twice from the same starting state, and write
            // two reviews for one answer.
            $fresh = Card::query()->whereKey($card->getKey())->lockForUpdate()->firstOrFail();

            $before = SchedulingState::fromCard($fresh);
            $after = $this->scheduler->grade($before, $grade, $now);

            $fresh->fill($after->toArray());
            $fresh->last_grade = $grade->value;
            $fresh->last_reviewed_at = $now;
            $fresh->save();

            $review = new CardReview([
                'grade' => $grade,
                'queue_before' => $before->queue,
                'queue_after' => $after->queue,
                'learning_step_before' => $before->learningStep,
                'learning_step_after' => $after->learningStep,
                'repetitions_before' => $before->repetitions,
                'repetitions_after' => $after->repetitions,
                'easiness_before' => $before->easiness,
                'easiness_after' => $after->easiness,
                'interval_days_before' => $before->intervalDays,
                'interval_days_after' => $after->intervalDays,
                'lapses_after' => $after->lapses,
                'duration_ms' => $durationMs,
                'reviewed_at' => $now,
            ]);

            // Ownership comes from the card, never from anything the request
            // can influence.
            $review->card_id = $fresh->id;
            $review->user_id = $fresh->user_id;
            $review->save();

            // Hold this sentence's other questions back until tomorrow, so the
            // second one is not answered with the first one's answer still on
            // screen.
            $this->burySiblings->handle($fresh, $now);

            return $fresh;
        });
    }
}
