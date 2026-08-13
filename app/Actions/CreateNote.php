<?php

namespace App\Actions;

use App\Models\Deck;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stores a sentence and creates the cards that can be asked about it.
 */
class CreateNote
{
    public function __construct(
        private readonly SyncNoteCards $syncCards,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $user, Deck $deck, array $attributes): Note
    {
        return DB::transaction(function () use ($user, $deck, $attributes): Note {
            $note = new Note($attributes);

            // Fall back to a guessed dictionary form so the duplicate warning
            // works on hand-typed sentences too; the AI supplies it properly.
            $note->target_lemma = $attributes['target_lemma']
                ?? Note::guessLemma($attributes['target'] ?? '');

            $note->user_id = $user->id;
            $note->deck_id = $deck->id;
            $note->save();

            $this->syncCards->handle($note);

            return $note->fresh();
        });
    }
}
