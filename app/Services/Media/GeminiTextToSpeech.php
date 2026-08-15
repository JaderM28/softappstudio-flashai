<?php

namespace App\Services\Media;

use App\Contracts\SpeechSynthesizer;
use App\Exceptions\MediaFetchFailed;
use App\Support\SynthesizedAudio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads sentences aloud using the Gemini API.
 *
 * Google Cloud Text-to-Speech has the more generous free tier and returns MP3
 * ready to play, and it is still not what this uses: Cloud will not enable the
 * API without a billing account with a card attached, whatever the tier. Gemini
 * carries speech on the same free tier, the same endpoint and the same key as
 * the sentence generator, which means no second account and one fewer
 * credential in the project.
 *
 * The cost of that is the audio format. Gemini answers with raw PCM rather than
 * a container, so a WAV header is put in front of it here — see wrapInWav().
 */
class GeminiTextToSpeech implements SpeechSynthesizer
{
    private const SERVICE = 'Gemini speech';

    /**
     * What the model returns, and what the WAV header therefore has to declare.
     * Fixed by the API rather than requested, so they are constants: it answers
     * with audio/L16;codec=pcm;rate=24000, single channel.
     */
    private const SAMPLE_RATE = 24000;

    private const CHANNELS = 1;

    private const BITS_PER_SAMPLE = 16;

    public function synthesize(string $text, string $language): SynthesizedAudio
    {
        $key = config('services.gemini.key');

        if (blank($key)) {
            throw MediaFetchFailed::notConfigured(self::SERVICE);
        }

        if (trim($text) === '') {
            throw MediaFetchFailed::unusableAnswer(self::SERVICE, 'there was nothing to read aloud');
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout((int) config('services.gemini.tts.timeout'))
                ->retry(2, 500, throw: false)
                ->post(
                    rtrim(config('services.gemini.base_url'), '/').'/interactions',
                    $this->body($text, $language),
                );
        } catch (ConnectionException $e) {
            throw MediaFetchFailed::unreachable(self::SERVICE, $e->getMessage());
        }

        if ($response->failed()) {
            throw MediaFetchFailed::rejected(self::SERVICE, $response->status(), $response->body());
        }

        return new SynthesizedAudio(
            bytes: $this->wrapInWav($this->pcmFrom($response->json())),
            extension: 'wav',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $text, string $language): array
    {
        return [
            'model' => config('services.gemini.tts.model'),

            // This API has no speakingRate, so pace is asked for in words. The
            // instruction travels with the text because the model reads the
            // whole input as direction plus content.
            'input' => "Read this aloud in {$this->languageName($language)}, slowly and very clearly, "
                ."for someone learning the language. Read only the text itself:\n\n{$text}",

            'response_format' => ['type' => 'audio'],

            'generation_config' => [
                'speech_config' => [
                    ['voice' => config('services.gemini.tts.voice')],
                ],
            ],
        ];
    }

    /**
     * The clip arrives base64-encoded. The SDKs expose it as `output_audio`,
     * but over REST the same block also sits in the `steps` array the sentence
     * generator reads, so both are checked rather than betting on one.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function pcmFrom(?array $payload): string
    {
        $encoded = data_get($payload, 'output_audio.data')
            ?? $this->audioFromSteps($payload);

        if (! is_string($encoded) || $encoded === '') {
            throw MediaFetchFailed::unusableAnswer(self::SERVICE, 'the response carried no audio');
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '') {
            throw MediaFetchFailed::unusableAnswer(self::SERVICE, 'the audio was not valid base64');
        }

        return $bytes;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function audioFromSteps(?array $payload): ?string
    {
        foreach ((array) data_get($payload, 'steps', []) as $step) {
            $data = data_get($step, 'output_audio.data')
                ?? data_get($step, 'audio.data')
                ?? data_get($step, 'inline_data.data');

            if (is_string($data) && $data !== '') {
                return $data;
            }
        }

        return null;
    }

    /**
     * Raw PCM samples are not playable on their own — nothing in them says how
     * fast to read them back. This is the canonical 44-byte RIFF header that
     * says so, which is all the difference between the two formats.
     */
    private function wrapInWav(string $pcm): string
    {
        $byteRate = self::SAMPLE_RATE * self::CHANNELS * (self::BITS_PER_SAMPLE / 8);
        $blockAlign = self::CHANNELS * (self::BITS_PER_SAMPLE / 8);

        return 'RIFF'
            .pack('V', 36 + strlen($pcm))   // size of everything after this field
            .'WAVE'
            .'fmt '
            .pack('V', 16)                  // length of this format block
            .pack('v', 1)                   // 1 = uncompressed PCM
            .pack('v', self::CHANNELS)
            .pack('V', self::SAMPLE_RATE)
            .pack('V', (int) $byteRate)
            .pack('v', (int) $blockAlign)
            .pack('v', self::BITS_PER_SAMPLE)
            .'data'
            .pack('V', strlen($pcm))
            .$pcm;
    }

    /**
     * The model takes direction in prose, so it wants a language by name rather
     * than the BCP-47 tag a deck stores. An unknown tag is passed through as
     * itself, which the model handles better than a wrong guess would.
     */
    private function languageName(string $language): string
    {
        $tag = mb_strtolower(explode('-', $language)[0]);

        return match ($tag) {
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'pt' => 'Portuguese',
            'it' => 'Italian',
            'nl' => 'Dutch',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'zh' => 'Mandarin Chinese',
            'ru' => 'Russian',
            'ar' => 'Arabic',
            default => $language,
        };
    }
}
