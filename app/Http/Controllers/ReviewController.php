<?php

namespace App\Http\Controllers;

use App\Actions\GradeCard;
use App\Actions\UndoReview;
use App\Http\Requests\GradeCardRequest;
use App\Models\Card;
use App\Services\ReviewQueue;
use App\Services\Scheduler;
use App\Support\IntervalLabel;
use App\Support\SchedulingState;
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
        private readonly Scheduler $scheduler,
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
            $nextDueAt = $this->queue->nextDueAt($user);

            return view('review.done', [
                'reviewedToday' => $user->reviews()
                    ->where('reviewed_at', '>=', ReviewDay::start())
                    ->count(),
                'newRemaining' => $this->queue->newSentencesRemaining($user),
                'canUndo' => $this->undoReview->lastReviewFor($user) !== null,
                // Three different endings that used to look like one. See the
                // view: nothing to study at all, waiting on the clock, or done
                // for today with more waiting behind the cap.
                'noteCount' => $user->notes()->count(),
                'unfinished' => $user->notes()->doesntHave('cards')->count(),
                'heldBack' => $this->queue->heldBackByTodaysCap($user),
                'nextDueAt' => $nextDueAt,
                'nextDueLabel' => $nextDueAt === null
                    ? null
                    : IntervalLabel::between(now(), $nextDueAt),
                // Only worth reloading for a wait measured in minutes; a card
                // due tomorrow is not something to sit and watch for.
                'reloadInSeconds' => $nextDueAt !== null && $nextDueAt->diffInMinutes(now(), absolute: true) <= 60
                    ? max(15, (int) ceil(now()->diffInSeconds($nextDueAt, absolute: true)) + 2)
                    : null,
            ]);
        }

        return view('review.show', [
            'card' => $card,
            'note' => $card->note,
            'deck' => $card->deck,
            'counts' => $this->queue->counts($user),
            'canUndo' => $this->undoReview->lastReviewFor($user) !== null,
            // What each button would do to this card, so the choice between
            // Hard and Good is an informed one.
            'previews' => $this->previewsFor($card),
        ]);
    }

    /**
     * The interval each grade would produce, as a label.
     *
     * @return array<int, string> keyed by ReviewGrade->value
     */
    private function previewsFor(Card $card): array
    {
        $now = now();

        return collect($this->scheduler->previewAll(SchedulingState::fromCard($card), $now))
            ->map(fn (SchedulingState $state) => IntervalLabel::between($now, $state->nextReviewAt))
            ->all();
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
