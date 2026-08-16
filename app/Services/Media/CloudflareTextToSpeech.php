<?php

namespace App\Services\Media;

use App\Contracts\SpeechSynthesizer;
use App\Exceptions\MediaFetchFailed;
use App\Support\SynthesizedAudio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads sentences aloud using Cloudflare Workers AI.
 *
 * The primary, on measurements rather than preference. Against Gemini's speech
 * tier, timed on the same sentence:
 *
 *  - one second against six;
 *  - an MP3 already encoded, against raw PCM that needs a WAV header built by
 *    hand and then ffmpeg to compress it;
 *  - about 140 notes a day inside the free tier, against a limit that ten
 *    requests in a short window were enough to exhaust.
 *
 * It also needs no Worker deployed: Workers AI has a REST endpoint, so this is
 * an ordinary POST like every other client in this project.
 *
 * Gemini stays behind it in the chain. Two providers with unrelated quotas is
 * the whole reason SpeechSynthesizer is an interface.
 */
class CloudflareTextToSpeech implements SpeechSynthesizer
{
    private const SERVICE = 'Cloudflare speech';

    public function synthesize(string $text, string $language): SynthesizedAudio
    {
        $account = config('services.cloudflare.account_id');
        $token = config('services.cloudflare.token');

        if (blank($account) || blank($token)) {
            throw MediaFetchFailed::notConfigured(self::SERVICE);
        }

        if (trim($text) === '') {
            throw MediaFetchFailed::unusableAnswer(self::SERVICE, 'there was nothing to read aloud');
        }

        try {
            $response = Http::withToken($token)
                ->timeout((int) config('services.cloudflare.tts.timeout'))
                ->retry(2, 500, throw: false)
                ->post($this->endpoint($account), [
                    'text' => $text,
                    'speaker' => config('services.cloudflare.tts.voice'),
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

        return new SynthesizedAudio(
            bytes: $this->audioFrom($response),
            extension: 'mp3',
        );
    }

    private function endpoint(string $account): string
    {
        return sprintf(
            '%s/accounts/%s/ai/run/%s',
            rtrim((string) config('services.cloudflare.base_url'), '/'),
            $account,
            config('services.cloudflare.tts.model'),
        );
    }

    /**
     * The audio is the body itself, not a field inside a JSON envelope.
     *
     * Worth checking rather than trusting: every other Cloudflare endpoint
     * answers with `{"success": ..., "result": ...}`, and an error from this
     * one arrives in that shape too — with a 200 in front of it in at least
     * some failure modes. A JSON content type here means something went wrong
     * however the status line reads.
     */
    private function audioFrom(\Illuminate\Http\Client\Response $response): string
    {
        $contentType = mb_strtolower((string) $response->header('Content-Type'));

        if (str_contains($contentType, 'json')) {
            $errors = collect((array) data_get($response->json(), 'errors', []))
                ->pluck('message')
                ->filter()
                ->implode('; ');

            throw MediaFetchFailed::unusableAnswer(
                self::SERVICE,
                'it answered with JSON instead of audio'.($errors === '' ? '' : ": {$errors}"),
            );
        }

        $bytes = $response->body();

        if ($bytes === '') {
            throw MediaFetchFailed::unusableAnswer(self::SERVICE, 'the clip came back empty');
        }

        return $bytes;
    }
}
