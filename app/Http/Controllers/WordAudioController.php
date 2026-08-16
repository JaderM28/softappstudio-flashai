<?php

namespace App\Http\Controllers;

use App\Actions\SpeakWord;
use App\Exceptions\MediaFetchFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hearing one word from a sentence.
 *
 * The clip is stored by word, so the first person to tap `coffee` pays for it
 * and nobody pays again. That is what makes this affordable to offer on every
 * word of every card rather than only on the target.
 */
class WordAudioController extends Controller
{
    public function __invoke(Request $request, SpeakWord $speak): JsonResponse
    {
        $validated = $request->validate([
            // Long enough for the longest compound anyone studies, short enough
            // that this cannot be used to read a paragraph aloud on our quota.
            'word' => ['required', 'string', 'max:40'],
            'language' => ['required', 'string', 'max:8'],
        ]);

        try {
            return response()->json([
                'url' => $speak->handle($validated['word'], $validated['language']),
            ]);
        } catch (MediaFetchFailed $e) {
            // A word that cannot be spoken is a disappointment, not a failure of
            // the review — the card still works, so this answers rather than
            // throwing a 500 into the middle of a session.
            return response()->json([
                'error' => $e->userMessage(),
            ], 503);
        }
    }
}
