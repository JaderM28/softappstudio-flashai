<?php

namespace App\Services\Media;

use App\Contracts\ImageProvider;
use App\Exceptions\MediaFetchFailed;
use App\Support\FoundImage;
use Illuminate\Support\Collection;
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
        return $this->searchMany($query, 1)->first()
            ?? throw MediaFetchFailed::nothingFound('No image provider', $query);
    }

    /**
     * Fills the grid from the libraries in order, and stops as soon as it is
     * full.
     *
     * Topping up rather than falling through matters both ways round: if
     * Pixabay answers with six, Unsplash is never called and its fifty an hour
     * stay untouched; if it answers with two, the grid is still six rather than
     * two, which is the difference between choosing and taking what you are
     * given.
     *
     * @return \Illuminate\Support\Collection<int, FoundImage>
     */
    public function searchMany(string $query, int $limit = 6): Collection
    {
        $found = collect();
        $seen = [];
        $failures = [];

        foreach ($this->providers as $provider) {
            if ($found->count() >= $limit) {
                break;
            }

            try {
                foreach ($provider->searchMany($query, $limit - $found->count()) as $image) {
                    // The same picture is indexed by more than one library, and
                    // twice in a grid of six is a wasted slot.
                    if (in_array($image->url, $seen, true)) {
                        continue;
                    }

                    $seen[] = $image->url;
                    $found->push($image);
                }
            } catch (MediaFetchFailed $e) {
                $failures[] = $e;

                Log::info('Image provider passed', [
                    'provider' => $provider::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // Every provider declined, so the note is told why by the one that had
        // the most useful opinion. Reporting "no picture matched" when the real
        // cause was an unconfigured key would send someone looking in the wrong
        // place, so a configuration problem wins over a search miss.
        if ($found->isEmpty()) {
            throw $this->mostUseful($failures, $query);
        }

        return $found->values();
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
