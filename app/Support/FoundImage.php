<?php

namespace App\Support;

/**
 * A picture the provider offered, before it has been downloaded.
 */
readonly class FoundImage
{
    public function __construct(
        public string $url,
        public string $photographer,
        public string $photographerUrl,
        public string $source = 'unsplash',
        /**
         * Unsplash asks that this be pinged when a picture is actually used, so
         * the photographer's download count reflects reality. Honouring it is a
         * condition of their API terms, not a nicety.
         */
        public ?string $downloadTrackingUrl = null,
        public ?string $description = null,
    ) {}

    /**
     * Stored on the note and shown wherever the picture is, because Unsplash
     * requires the photographer to be credited.
     *
     * @return array<string, string|null>
     */
    public function attribution(): array
    {
        return [
            'photographer' => $this->photographer,
            'profile_url' => $this->photographerUrl,
            'source' => $this->source,
            'description' => $this->description,
        ];
    }
}
