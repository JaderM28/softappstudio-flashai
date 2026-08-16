<?php

namespace App\Actions;

use App\Contracts\SpeechTranscriber;
use App\Models\Note;
use App\Support\WordTiming;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Works out where each word of a sentence falls inside its clip.
 *
 * Best effort, always. Timings make tapping a word instant and free, and their
 * absence costs nothing at all — the word is synthesised on its own instead,
 * which is the path that always works. So nothing here is allowed to fail the
 * audio job: the clip is already stored and the note is already finished by the
 * time this runs.
 */
class TimeNoteWords
{
    public function __construct(
        private readonly SpeechTranscriber $transcriber,
        private readonly AlignWordTimings $align,
    ) {}

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function handle(Note $note, string $audio, string $language): ?array
    {
        try {
            $timings = $this->align->handle(
                $this->transcriber->transcribe($audio, $language, $note->sentence),
                $note->sentence,
            );
        } catch (Throwable $e) {
            Log::info('Word timings skipped', ['note_id' => $note->id, 'message' => $e->getMessage()]);

            return null;
        }

        if ($timings->isEmpty()) {
            // The transcript disagreed with the sentence, so the timings cannot
            // be trusted to land on the right words. Saying nothing is the only
            // safe answer: a tap that plays the wrong word teaches the wrong
            // pronunciation, which is worse than not offering the feature.
            Log::info('Word timings did not line up with the sentence', ['note_id' => $note->id]);

            return null;
        }

        return $timings->map(fn (WordTiming $timing) => $timing->toArray())->all();
    }
}
