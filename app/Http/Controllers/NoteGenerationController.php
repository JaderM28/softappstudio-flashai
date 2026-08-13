<?php

namespace App\Http\Controllers;

use App\Actions\EnsureDefaultDeck;
use App\Contracts\SentenceGenerator;
use App\Exceptions\GenerationFailed;
use App\Http\Requests\GenerateNoteRequest;
use App\Support\GenerationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Runs a word or a pasted sentence through the model and hands the result back
 * to the add screen for approval.
 *
 * Synchronous on purpose. Images and audio are queued because they happen after
 * the note exists, but the user is standing here waiting to approve this, so
 * there is nothing to gain by making them poll for it.
 */
class NoteGenerationController extends Controller
{
    public function __construct(
        private readonly SentenceGenerator $generator,
        private readonly EnsureDefaultDeck $ensureDefaultDeck,
    ) {}

    public function __invoke(GenerateNoteRequest $request): RedirectResponse
    {
        $user = $request->user();

        $deckId = $request->integer('deck_id');
        $deck = $deckId !== 0
            ? $user->decks()->findOrFail($deckId)
            : $this->ensureDefaultDeck->handle($user);

        $back = redirect()->route('notes.create', ['deck' => $deck->id]);

        try {
            $generated = $this->generator->generate(
                GenerationRequest::forDeck($request->string('input')->toString(), $deck)
            );
        } catch (GenerationFailed $e) {
            Log::warning('Sentence generation failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            // The technical detail goes to the log; the screen gets something
            // readable that points at typing the sentence by hand.
            return $back
                ->withInput($request->only('input'))
                ->withErrors(['input' => $e->userMessage()]);
        }

        // Nothing is saved yet. The values go back to the form so they can be
        // corrected first — a bad sentence poisons months of reviews, and the
        // model is usually right but occasionally strange.
        return $back
            ->with('generated', $generated->toArray())
            ->with('generatedFrom', $request->string('input')->toString())
            ->with('targetWarning', ! $generated->hasUsableTarget());
    }
}
