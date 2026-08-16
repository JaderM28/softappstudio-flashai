<?php

namespace App\Services\Media;

use App\Contracts\ImageProvider;
use App\Exceptions\MediaFetchFailed;
use App\Services\Media\Concerns\PicksFirstCandidate;
use App\Support\FoundImage;
use Illuminate\Support\Collection;

/**
 * Stand-in for tests.
 */
class FakeImageProvider implements ImageProvider
{
    private const SERVICE = 'Fake images';

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

    use PicksFirstCandidate;

    /**
     * Deterministic candidates: the same query always produces the same grid,
     * in the same order, so a test can assert on which one was chosen.
     *
     * @return Collection<int, FoundImage>
     */
    public function searchMany(string $query, int $limit = 6): Collection
    {
        $this->queries[] = $query;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return collect(range(1, max(1, $limit)))->map(fn (int $n) => new FoundImage(
            url: 'https://images.pixabay.test/photo-'.md5($n === 1 ? $query : $query.$n).'.jpg',
            photographer: 'Ada Lovelace',
            photographerUrl: 'https://pixabay.com/users/-1/',
            source: 'pixabay',
            description: $query,
            thumbnailUrl: 'https://images.pixabay.test/thumb-'.md5($n === 1 ? $query : $query.$n).'.jpg',
        ));
    }

    public function reportUsage(FoundImage $image): void
    {
        $this->reported[] = $image;
    }
}
