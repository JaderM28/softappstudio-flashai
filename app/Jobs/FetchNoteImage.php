<?php

namespace App\Jobs;

use App\Actions\StoreNoteImage;
use App\Enums\AssetStatus;
use App\Exceptions\MediaFetchFailed;
use App\Models\Note;
use App\Services\Media\ImageCandidates;
use App\Support\ImageQuery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

/**
 * Finds the picture for a note and stores a copy of it.
 *
 * An earlier version hotlinked the picture from Unsplash's CDN, which their
 * guidelines ask for. Pixabay, which is now tried first, asks for the opposite:
 * their URLs may be used to display search results but not to serve pictures
 * from inside an app, so the file has to be downloaded and kept.
 *
 * Which is the better design regardless. A stored copy keeps working when a
 * photographer deletes an upload, and it is the difference between a PWA that
 * shows its cards on the underground and one that shows broken images there.
 *
 * Queued rather than done while the user waits, because it is two network round
 * trips against services with hard rate limits, and because a note without its
 * picture yet is still perfectly studiable.
 */
class FetchNoteImage implements ShouldQueue
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

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // Without dontRelease(), a job that finds the limiter full is put back
        // on the queue to be tried when it reopens. With it, the job is thrown
        // away silently — which is what this used to do, directly against what
        // the comment here claimed, so a batch large enough to hit the limit
        // lost every note past the ceiling and reported nothing.
        //
        // Releasing costs an attempt, which is why $tries is 25 and bounded by
        // retryUntil() rather than by a small count.
        return [new RateLimited('images')];
    }

    public function handle(ImageCandidates $candidates, StoreNoteImage $store): void
    {
        $note = $this->note->fresh();

        if ($note === null) {
            return;
        }

        // Never the raw sentence: stock libraries search tags, and a sentence
        // is mostly words nothing is tagged with. See ImageQuery.
        $query = ImageQuery::forNote($note->image_query, $note->sentence, $note->target);

        if (blank($query)) {
            return;
        }

        $note->update(['image_status' => AssetStatus::Processing]);

        try {
            // The whole grid is fetched and remembered, not just the winner:
            // the same one request answers "show me a picture" and "show me the
            // others", so changing your mind on the compose screen is free.
            $found = $candidates->refresh($note, $query);

            $store->handle(
                $note,
                $found->first() ?? throw MediaFetchFailed::nothingFound('No image provider', $query),
            );
        } catch (MediaFetchFailed $e) {
            $this->recordFailure($note, $e);
        }
    }


    private function recordFailure(Note $note, MediaFetchFailed $e): void
    {
        Log::warning('Note image could not be fetched', [
            'note_id' => $note->id,
            'message' => $e->getMessage(),
        ]);

        // A quota resets, a bad key does not — only the first is worth another
        // attempt, and releasing lets the queue's backoff handle the waiting.
        if ($e->isWorthRetrying() && $this->attempts() < $this->tries) {
            $note->update(['image_status' => AssetStatus::Pending]);

            $this->release($this->retryDelay($e));

            return;
        }

        $note->update([
            'image_status' => AssetStatus::Failed,
            'generation_errors' => [
                ...($note->generation_errors ?? []),
                'image' => $e->userMessage(),
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
