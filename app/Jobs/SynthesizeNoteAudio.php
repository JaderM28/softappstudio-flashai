<?php

namespace App\Jobs;

use App\Actions\SyncNoteCards;
use App\Contracts\SpeechSynthesizer;
use App\Enums\AssetStatus;
use App\Exceptions\MediaFetchFailed;
use App\Models\Note;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Records the sentence and the target word.
 *
 * Generated once when the note is created and stored, never per review — a
 * review session must never depend on an external service being up.
 */
class SynthesizeNoteAudio implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly Note $note,
    ) {}

    public function handle(SpeechSynthesizer $speech, SyncNoteCards $syncCards): void
    {
        $note = $this->note->fresh();

        if ($note === null || blank($note->sentence)) {
            return;
        }

        $note->update(['audio_status' => AssetStatus::Processing]);

        $language = $note->deck->target_language;

        try {
            $sentence = $speech->synthesize($note->sentence, $language);

            // The word on its own as well: hearing it in isolation is what
            // makes it recognisable inside a sentence spoken at speed.
            $target = blank($note->target)
                ? null
                : $speech->synthesize($note->target, $language);
        } catch (MediaFetchFailed $e) {
            $this->recordFailure($note, $e);

            return;
        }

        $sentencePath = "notes/audio/{$note->id}-sentence.{$sentence->extension}";
        Storage::disk('public')->put($sentencePath, $sentence->bytes);

        $targetPath = null;

        if ($target !== null && ! $target->isEmpty()) {
            $targetPath = "notes/audio/{$note->id}-target.{$target->extension}";
            Storage::disk('public')->put($targetPath, $target->bytes);
        }

        $note->update([
            'audio_status' => AssetStatus::Ready,
            'audio_sentence_path' => $sentencePath,
            'audio_target_path' => $targetPath,
            'generation_errors' => $this->withoutError($note, 'audio'),
        ]);

        // The listening card could not exist until now, so this is the moment
        // it appears.
        $syncCards->handle($note->refresh());
    }

    private function recordFailure(Note $note, MediaFetchFailed $e): void
    {
        Log::warning('Note audio could not be synthesised', [
            'note_id' => $note->id,
            'message' => $e->getMessage(),
        ]);

        if ($e->isWorthRetrying() && $this->attempts() < $this->tries) {
            $note->update(['audio_status' => AssetStatus::Pending]);

            $this->release(now()->addMinutes(5));

            return;
        }

        $note->update([
            'audio_status' => AssetStatus::Failed,
            'generation_errors' => [
                ...($note->generation_errors ?? []),
                'audio' => $e->userMessage(),
            ],
        ]);
    }

    /**
     * @return array<string, string>|null
     */
    private function withoutError(Note $note, string $asset): ?array
    {
        $errors = $note->generation_errors ?? [];

        unset($errors[$asset]);

        return $errors === [] ? null : $errors;
    }
}
