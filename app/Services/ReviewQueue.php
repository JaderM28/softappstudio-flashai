<?php

namespace App\Services;

use App\Enums\CardQueue;
use App\Models\Card;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Decides what to study next.
 *
 * Queues are served in a fixed order: cards being drilled through the learning
 * steps first because they are time-sensitive and were promised back in
 * minutes, then reviews, then new material. Never new cards first — that
 * front-loads the hardest work of the session onto someone who has not warmed
 * up yet, and leaves the reviews they actually owe to the end.
 */
class ReviewQueue
{
    public function nextCard(User $user, ?Deck $deck = null, ?Carbon $at = null): ?Card
    {
        $at ??= now();

        foreach (CardQueue::sessionOrder() as $queue) {
            $card = $this->queryFor($user, $deck, $at)
                ->where('queue', $queue->value)
                ->orderBy($this->orderColumnFor($queue))
                ->orderBy('id')
                ->with('note')
                ->first();

            // A card whose note has lost the media its question needs — audio
            // that never generated, say — is skipped rather than shown broken.
            if ($card !== null && $card->isAnswerable()) {
                return $card;
            }
        }

        return null;
    }

    /**
     * How many cards are waiting, broken down by queue.
     *
     * @return array<string, int>
     */
    public function counts(User $user, ?Deck $deck = null, ?Carbon $at = null): array
    {
        $at ??= now();

        $counts = $this->queryFor($user, $deck, $at)
            ->selectRaw('queue, count(*) as total')
            ->groupBy('queue')
            ->pluck('total', 'queue')
            ->all();

        $result = [];

        foreach (CardQueue::sessionOrder() as $queue) {
            $result[$queue->value] = (int) ($counts[$queue->value] ?? 0);
        }

        return $result;
    }

    public function dueCount(User $user, ?Deck $deck = null, ?Carbon $at = null): int
    {
        return array_sum($this->counts($user, $deck, $at));
    }

    /**
     * @return Builder<Card>
     */
    private function queryFor(User $user, ?Deck $deck, Carbon $at): Builder
    {
        return Card::query()
            ->where('user_id', $user->id)
            ->when($deck !== null, fn (Builder $query) => $query->where('deck_id', $deck->id))
            ->due($at);
    }

    /**
     * Within a queue, the card that has been waiting longest goes first. New
     * cards have no meaningful due date, so they go in the order they were
     * added — the order the user built them in.
     */
    private function orderColumnFor(CardQueue $queue): string
    {
        return $queue === CardQueue::New ? 'created_at' : 'next_review_at';
    }
}
