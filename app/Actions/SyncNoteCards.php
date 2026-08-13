<?php

namespace App\Actions;

use App\Enums\CardType;
use App\Models\Card;
use App\Models\Note;
use App\Support\SchedulingState;
use Illuminate\Support\Collection;

/**
 * Creates the cards a note is currently able to support.
 *
 * Called whenever a note changes, because what it can support changes with it:
 * a hand-typed sentence starts out with only a cloze card, and its listening
 * card appears the moment audio has been generated.
 *
 * Cards are only ever added. Removing one because a note temporarily lost an
 * asset would throw away everything the scheduler has learned about it.
 */
class SyncNoteCards
{
    /**
     * @return Collection<int, Card> the cards that were created
     */
    public function handle(Note $note): Collection
    {
        // Mapped through the model rather than plucked straight from the query:
        // Eloquent applies casts to plucked columns, so this column comes back
        // as CardType instances and comparing it to raw strings silently never
        // matches — which would mean trying to create a card that exists.
        $existing = $note->cards()->get(['id', 'type'])
            ->map(fn (Card $card) => $card->type->value)
            ->all();

        $created = collect();

        foreach ($note->deck->cardTypeEnums as $type) {
            if (in_array($type->value, $existing, true)) {
                continue;
            }

            if (! $note->supports($type)) {
                continue;
            }

            $created->push($this->createCard($note, $type));
        }

        return $created;
    }

    private function createCard(Note $note, CardType $type): Card
    {
        $card = new Card([
            'type' => $type,
            ...SchedulingState::forNewCard()->toArray(),
        ]);

        // Ownership is derived from the note, never from request input.
        $card->note_id = $note->id;
        $card->user_id = $note->user_id;
        $card->deck_id = $note->deck_id;
        $card->save();

        return $card;
    }
}
