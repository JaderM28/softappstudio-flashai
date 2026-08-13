<?php

namespace App\Jobs;

use App\Contracts\ImageProvider;
use App\Enums\AssetStatus;
use App\Exceptions\MediaFetchFailed;
use App\Models\Note;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

/**
 * Finds the picture for a note and stores the link to it.
 *
 * The image itself is hotlinked from Unsplash's CDN rather than copied here.
 * Their API guidelines ask for that, and requests for the image do not count
 * against the rate limit — only the search does. So caching a copy would cost
 * disk and break their terms while solving a problem that does not exist.
 *
 * Queued rather than done while the user waits, because the search side allows
 * 50 requests an hour on the free tier and a batch of sentences would burn
 * through that in a minute.
 */
class FetchNoteImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly Note $note,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // When the limiter is out of room the job goes back on the queue rather
        // than burning an attempt, so a big batch drains slowly instead of
        // half-failing.
        return [(new RateLimited('unsplash'))->dontRelease()];
    }

    public function handle(ImageProvider $images): void
    {
        $note = $this->note->fresh();

        if ($note === null) {
            return;
        }

        $query = $note->image_query ?: $note->sentence;

        if (blank($query)) {
            return;
        }

        $note->update(['image_status' => AssetStatus::Processing]);

        try {
            $found = $images->search($query);
        } catch (MediaFetchFailed $e) {
            $this->recordFailure($note, $e);

            return;
        }

        $images->reportUsage($found);

        $note->update([
            'image_status' => AssetStatus::Ready,
            'image_url' => $found->url,
            'image_attribution' => $found->attribution(),
            'generation_errors' => $this->withoutError($note, 'image'),
        ]);
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

            $this->release(now()->addMinutes(10));

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
}
