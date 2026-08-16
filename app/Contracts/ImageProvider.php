<?php

namespace App\Contracts;

use App\Exceptions\MediaFetchFailed;
use App\Support\FoundImage;
use Illuminate\Support\Collection;

interface ImageProvider
{
    /**
     * Find pictures for a scene description, best match first.
     *
     * Several rather than one because "the right picture" is a judgement the
     * person studying makes, not the search engine: measured against Pixabay,
     * four of every five results were relevant and the top hit was wrong twice
     * out of three queries. Ranking helps; choosing is what settles it.
     *
     * @return Collection<int, FoundImage>
     *
     * @throws MediaFetchFailed
     */
    public function searchMany(string $query, int $limit = 6): Collection;

    /**
     * The best single match, for the paths that cannot ask — a background
     * refetch, a diagnostic probe.
     *
     * @throws MediaFetchFailed
     */
    public function search(string $query): FoundImage;

    /**
     * Report that a picture is being used.
     *
     * Unsplash asks for this so photographers' download counts reflect
     * reality, and honouring it is a condition of their API terms. It must
     * never be the reason a card ends up without a picture, so implementations
     * swallow their own failures.
     */
    public function reportUsage(FoundImage $image): void;
}
