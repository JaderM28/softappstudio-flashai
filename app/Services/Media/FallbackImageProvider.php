<?php

namespace App\Services\Media;

use App\Contracts\ImageProvider;
use App\Exceptions\MediaFetchFailed;
use App\Support\FoundImage;
use Illuminate\Support\Facades\Log;

/**
 * Tries several libraries in order and returns the first picture found.
 *
 * A single provider makes a note's picture depend on one company's quota, one
 * company's index, and one company's afternoon. None of those is worth a card
 * going without an image when three free libraries exist, and the three fail in
 * genuinely different ways: Pixabay runs out of requests, Openverse runs out of
 * relevance, Unsplash runs out of both sooner.
 *
 * It is a decorator rather than a branch inside the job because the job should
 * not know how many providers there are — and because a chain of one is a valid
 * configuration, which is what keeps the tests simple.
 */
class FallbackImageProvider implements ImageProvider
{
    /** @var array<int, ImageProvider> */
    private array $providers;

    public function __construct(ImageProvider ...$providers)
    {
        $this->providers = $providers;
    }

    public function search(string $query): FoundImage
    {
        $failures = [];

        foreach ($this->providers as $provider) {
            try {
                return $provider->search($query);
            } catch (MediaFetchFailed $e) {
                $failures[] = $e;

                Log::info('Image provider passed', [
                    'provider' => $provider::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // Every provider declined, so the note is told why by the last one that
        // had an opinion. Reporting "no picture matched" when the real cause
        // was an unconfigured key would send someone looking in the wrong
        // place, so a configuration problem wins over a search miss.
        throw $this->mostUseful($failures, $query);
    }

    /**
     * Whichever provider supplied the picture is the only one with anything to
     * report, and it is the only one that knows whether it needs to. Passing
     * the call to all of them lets each decide, which is how Unsplash keeps
     * honouring its terms from inside a chain.
     */
    public function reportUsage(FoundImage $image): void
    {
        foreach ($this->providers as $provider) {
            $provider->reportUsage($image);
        }
    }

    /**
     * @param  array<int, MediaFetchFailed>  $failures
     */
    private function mostUseful(array $failures, string $query): MediaFetchFailed
    {
        if ($failures === []) {
            return MediaFetchFailed::nothingFound('No image provider', $query);
        }

        foreach ($failures as $failure) {
            if ($failure->isWorthRetrying()) {
                return $failure;
            }
        }

        return $failures[0];
    }
}
