<?php

namespace App\Http\Controllers;

use App\Actions\CreateNote;
use App\Actions\EnsureDefaultDeck;
use App\Actions\QueueNoteMedia;
use App\Actions\SyncNoteCards;
use App\Enums\AssetStatus;
use App\Http\Requests\StoreNoteRequest;
use App\Http\Requests\UpdateNoteRequest;
use App\Models\Deck;
use App\Models\Note;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NoteController extends Controller
{
    public function __construct(
        private readonly CreateNote $createNote,
        private readonly SyncNoteCards $syncCards,
        private readonly EnsureDefaultDeck $ensureDefaultDeck,
        private readonly QueueNoteMedia $queueMedia,
    ) {}

    /**
     * Ask again for whatever did not come through. Image search runs out of free
     * requests long before the others, so this is the common case rather than
     * an edge one.
     */
    public function retryMedia(Note $note): RedirectResponse
    {
        $this->authorize('update', $note);

        $this->queueMedia->handle(
            $note,
            image: $note->image_status !== AssetStatus::Ready,
            audio: $note->audio_status !== AssetStatus::Ready,
        );

        return back()->with('status', 'Queued again. It will appear once it comes through.');
    }

    public function index(Request $request): View
    {
        $notes = $request->user()->notes()
            ->with(['deck', 'cards'])
            ->when($request->string('q')->isNotEmpty(), function ($query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';

                $query->where(fn ($sub) => $sub
                    ->where('sentence', 'ilike', $term)
                    ->orWhere('target', 'ilike', $term)
                    ->orWhere('meaning', 'ilike', $term));
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('notes.index', [
            'notes' => $notes,
            'query' => $request->string('q')->toString(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('notes.create', [
            'decks' => $this->decksFor($request),
            'selectedDeckId' => $request->integer('deck')
                ?: $this->ensureDefaultDeck->handle($request->user())->id,
            // Without a key the generate box is hidden rather than shown and
            // then failing; typing the sentence yourself still works.
            'generatorAvailable' => filled(config('services.gemini.key')),
        ]);
    }

    public function store(StoreNoteRequest $request): RedirectResponse
    {
        $user = $request->user();
        $deck = $this->resolveDeck($request);

        $note = $this->createNote->handle($user, $deck, $request->safe()->except('deck_id'));

        $message = $note->cards()->count() === 0
            ? 'Sentence saved, but no card could be built from it yet — check that the word appears in the sentence.'
            : 'Sentence saved.';

        return redirect()
            ->route('notes.create', ['deck' => $deck->id])
            ->with('status', $message);
    }

    public function edit(Note $note): View
    {
        $this->authorize('update', $note);

        return view('notes.edit', [
            'note' => $note,
            'decks' => $note->user->decks()->orderBy('name')->get(),
            'selectedDeckId' => $note->deck_id,
        ]);
    }

    public function update(UpdateNoteRequest $request, Note $note): RedirectResponse
    {
        $this->authorize('update', $note);

        $note->fill($request->safe()->except('deck_id'));
        $note->target_lemma = Note::guessLemma($note->target);
        $note->deck_id = $this->resolveDeck($request)->id;

        // Checked before saving, while the original values are still known.
        $audioIsStale = $note->isDirty(['sentence', 'target']);
        $imageIsStale = $note->isDirty('image_query');

        $note->save();

        // Editing can make a card possible that was not before — fixing a typo
        // so the target finally appears in the sentence, for instance.
        $this->syncCards->handle($note->refresh());

        // Rewording the sentence makes the recording wrong, and a wrong
        // recording is worse than none.
        if ($audioIsStale || $imageIsStale) {
            $this->queueMedia->handle($note, image: $imageIsStale, audio: $audioIsStale);
        }

        return redirect()
            ->route('notes.index')
            ->with('status', 'Sentence updated.');
    }

    public function destroy(Note $note): RedirectResponse
    {
        $this->authorize('delete', $note);

        $note->delete();

        return redirect()
            ->route('notes.index')
            ->with('status', 'Sentence deleted.');
    }

    private function resolveDeck(Request $request): Deck
    {
        $deckId = $request->integer('deck_id');

        if ($deckId !== 0) {
            // The request rules already scoped this to the user's own decks.
            return $request->user()->decks()->findOrFail($deckId);
        }

        return $this->ensureDefaultDeck->handle($request->user());
    }

    /**
     * @return Collection<int, Deck>
     */
    private function decksFor(Request $request)
    {
        $this->ensureDefaultDeck->handle($request->user());

        return $request->user()->decks()->orderBy('name')->get();
    }
}
