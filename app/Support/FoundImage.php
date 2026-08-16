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
        /**
         * A small copy for the chooser grid. Six full-size pictures on one
         * screen is megabytes of download to answer a question that a
         * thumbnail answers just as well.
         */
        public ?string $thumbnailUrl = null,
    ) {}

    /**
     * What to show in the grid. Falls back to the real thing, because a
     * provider without thumbnails is not a provider without pictures.
     */
    public function thumbnail(): string
    {
        return $this->thumbnailUrl ?? $this->url;
    }

    /**
     * Identifies a candidate across a request, so the browser can say which one
     * was chosen without ever sending back a URL. A URL from the client is a
     * URL the server would fetch on command.
     */
    public function fingerprint(): string
    {
        return substr(sha1($this->url), 0, 12);
    }

    /**
     * Plain data, for the cache.
     *
     * Candidates are held between requests while someone decides, and storing
     * serialised objects there ties a day's worth of cache entries to this
     * class's exact shape — add a field and every one of them comes back as an
     * incomplete object. Arrays go stale gracefully.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'photographer' => $this->photographer,
            'photographer_url' => $this->photographerUrl,
            'source' => $this->source,
            'download_tracking_url' => $this->downloadTrackingUrl,
            'description' => $this->description,
            'thumbnail_url' => $this->thumbnailUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            url: (string) ($data['url'] ?? ''),
            photographer: (string) ($data['photographer'] ?? 'Unknown'),
            photographerUrl: (string) ($data['photographer_url'] ?? ''),
            source: (string) ($data['source'] ?? 'unknown'),
            downloadTrackingUrl: $data['download_tracking_url'] ?? null,
            description: $data['description'] ?? null,
            thumbnailUrl: $data['thumbnail_url'] ?? null,
        );
    }

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
