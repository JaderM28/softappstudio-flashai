<?php

namespace App\Actions;

use App\Enums\AssetStatus;
use App\Jobs\FetchNoteImage;
use App\Jobs\SynthesizeNoteAudio;
use App\Models\Note;

/**
 * Queues the picture and the audio for a note.
 *
 * Both are queued rather than done while the user waits, and the reason is not
 * only that they take seconds.
 *
 * This runs inside CreateNote's transaction. Doing the work here would hold a
 * Postgres transaction open across network calls — a second when every provider
 * answers, and tens of seconds when the first one hangs and the chain waits out
 * its timeout before trying the next. Queueing keeps the transaction to the
 * width of an INSERT, which is what it should be.
 *
 * The compose screen covers the wait: it shows what has arrived and what has
 * not, and updates itself as each lands.
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

        if ($audio && $this->canSynthesizeSpeech()) {
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

    /**
     * Any voice will do.
     *
     * This asked for the Gemini key alone until Cloudflare became the primary,
     * at which point removing that key would have stopped audio being queued at
     * all — while the service that actually produces it sat there working. The
     * question is whether the chain has anything in it, not whether one
     * particular link is present.
     */
    private function canSynthesizeSpeech(): bool
    {
        return filled(config('services.gemini.key'))
            || (filled(config('services.cloudflare.token')) && filled(config('services.cloudflare.account_id')));
    }
}
