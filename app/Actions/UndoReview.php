<?php

namespace App\Actions;

use App\Models\Card;
use App\Models\CardReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Takes back the last grade.
 *
 * A mis-tap on Easy pushes a card weeks out, and without this the only way
 * back is to wait for it or edit the database. The review log records the state
 * on both sides of every grade, so undoing is a matter of restoring one row and
 * deleting the log entry that recorded the change.
 */
class UndoReview
{
    public function lastReviewFor(User $user): ?CardReview
    {
        return $user->reviews()
            ->latest('reviewed_at')
            ->latest('id')
            ->first();
    }

    public function handle(User $user): ?Card
    {
        return DB::transaction(function () use ($user): ?Card {
            $review = $this->lastReviewFor($user);

            if ($review === null) {
                return null;
            }

            $card = Card::query()->whereKey($review->card_id)->lockForUpdate()->first();

            if ($card === null) {
                $review->delete();

                return null;
            }

            $card->fill($review->undoState());

            // The grade before this one, which is what the card showed until a
            // moment ago.
            $previous = $card->reviews()
                ->where('id', '!=', $review->id)
                ->latest('reviewed_at')
                ->latest('id')
                ->first();

            $card->last_grade = $previous?->grade->value;
            $card->last_reviewed_at = $previous?->reviewed_at;
            $card->save();

            // Grading buried this sentence's other cards, so taking the grade
            // back releases them too. If another card of the same sentence was
            // graded earlier today this releases them a day early, which is a
            // far smaller wrong than undo leaving them stuck.
            $card->siblings()->where('buried_until', '>', now())->update(['buried_until' => null]);

            $review->delete();

            return $card;
        });
    }
}
