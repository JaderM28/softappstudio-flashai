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
        $this->failure = $failure ?? MediaFetchFailed::rejected('Pixabay', 429, 'Rate Limit Exceeded');

        return $this;
    }

    public function search(string $query): FoundImage
    {
        $this->queries[] = $query;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new FoundImage(
            url: 'https://images.pixabay.test/photo-'.md5($query).'.jpg',
            photographer: 'Ada Lovelace',
            photographerUrl: 'https://pixabay.com/users/-1/',
            source: 'pixabay',
            description: $query,
        );
    }

    public function reportUsage(FoundImage $image): void
    {
        $this->reported[] = $image;
    }
}
