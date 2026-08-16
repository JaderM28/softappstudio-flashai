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
use Illuminate\Support\Facades\Log;

class UnsplashImageProvider implements ImageProvider
{
    use PicksFirstCandidate;
    use RanksByRelevance;

    private const SERVICE = 'Unsplash';

    public function searchMany(string $query, int $limit = 6): Collection
    {
        $key = config('services.unsplash.key');

        if (blank($key)) {
            throw MediaFetchFailed::notConfigured(self::SERVICE);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Client-ID {$key}",
                'Accept-Version' => 'v1',
            ])
                ->timeout((int) config('services.unsplash.timeout'))
                ->get(rtrim(config('services.unsplash.base_url'), '/').'/search/photos', [
                    'query' => $query,
                    'per_page' => $limit,
                    // Cards are wider than they are tall, so a portrait shot
                    // would be cropped to nothing.
                    'orientation' => 'landscape',
                    'content_filter' => 'high',
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

        $ranked = $this->rankByRelevance(
            (array) data_get($response->json(), 'results', []),
            $query,
            fn (array $hit): ?string => trim(implode(' ', [
                (string) data_get($hit, 'description'),
                (string) data_get($hit, 'alt_description'),
            ])),
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
        $url = data_get($result, 'urls.regular');

        if (! is_string($url) || $url === '') {
            return null;
        }

        return new FoundImage(
            url: $url,
            photographer: (string) (data_get($result, 'user.name') ?? 'Unknown'),
            photographerUrl: (string) (data_get($result, 'user.links.html') ?? 'https://unsplash.com'),
            source: 'unsplash',
            downloadTrackingUrl: data_get($result, 'links.download_location'),
            description: data_get($result, 'description') ?? data_get($result, 'alt_description'),
            thumbnailUrl: data_get($result, 'urls.small'),
        );
    }

    public function reportUsage(FoundImage $image): void
    {
        if (blank($image->downloadTrackingUrl)) {
            return;
        }

        try {
            Http::withHeaders(['Authorization' => 'Client-ID '.config('services.unsplash.key')])
                ->timeout(5)
                ->get($image->downloadTrackingUrl);
        } catch (ConnectionException $e) {
            // Never the reason a card ends up without a picture.
            Log::info('Unsplash usage report failed', ['message' => $e->getMessage()]);
        }
    }
}
