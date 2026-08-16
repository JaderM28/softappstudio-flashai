<?php

namespace App\Services\Media;

use App\Contracts\ImageProvider;
use App\Exceptions\MediaFetchFailed;
use App\Services\Media\Concerns\PicksFirstCandidate;
use App\Services\Media\Concerns\RanksByRelevance;
use App\Support\FoundImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * The first library tried for a picture.
 *
 * Unsplash was never the expensive option — it is free and needs no card. It is
 * the narrow one: 50 requests an hour, and raising that means an approval
 * process a personal project does not pass. Pixabay allows 100 requests a
 * minute with no such process, and carries illustrations and vectors as well as
 * photographs, which for vocabulary are often the better card — a drawing of
 * "running" has none of the background a photograph of a runner has.
 *
 * Unlike Unsplash it forbids permanent hotlinking: their URLs may be used to
 * display search results, not to serve pictures from inside an app. So the
 * picture is downloaded and stored, which is why FetchNoteImage has a download
 * step at all.
 */
class PixabayImageProvider implements ImageProvider
{
    use PicksFirstCandidate;
    use RanksByRelevance;

    /**
     * Their API requires at least three, and asking for more costs the same one
     * request against the hourly limit.
     */
    private const MINIMUM_PER_PAGE = 3;

    private const SERVICE = 'Pixabay';

    public function searchMany(string $query, int $limit = 6): Collection
    {
        $key = config('services.pixabay.key');

        if (blank($key)) {
            throw MediaFetchFailed::notConfigured(self::SERVICE);
        }

        try {
            $response = Http::timeout((int) config('services.pixabay.timeout'))
                ->get(rtrim(config('services.pixabay.base_url'), '/').'/', [
                    'key' => $key,
                    'q' => $query,
                    'per_page' => max(self::MINIMUM_PER_PAGE, $limit),
                    // Cards are wider than they are tall, so a portrait shot
                    // would be cropped to nothing.
                    'orientation' => 'horizontal',
                    'safesearch' => 'true',
                    // Photos and illustrations both, ranked by their own
                    // relevance — "all" is what makes the drawings reachable.
                    'image_type' => 'all',
                ]);
        } catch (ConnectionException $e) {
            throw MediaFetchFailed::unreachable(self::SERVICE, $e->getMessage());
        }

        if ($response->failed()) {
            throw MediaFetchFailed::rejected(
                self::SERVICE,
                $response->status(),
                $response->body(),
                $response->header('Retry-After') ?: null,
            );
        }

        // Not in the order they arrive: Pixabay ranks by popularity, not by
        // fit. See RanksByRelevance for the real queries that made this
        // necessary — the top hit was wrong twice out of three.
        $ranked = $this->rankByRelevance(
            (array) data_get($response->json(), 'hits', []),
            $query,
            fn (array $hit): ?string => data_get($hit, 'tags'),
        );

        return collect($ranked)
            ->map(fn (array $hit): ?FoundImage => $this->toFoundImage($hit))
            ->filter()
            ->take($limit)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function toFoundImage(array $result): ?FoundImage
    {
        // webformatURL is the 640px variant. A flashcard is shown a few hundred
        // pixels wide, so storing the full-size original would be paying for
        // pixels nobody sees — and the bucket it lands in is metered.
        $url = data_get($result, 'webformatURL') ?? data_get($result, 'largeImageURL');

        // A hit with no usable URL is dropped rather than fatal: the other five
        // are still perfectly good answers.
        if (! is_string($url) || $url === '') {
            return null;
        }

        return new FoundImage(
            url: $url,
            photographer: (string) (data_get($result, 'user') ?? 'Unknown'),
            photographerUrl: $this->profileUrl($result),
            source: 'pixabay',
            description: $this->describe($result),
            thumbnailUrl: data_get($result, 'previewURL'),
        );
    }

    /**
     * Pixabay does not require attribution the way Unsplash does, and asks to
     * be credited anyway. Crediting it costs nothing and the note has a field
     * for it already.
     */
    public function reportUsage(FoundImage $image): void
    {
        //
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function profileUrl(array $result): string
    {
        $id = data_get($result, 'user_id');

        return $id === null
            ? 'https://pixabay.com'
            : "https://pixabay.com/users/-{$id}/";
    }

    /**
     * Their tags are a comma-separated string, which is close enough to the
     * alt text the other providers return.
     */
    private function describe(array $result): ?string
    {
        $tags = data_get($result, 'tags');

        return is_string($tags) && $tags !== '' ? $tags : null;
    }
}
