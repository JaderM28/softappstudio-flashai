<?php

namespace App\Services\Media\Concerns;

use App\Exceptions\MediaFetchFailed;
use App\Support\FoundImage;

/**
 * `search()` in terms of `searchMany()`, written once.
 *
 * Every provider answers the same way when asked for a single picture: take the
 * best-ranked candidate, and if there are none, say nothing was found. Keeping
 * it here means adding a provider is one method, and means the two calls can
 * never drift into disagreeing about which picture is the best one.
 */
trait PicksFirstCandidate
{
    public function search(string $query): FoundImage
    {
        return $this->searchMany($query, 1)->first()
            ?? throw MediaFetchFailed::nothingFound(self::SERVICE, $query);
    }
}
