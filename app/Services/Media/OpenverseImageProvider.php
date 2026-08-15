<?php

namespace App\Services\Media;

use App\Contracts\ImageProvider;
use App\Exceptions\MediaFetchFailed;
use App\Support\FoundImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The middle link in the chain, and the reason the chain is worth having.
 *
 * Openverse is not a curated library but an index over 800M openly licensed
 * works from Flickr, Wikimedia and elsewhere. That makes it worse than Pixabay
 * for a common scene — the results are messier — and much better for the
 * uncommon one a stock library never had a reason to stock. Which is exactly
 * the job of a fallback.
 *
 * It needs no API key. A token raises the rate limit but is not required, so
 * this is the one provider in the chain that cannot be misconfigured.
 */
class OpenverseImageProvider implements ImageProvider
{
    private const SERVICE = 'Openverse';

    public function search(string $query): FoundImage
    {
        try {
            $response = Http::withHeaders(['Accept' => 'application/json'])
                ->timeout((int) config('services.openverse.timeout'))
                ->get(rtrim(config('services.openverse.base_url'), '/').'/images/', [
                    'q' => $query,
                    'page_size' => 3,
                    'aspect_ratio' => 'wide',
                    'mature' => 'false',
                    // Licences that allow use without asking. Openverse indexes
                    // stricter ones too, and a card is not worth a licence
                    // question later.
                    'license_type' => 'all-cc',
                ]);
        } catch (ConnectionException $e) {
            throw MediaFetchFailed::unreachable(self::SERVICE, $e->getMessage());
        }

        if ($response->failed()) {
            throw MediaFetchFailed::rejected(self::SERVICE, $response->status(), $response->body());
        }

        $result = data_get($response->json(), 'results.0');

        if (! is_array($result)) {
            throw MediaFetchFailed::nothingFound(self::SERVICE, $query);
        }

        // thumbnail is Openverse's own cached copy and is both smaller and far
        // likelier to answer than the original, which may be a dead link on
        // whichever site it was indexed from years ago.
        $url = data_get($result, 'thumbnail') ?? data_get($result, 'url');

        if (! is_string($url) || $url === '') {
            throw MediaFetchFailed::unusableAnswer(self::SERVICE, 'the result had no image URL');
        }

        return new FoundImage(
            url: $url,
            photographer: (string) (data_get($result, 'creator') ?? 'Unknown'),
            photographerUrl: (string) (
                data_get($result, 'creator_url')
                ?? data_get($result, 'foreign_landing_url')
                ?? 'https://openverse.org'
            ),
            source: 'openverse',
            description: $this->describe($result),
        );
    }

    public function reportUsage(FoundImage $image): void
    {
        //
    }

    /**
     * Results carry their own Creative Commons terms, and unlike the other two
     * providers those differ per picture. The licence travels in the
     * description so the credit shown on the card can be honest about it.
     *
     * @param  array<string, mixed>  $result
     */
    private function describe(array $result): ?string
    {
        $title = data_get($result, 'title');
        $licence = data_get($result, 'license');

        $parts = array_filter([
            is_string($title) && $title !== '' ? $title : null,
            is_string($licence) && $licence !== '' ? 'CC '.mb_strtoupper($licence) : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
