<?php

namespace App\Services;

use App\Enums\CardQueue;
use App\Models\Card;
use App\Models\Deck;
use App\Models\User;
use App\Support\ReviewDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Decides what to study next, and how much of it.
 *
 * Queues are served in a fixed order: cards being drilled through the learning
 * steps first, because they are time-sensitive and were promised back in
 * minutes, then reviews, then new material. Never new cards first — that
 * front-loads the hardest work onto someone who has not warmed up, and leaves
 * the reviews they actually owe until the end.
 */
class ReviewQueue
{
    public function nextCard(User $user, ?Deck $deck = null, ?Carbon $at = null): ?Card
    {
        $at ??= now();

        foreach (CardQueue::sessionOrder() as $queue) {
            $card = $this->cardFrom($queue, $user, $deck, $at);

            if ($card !== null) {
                return $card;
            }
        }

        return null;
    }

    /**
     * How many cards are waiting in each queue, after the daily allowances
     * have been applied — so the number on screen is what the session will
     * actually serve, not what exists.
     *
     * @return array<string, int>
     */
    public function counts(User $user, ?Deck $deck = null, ?Carbon $at = null): array
    {
        $at ??= now();

        $raw = $this->baseQuery($user, $deck, $at)
            ->selectRaw('queue, count(*) as total')
            ->groupBy('queue')
            ->pluck('total', 'queue')
            ->all();

        $counts = [];

        foreach (CardQueue::sessionOrder() as $queue) {
            $counts[$queue->value] = (int) ($raw[$queue->value] ?? 0);
        }

        $counts[CardQueue::Review->value] = min(
            $counts[CardQueue::Review->value],
            $this->reviewsRemaining($user, $deck, $at),
        );

        $counts[CardQueue::New->value] = min(
            $counts[CardQueue::New->value],
            $this->newCardsAvailable($user, $deck, $at),
        );

        return $counts;
    }

    public function dueCount(User $user, ?Deck $deck = null, ?Carbon $at = null): int
    {
        return array_sum($this->counts($user, $deck, $at));
    }

    /**
     * How many more sentences may be started today.
     */
    public function newSentencesRemaining(User $user, ?Deck $deck = null, ?Carbon $at = null): int
    {
        $limit = $this->limitFor($user, $deck, 'newPerDay', 'flashai.session.new_per_day');

        return max(0, $limit - $this->sentencesStartedToday($user, $deck, $at));
    }

    public function reviewsRemaining(User $user, ?Deck $deck = null, ?Carbon $at = null): int
    {
        $limit = $this->limitFor($user, $deck, 'reviewsPerDay', 'flashai.session.reviews_per_day');

        return max(0, $limit - $this->reviewsDoneToday($user, $deck, $at));
    }

    /**
     * Sentences whose very first review happened today. Counted per sentence
     * rather than per card because that is the unit the user thinks in — one
     * sentence becoming two cards should cost one of the day's allowance, not
     * two.
     */
    public function sentencesStartedToday(User $user, ?Deck $deck = null, ?Carbon $at = null): int
    {
        $dayStart = ReviewDay::start($at ?? now());

        $firstReviews = DB::table('card_reviews')
            ->join('cards', 'cards.id', '=', 'card_reviews.card_id')
            ->where('cards.user_id', $user->id)
            ->when($deck !== null, fn ($query) => $query->where('cards.deck_id', $deck->id))
            ->groupBy('cards.note_id')
            ->havingRaw('min(card_reviews.reviewed_at) >= ?', [$dayStart])
            ->select('cards.note_id');

        return DB::query()->fromSub($firstReviews, 'started')->count();
    }

    /**
     * Only graduated cards count against the review ceiling. Learning steps are
     * work already committed to minutes ago, and holding them back would leave
     * a card half-learned until tomorrow.
     */
    public function reviewsDoneToday(User $user, ?Deck $deck = null, ?Carbon $at = null): int
    {
        return DB::table('card_reviews')
            ->join('cards', 'cards.id', '=', 'card_reviews.card_id')
            ->where('cards.user_id', $user->id)
            ->when($deck !== null, fn ($query) => $query->where('cards.deck_id', $deck->id))
            ->where('card_reviews.queue_before', CardQueue::Review->value)
            ->where('card_reviews.reviewed_at', '>=', ReviewDay::start($at ?? now()))
            ->count();
    }

    private function cardFrom(CardQueue $queue, User $user, ?Deck $deck, Carbon $at): ?Card
    {
        if ($queue === CardQueue::Review && $this->reviewsRemaining($user, $deck, $at) < 1) {
            return null;
        }

        if ($queue === CardQueue::New) {
            return $this->nextNewCard($user, $deck, $at);
        }

        return $this->firstAnswerable(
            $this->baseQuery($user, $deck, $at)->where('queue', $queue->value),
            $queue,
        );
    }

    /**
     * A sentence already started keeps going regardless of the daily
     * allowance — its remaining cards are follow-ups, not new material, and
     * blocking them would leave the sentence half-learned.
     */
    private function nextNewCard(User $user, ?Deck $deck, Carbon $at): ?Card
    {
        $started = $this->firstAnswerable(
            $this->baseQuery($user, $deck, $at)
                ->where('queue', CardQueue::New->value)
                ->whereHas('note.cards.reviews'),
            CardQueue::New,
        );

        if ($started !== null) {
            return $started;
        }

        if ($this->newSentencesRemaining($user, $deck, $at) < 1) {
            return null;
        }

        return $this->firstAnswerable(
            $this->baseQuery($user, $deck, $at)->where('queue', CardQueue::New->value),
            CardQueue::New,
        );
    }

    /**
     * How many fresh sentences the session may still introduce.
     */
    private function newCardsAvailable(User $user, ?Deck $deck, Carbon $at): int
    {
        return $this->newSentencesRemaining($user, $deck, $at);
    }

    /**
     * @param  Builder<Card>  $query
     */
    private function firstAnswerable(Builder $query, CardQueue $queue): ?Card
    {
        $candidates = $query
            ->orderBy($queue === CardQueue::New ? 'created_at' : 'next_review_at')
            ->orderBy('id')
            ->with('note')
            ->limit(20)
            ->get();

        // A card whose note has lost the media its question needs — audio that
        // never generated, say — is skipped rather than shown broken.
        return $candidates->first(fn (Card $card) => $card->isAnswerable());
    }

    /**
     * @return Builder<Card>
     */
    private function baseQuery(User $user, ?Deck $deck, Carbon $at): Builder
    {
        return Card::query()
            ->where('user_id', $user->id)
            ->when($deck !== null, fn (Builder $query) => $query->where('deck_id', $deck->id))
            ->due($at);
    }

    /**
     * Deck settings win; otherwise the global default. Without a deck the
     * strictest of the user's decks would be arbitrary, so the global value
     * stands for the whole collection.
     */
    private function limitFor(User $user, ?Deck $deck, string $method, string $configKey): int
    {
        return $deck !== null
            ? $deck->{$method}()
            : (int) config($configKey);
    }
}
