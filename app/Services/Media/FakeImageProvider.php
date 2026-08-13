<?php

namespace App\Services\Media;

use App\Contracts\ImageProvider;
use App\Exceptions\MediaFetchFailed;
use App\Support\FoundImage;

/**
 * Stand-in for tests.
 */
class FakeImageProvider implements ImageProvider
{
    /** @var array<int, string> */
    public array $queries = [];

    /** @var array<int, FoundImage> */
    public array $reported = [];

    private ?MediaFetchFailed $failure = null;

    public function willFail(?MediaFetchFailed $failure = null): self
    {
        $this->failure = $failure ?? MediaFetchFailed::rejected('Unsplash', 429, 'Rate Limit Exceeded');

        return $this;
    }

    public function search(string $query): FoundImage
    {
        $this->queries[] = $query;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new FoundImage(
            url: 'https://images.unsplash.test/photo-'.md5($query),
            photographer: 'Ada Lovelace',
            photographerUrl: 'https://unsplash.com/@ada',
            source: 'unsplash',
            downloadTrackingUrl: 'https://api.unsplash.test/photos/x/download',
            description: $query,
        );
    }

    public function reportUsage(FoundImage $image): void
    {
        $this->reported[] = $image;
    }
}
