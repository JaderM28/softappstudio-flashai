<?php

namespace App\Jobs;

use App\Contracts\SpeechSynthesizer;
use App\Actions\TimeNoteWords;
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

    /**
     * Attempts are counted generously and bounded by the clock instead.
     *
     * A small count was wrong for both reasons a media job fails. Honouring
     * the provider's "retry in 56s" means a handful of attempts can be spent
     * inside a single minute — the old three were gone before the quota window
     * had even reopened — while a genuinely broken key deserves to stop long
     * before twenty-five tries. retryUntil() is what draws that line: keep
     * trying for a few hours, then leave it in the tray for a person.
     */
    public int $tries = 25;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function __construct(
        public readonly Note $note,
    ) {}

    public function handle(SpeechSynthesizer $speech, TimeNoteWords $timeWords): void
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
        Storage::disk(config('flashai.media.disk'))->put($sentencePath, $sentence->bytes);

        $targetPath = null;

        if ($target !== null && ! $target->isEmpty()) {
            $targetPath = "notes/audio/{$note->id}-target.{$target->extension}";
            Storage::disk(config('flashai.media.disk'))->put($targetPath, $target->bytes);
        }

        $note->update([
            'audio_status' => AssetStatus::Ready,
            'audio_sentence_path' => $sentencePath,
            'audio_target_path' => $targetPath,
            'generation_errors' => $this->withoutError($note, 'audio'),
            // Where each word falls in the clip, so tapping one plays that
            // stretch of it. Best effort by design: the note is finished either
            // way, and a note without timings simply has its words synthesised
            // one at a time instead.
            'word_timings' => $timeWords->handle($note, $sentence->bytes, $language),
        ]);

        // Deliberately no cards here. The clip existing is not the same as
        // the clip being any good, and this app's whole argument is that the
        // media is the card — so a person listens to it before it becomes one.
        // Notes that already have cards are topped up by SyncNoteCards from
        // the compose screen and from NoteController::update.
    }

    private function recordFailure(Note $note, MediaFetchFailed $e): void
    {
        Log::warning('Note audio could not be synthesised', [
            'note_id' => $note->id,
            'message' => $e->getMessage(),
        ]);

        if ($e->isWorthRetrying() && $this->attempts() < $this->tries) {
            $note->update(['audio_status' => AssetStatus::Pending]);

            $this->release($this->retryDelay($e));

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

    /**
     * How long to wait before the next attempt.
     *
     * The provider's own answer wins when it gives one — a 429 from Gemini
     * speech carries "Please retry in 15.5s", and waiting five minutes on top
     * of that is time spent for nothing. Otherwise it climbs, because a service
     * that is down does not want to be asked every minute.
     *
     * The jitter matters more than it looks: five notes added together would
     * otherwise retry in the same second and hit the same limit again.
     */
    private function retryDelay(MediaFetchFailed $e): int
    {
        $steps = [60, 300, 900, 1800, 3600];

        $base = $e->retryAfterSeconds
            ?? ($steps[max(0, $this->attempts() - 1)] ?? end($steps));

        return random_int((int) ceil($base * 0.9), (int) ceil($base * 1.3));
    }

}
