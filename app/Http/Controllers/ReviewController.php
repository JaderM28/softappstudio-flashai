<?php

namespace App\Http\Controllers;

use App\Actions\GradeCard;
use App\Actions\UndoReview;
use App\Http\Requests\GradeCardRequest;
use App\Models\Card;
use App\Services\ReviewQueue;
use App\Support\ReviewDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewQueue $queue,
        private readonly GradeCard $gradeCard,
        private readonly UndoReview $undoReview,
    ) {}

    /**
     * Take back the last grade. A mis-tap on Easy pushes a card weeks out, and
     * without this the only way back is to wait for it.
     */
    public function undo(Request $request): RedirectResponse
    {
        $card = $this->undoReview->handle($request->user());

        return redirect()
            ->route('review.show')
            ->with('status', $card === null
                ? 'Nothing to undo.'
                : 'Last answer taken back.');
    }

    /**
     * The session is a single screen that keeps replacing itself: there is no
     * queue held in the session, so closing the tab mid-review loses nothing
     * and reopening it picks up exactly where the schedule says to.
     */
    public function show(Request $request): View
    {
        $user = $request->user();
        $card = $this->queue->nextCard($user);

        if ($card === null) {
            return view('review.done', [
                'reviewedToday' => $user->reviews()
                    ->where('reviewed_at', '>=', ReviewDay::start())
                    ->count(),
                'newRemaining' => $this->queue->newSentencesRemaining($user),
                'canUndo' => $this->undoReview->lastReviewFor($user) !== null,
            ]);
        }

        return view('review.show', [
            'card' => $card,
            'note' => $card->note,
            'deck' => $card->deck,
            'counts' => $this->queue->counts($user),
            'canUndo' => $this->undoReview->lastReviewFor($user) !== null,
        ]);
    }

    public function grade(GradeCardRequest $request, Card $card): RedirectResponse
    {
        $this->authorize('grade', $card);

        $this->gradeCard->handle(
            $card,
            $request->grade(),
            $request->integer('duration_ms') ?: null,
        );

        return redirect()->route('review.show');
    }
}
