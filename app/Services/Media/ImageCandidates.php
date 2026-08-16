<?php

namespace App\Services\Media;

use App\Contracts\ImageProvider;
use App\Models\Note;
use App\Support\FoundImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The pictures offered for a note, held server-side while the user decides.
 *
 * They are cached rather than put in the page for one reason that matters and
 * one that is merely nice. The reason that matters: the browser sends back an
 * index, never a URL. A URL in a form field is a URL the server can be told to
 * fetch, which is a request-forgery hole with a picture drawn on it.
 *
 * The nice one: Pixabay's terms ask that search results be cached for 24 hours,
 * which this does for free, and going back to change your mind costs no request
 * at all.
 */
class ImageCandidates
{
    /** How many fit a grid without turning the choice into a chore. */
    public const LIMIT = 6;

    public function __construct(private readonly ImageProvider $images) {}

    /**
     * Search and remember. Replaces whatever was offered before, because the
     * user asking again means the earlier set was not good enough.
     *
     * @return Collection<int, FoundImage>
     */
    public function refresh(Note $note, string $query): Collection
    {
        $found = $this->images->searchMany($query, self::LIMIT);

        Cache::put(
            $this->key($note),
            $found->map(fn (FoundImage $image) => $image->toArray())->all(),
            now()->addDay(),
        );

        return $found;
    }

    /**
     * @return Collection<int, FoundImage>
     */
    public function for(Note $note): Collection
    {
        return collect(Cache::get($this->key($note), []))
            ->map(fn (array $data) => FoundImage::fromArray($data));
    }

    public function at(Note $note, int $index): ?FoundImage
    {
        return $this->for($note)->get($index);
    }

    public function forget(Note $note): void
    {
        Cache::forget($this->key($note));
    }

    private function key(Note $note): string
    {
        return "note-image-candidates:{$note->getKey()}";
    }
}
