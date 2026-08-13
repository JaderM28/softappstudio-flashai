<?php

namespace App\Services\Media;

use App\Contracts\SpeechSynthesizer;
use App\Exceptions\MediaFetchFailed;
use App\Support\SynthesizedAudio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GoogleTextToSpeech implements SpeechSynthesizer
{
    private const SERVICE = 'Google TTS';

    public function synthesize(string $text, string $language): SynthesizedAudio
    {
        $key = config('services.google_tts.key');

        if (blank($key)) {
            throw MediaFetchFailed::notConfigured(self::SERVICE);
        }

        if (trim($text) === '') {
            throw MediaFetchFailed::unusableAnswer(self::SERVICE, 'there was nothing to read aloud');
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout((int) config('services.google_tts.timeout'))
                ->retry(2, 500, throw: false)
                ->post(rtrim(config('services.google_tts.base_url'), '/').'/text:synthesize', [
                    'input' => ['text' => $text],
                    'voice' => [
                        'languageCode' => $this->languageCode($language),
                        'ssmlGender' => 'FEMALE',
                    ],
                    'audioConfig' => [
                        'audioEncoding' => 'MP3',
                        // Slightly under natural pace: these are sentences being
                        // learned, not listened to for pleasure.
                        'speakingRate' => (float) config('services.google_tts.speaking_rate'),
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw MediaFetchFailed::unreachable(self::SERVICE, $e->getMessage());
        }

        if ($response->failed()) {
            throw MediaFetchFailed::rejected(self::SERVICE, $response->status(), $response->body());
        }

        $encoded = data_get($response->json(), 'audioContent');

        if (! is_string($encoded) || $encoded === '') {
            throw MediaFetchFailed::unusableAnswer(self::SERVICE, 'the response carried no audio');
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '') {
            throw MediaFetchFailed::unusableAnswer(self::SERVICE, 'the audio was not valid base64');
        }

        return new SynthesizedAudio($bytes);
    }

    /**
     * Decks store a short tag like "en"; the API wants a full locale.
     */
    private function languageCode(string $language): string
    {
        if (str_contains($language, '-')) {
            return $language;
        }

        return match ($language) {
            'en' => 'en-US',
            'es' => 'es-ES',
            'fr' => 'fr-FR',
            'de' => 'de-DE',
            'pt' => 'pt-BR',
            'it' => 'it-IT',
            default => $language.'-'.mb_strtoupper($language),
        };
    }
}
