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

        $counts = [];

        // Counted per queue rather than in one grouped query, because the
        // learning queues are allowed to look further ahead than the rest. A
        // shared horizon would have the counter read zero while the session was
        // still handing out cards.
        foreach (CardQueue::sessionOrder() as $queue) {
            $counts[$queue->value] = $this
                ->baseQuery($user, $deck, $this->horizonFor($queue, $at))
                ->where('queue', $queue->value)
                ->count();
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
     * When the next card falls due, if one is coming.
     *
     * The session screen needs this to tell "you are finished" apart from
     * "come back in seven minutes" — two states that used to look identical
     * and mean very different things.
     */
    public function nextDueAt(User $user, ?Deck $deck = null, ?Carbon $at = null): ?Carbon
    {
        $at ??= now();

        $next = Card::query()
            ->where('user_id', $user->id)
            ->when($deck !== null, fn (Builder $query) => $query->where('deck_id', $deck->id))
            ->whereIn('queue', [CardQueue::Learning->value, CardQueue::Relearning->value, CardQueue::Review->value])
            ->where('next_review_at', '>', $at)
            ->where(fn (Builder $sub) => $sub
                ->whereNull('buried_until')
                ->orWhere('buried_until', '<=', $at))
            ->min('next_review_at');

        return $next === null ? null : Carbon::parse($next);
    }

    /**
     * Cards that exist, are due, and are being held back only by today's
     * allowance.
     *
     * Without this the daily cap is invisible: the session simply stops, and
     * "you have done your quota, thirty-four are waiting for tomorrow" reads
     * exactly like "there is nothing left to study".
     */
    public function heldBackByTodaysCap(User $user, ?Deck $deck = null, ?Carbon $at = null): int
    {
        $at ??= now();

        $reviews = $this->baseQuery($user, $deck, $at)
            ->where('queue', CardQueue::Review->value)
            ->count();

        $newCards = $this->baseQuery($user, $deck, $at)
            ->where('queue', CardQueue::New->value)
            ->count();

        $counts = $this->counts($user, $deck, $at);

        return max(0, $reviews - $counts[CardQueue::Review->value])
            + max(0, $newCards - $counts[CardQueue::New->value]);
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
            $this->baseQuery($user, $deck, $this->horizonFor($queue, $at))->where('queue', $queue->value),
            $queue,
        );
    }

    /**
     * How far ahead this queue is allowed to look.
     *
     * Anki's learn-ahead limit, and the reason it exists is worth stating: a
     * learning step is minutes long, so on a small collection every "Again" or
     * "Hard" empties the session for ten minutes and the screen says there is
     * nothing left to study. The card was never lost; it simply was not due
     * yet, and the session had no way to say so.
     *
     * Reviews never move. Their intervals are measured in days and are the
     * whole point of the algorithm — bringing them forward would make them
     * mean nothing.
     */
    private function horizonFor(CardQueue $queue, Carbon $at): Carbon
    {
        if (! $queue->isIntraday()) {
            return $at;
        }

        $minutes = (int) config('flashai.session.learn_ahead_minutes');

        return $minutes > 0 ? $at->copy()->addMinutes($minutes) : $at;
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
