<?php

namespace App\Actions;

use App\Contracts\ImageProvider;
use App\Enums\AssetStatus;
use App\Exceptions\MediaFetchFailed;
use App\Models\Note;
use App\Support\FoundImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads a chosen picture, stores it, and records it on the note.
 *
 * Shared by the job that picks the best candidate on its own and the screen
 * where the user picks a different one, because both do exactly the same work
 * and the second one must not become a slightly different version of the first.
 *
 * Pixabay's terms are the reason the file is copied rather than linked: their
 * URLs may be used to show search results and not to serve pictures from inside
 * an app. Storing it also means a card keeps its picture when a photographer
 * deletes an upload, and that a review works on a train.
 */
class StoreNoteImage
{
    public function __construct(private readonly ImageProvider $images) {}

    /**
     * @throws MediaFetchFailed
     */
    public function handle(Note $note, FoundImage $found): Note
    {
        // Unsplash asks to be told when a picture is actually used, and this is
        // that moment — not the moment it appeared in a grid of six.
        $this->images->reportUsage($found);

        [$bytes, $contentType] = $this->download($found);

        $path = "notes/images/{$note->id}.".$this->extensionFor($contentType, $found->url);

        Storage::disk(config('flashai.media.disk'))->put($path, $bytes);

        $note->update([
            'image_status' => AssetStatus::Ready,
            'image_path' => $path,
            // Kept beside the copy: it is where the credit on the card links
            // back to, and it is what a refetch would compare against.
            'image_url' => $found->url,
            'image_attribution' => $found->attribution(),
            'generation_errors' => collect($note->generation_errors ?? [])
                ->except('image')
                ->all() ?: null,
        ]);

        return $note->refresh();
    }

    /**
     * @return array{0: string, 1: ?string} the bytes, and what the server said they are
     *
     * @throws MediaFetchFailed
     */
    private function download(FoundImage $found): array
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

        return [$bytes, $response->header('Content-Type')];
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
}
