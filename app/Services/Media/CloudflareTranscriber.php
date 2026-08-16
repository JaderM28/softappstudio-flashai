<?php

namespace App\Services\Media;

use App\Contracts\SpeechTranscriber;
use App\Exceptions\MediaFetchFailed;
use App\Support\WordTiming;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Finds where each word falls inside a clip, using Whisper on Workers AI.
 *
 * The same REST endpoint and the same token as the voice, and almost free:
 * measured at **1.83 neurons** for a five-second sentence, against 10,000 a day.
 * A note pays it once, and every tap on every word of that sentence afterwards
 * costs nothing at all.
 */
class CloudflareTranscriber implements SpeechTranscriber
{
    private const SERVICE = 'Cloudflare transcription';

    public function transcribe(string $audio, string $language, string $knownText): Collection
    {
        $account = config('services.cloudflare.account_id');
        $token = config('services.cloudflare.token');

        if (blank($account) || blank($token)) {
            throw MediaFetchFailed::notConfigured(self::SERVICE);
        }

        try {
            $response = Http::withToken($token)
                ->timeout((int) config('services.cloudflare.transcription.timeout'))
                ->post($this->endpoint($account), [
                    'audio' => base64_encode($audio),
                    'language' => $this->languageTag($language),
                    // The whole reason this is reliable. Told what the clip
                    // says, Whisper stops guessing at compounds: "tablecloth"
                    // came back as "table cup" without it and correctly with it.
                    'initial_prompt' => $knownText,
                ]);
        } catch (ConnectionException $e) {
            throw MediaFetchFailed::unreachable(self::SERVICE, $e->getMessage());
        }

        if ($response->failed()) {
            throw MediaFetchFailed::rejected(
                self::SERVICE,
                $response->status(),
                $response->body(),
                $response->header('Retry-After') ?: null,
            );
        }

        return $this->timingsFrom($response->json());
    }

    /**
     * The timings live inside the segments, not beside them.
     *
     * @param  array<string, mixed>|null  $payload
     * @return Collection<int, WordTiming>
     */
    private function timingsFrom(?array $payload): Collection
    {
        if (data_get($payload, 'success') === false) {
            $errors = collect((array) data_get($payload, 'errors', []))
                ->pluck('message')
                ->filter()
                ->implode('; ');

            throw MediaFetchFailed::unusableAnswer(self::SERVICE, $errors ?: 'the request was rejected');
        }

        $words = collect((array) data_get($payload, 'result.segments', []))
            ->flatMap(fn ($segment) => (array) data_get($segment, 'words', []));

        if ($words->isEmpty()) {
            throw MediaFetchFailed::unusableAnswer(self::SERVICE, 'the reply carried no word timings');
        }

        return $words
            ->map(fn ($word) => new WordTiming(
                word: (string) data_get($word, 'word', ''),
                start: (float) data_get($word, 'start', 0),
                end: (float) data_get($word, 'end', 0),
            ))
            ->values();
    }

    private function endpoint(string $account): string
    {
        return sprintf(
            '%s/accounts/%s/ai/run/%s',
            rtrim((string) config('services.cloudflare.base_url'), '/'),
            $account,
            config('services.cloudflare.transcription.model'),
        );
    }

    /**
     * Whisper wants a bare language code; decks carry BCP-47 tags.
     */
    private function languageTag(string $language): string
    {
        return mb_strtolower(explode('-', $language)[0]);
    }
}
