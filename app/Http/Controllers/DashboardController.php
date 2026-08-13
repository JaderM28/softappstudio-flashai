<?php

namespace App\Http\Controllers;

use App\Enums\CardState;
use App\Models\Card;
use App\Services\ReviewQueue;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ReviewQueue $queue,
    ) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        $byState = Card::query()
            ->where('user_id', $user->id)
            ->get(['queue', 'interval_days'])
            ->countBy(fn (Card $card) => $card->state->value);

        return view('dashboard', [
            'counts' => $this->queue->counts($user),
            'dueCount' => $this->queue->dueCount($user),
            'noteCount' => $user->notes()->count(),
            'states' => collect(CardState::cases())
                ->mapWithKeys(fn (CardState $state) => [
                    $state->value => (int) $byState->get($state->value, 0),
                ]),
        ]);
    }
}
