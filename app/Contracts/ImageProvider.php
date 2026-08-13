<?php

namespace App\Contracts;

use App\Exceptions\MediaFetchFailed;
use App\Support\FoundImage;

interface ImageProvider
{
    /**
     * Find one picture for a scene description.
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
