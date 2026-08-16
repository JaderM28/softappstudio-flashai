<?php

namespace App\Actions;

use App\Models\Deck;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stores a sentence as a draft and sends its picture and clip off to be made.
 *
 * It creates no cards. A card exists once its media does and the user has seen
 * it, which is the compose screen's job — the sentence, meanwhile, is safe from
 * the moment it is typed no matter what any external service does.
 */
class CreateNote
{
    public function __construct(
        private readonly QueueNoteMedia $queueMedia,
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

            // No cards yet, on purpose. The sentence is saved the moment it is
            // typed — it is never lost, whatever the services do — but it
            // becomes a card only once its picture and its clip exist and have
            // been looked at and listened to. That approval happens on the
            // compose screen; see NoteCompositionController.
            $this->queueMedia->handle($note);

            return $note->fresh();
        });
    }
}
