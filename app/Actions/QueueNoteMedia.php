<?php

namespace App\Actions;

use App\Enums\AssetStatus;
use App\Jobs\FetchNoteImage;
use App\Jobs\SynthesizeNoteAudio;
use App\Models\Note;

/**
 * Queues the picture and the audio for a note.
 *
 * Both are queued rather than done while the user waits: they take seconds,
 * they hit services with hard rate limits, and neither is needed for the
 * sentence to be studiable.
 */
class QueueNoteMedia
{
    public function handle(Note $note, bool $image = true, bool $audio = true): void
    {
        if ($image && $this->canFetchImages()) {
            $note->update(['image_status' => AssetStatus::Pending]);

            // afterCommit, so the worker cannot pick the job up before the row
            // it is about exists.
            FetchNoteImage::dispatch($note)->afterCommit();
        }

        if ($audio && filled(config('services.gemini.key'))) {
            $note->update(['audio_status' => AssetStatus::Pending]);

            SynthesizeNoteAudio::dispatch($note)->afterCommit();
        }
    }

    /**
     * Openverse needs no key, so pictures are reachable even with nothing
     * configured — but a chain that can only reach its last resort is worth
     * being deliberate about rather than surprised by.
     */
    private function canFetchImages(): bool
    {
        return filled(config('services.pixabay.key'))
            || filled(config('services.unsplash.key'))
            || filled(config('services.openverse.base_url'));
    }
}
