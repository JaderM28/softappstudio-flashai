<?php

namespace App\Http\Controllers;

use App\Actions\GradeCard;
use App\Http\Requests\GradeCardRequest;
use App\Models\Card;
use App\Services\ReviewQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewQueue $queue,
        private readonly GradeCard $gradeCard,
    ) {}

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
                    ->where('reviewed_at', '>=', now()->startOfDay())
                    ->count(),
            ]);
        }

        return view('review.show', [
            'card' => $card,
            'note' => $card->note,
            'deck' => $card->deck,
            'counts' => $this->queue->counts($user),
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
