<?php

namespace App\Services\Generation;

use App\Contracts\SentenceGenerator;
use App\Support\GeneratedNote;
use App\Support\GenerationRequest;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers what the model said about a given input.
 *
 * Looking a word up twice is common — you meet it again, or you discard the
 * first draft and try once more — and the free tier is 1,500 calls a day. The
 * fingerprint includes the deck settings, so the same word in two decks with
 * different instructions is generated twice on purpose.
 */
class CachedSentenceGenerator implements SentenceGenerator
{
    public function __construct(
        private readonly SentenceGenerator $inner,
    ) {}

    public function generate(GenerationRequest $request): GeneratedNote
    {
        $key = 'generation:'.$request->fingerprint();
        $ttl = (int) config('services.gemini.cache_ttl');

        if ($ttl <= 0) {
            return $this->inner->generate($request);
        }

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return GeneratedNote::fromArray($cached);
        }

        $note = $this->inner->generate($request);

        // Only successful generations are stored; a failure must be retryable
        // immediately rather than cached as the answer.
        Cache::put($key, $note->toArray(), $ttl);

        return $note;
    }
}
