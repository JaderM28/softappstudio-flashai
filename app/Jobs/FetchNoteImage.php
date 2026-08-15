<?php

namespace App\Jobs;

use App\Contracts\ImageProvider;
use App\Enums\AssetStatus;
use App\Exceptions\MediaFetchFailed;
use App\Models\Note;
use App\Support\FoundImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
        return [(new RateLimited('images'))->dontRelease()];
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

        try {
            $path = $this->store($note, $found);
        } catch (MediaFetchFailed $e) {
            $this->recordFailure($note, $e);

            return;
        }

        $note->update([
            'image_status' => AssetStatus::Ready,
            'image_path' => $path,
            // Kept beside the copy: it is where the credit on the card links
            // back to, and it is what a refetch would compare against.
            'image_url' => $found->url,
            'image_attribution' => $found->attribution(),
            'generation_errors' => $this->withoutError($note, 'image'),
        ]);
    }

    /**
     * @throws MediaFetchFailed
     */
    private function store(Note $note, FoundImage $found): string
    {
        try {
            $response = Http::timeout(20)->get($found->url);
        } catch (ConnectionException $e) {
            throw MediaFetchFailed::unreachable($found->source, $e->getMessage());
        }

        if ($response->failed()) {
            throw MediaFetchFailed::rejected($found->source, $response->status(), '');
        }

        $bytes = $response->body();

        if ($bytes === '') {
            throw MediaFetchFailed::unusableAnswer($found->source, 'the picture downloaded as an empty file');
        }

        $path = "notes/images/{$note->id}.".$this->extensionFor($response->header('Content-Type'), $found->url);

        Storage::disk(config('flashai.media.disk'))->put($path, $bytes);

        return $path;
    }

    /**
     * The content type is what the server actually sent; the URL is a guess for
     * when it sent nothing useful. JPEG is the last resort because every one of
     * these libraries serves mostly JPEG.
     */
    private function extensionFor(?string $contentType, string $url): string
    {
        $known = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

        foreach ($known as $type => $extension) {
            if ($contentType !== null && str_contains(mb_strtolower($contentType), $type)) {
                return $extension;
            }
        }

        $fromUrl = mb_strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return in_array($fromUrl, $known, true) ? $fromUrl : 'jpg';
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
