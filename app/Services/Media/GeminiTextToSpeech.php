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
     * What to declare in the WAV header when the answer does not say.
     *
     * The answer normally does say — every audio block carries its own
     * sample_rate and channels — so these are the floor rather than the truth.
     * Bit depth has no field of its own because L16 is sixteen bits by
     * definition; if Google ever returns another codec, the mime type says so
     * and wrapping it as L16 would produce noise, so it is checked below.
     */
    private const DEFAULT_SAMPLE_RATE = 24000;

    private const DEFAULT_CHANNELS = 1;

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
            throw MediaFetchFailed::rejected(
                self::SERVICE,
                $response->status(),
                $response->body(),
                $response->header('Retry-After') ?: null,
            );
        }

        $audio = $this->audioBlockFrom($response->json());

        return new SynthesizedAudio(
            bytes: $this->wrapInWav(
                $this->decode($audio['data']),
                (int) $audio['sample_rate'],
                (int) $audio['channels'],
            ),
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
     * Find the clip in the answer, along with the format it says it is in.
     *
     * Where it actually lives, confirmed against the live API rather than the
     * documentation: `steps[].content[]`, in the entry whose `type` is `audio`,
     * under `data` — beside a `sample_rate`, a `channels` and a `mime_type` of
     * `audio/l16; rate=24000; channels=1`. That is the same `steps` array the
     * sentence generator reads, with an audio block where it has a text one.
     *
     * The documented `output_audio.data` is checked first anyway. Google's own
     * examples show it at the top level, so it may well appear there one day,
     * and keeping the path costs one line.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{data: string, sample_rate: int, channels: int}
     */
    private function audioBlockFrom(?array $payload): array
    {
        foreach ($this->candidateBlocks($payload) as $block) {
            $data = data_get($block, 'data');

            if (! is_string($data) || $data === '') {
                continue;
            }

            // L16 is what the request asks for and all this class can wrap.
            // Anything else would be handed to the browser mislabelled, which
            // is worse than saying plainly that it could not be used.
            $mime = (string) data_get($block, 'mime_type', 'audio/l16');

            if (! str_contains(mb_strtolower($mime), 'l16')) {
                throw MediaFetchFailed::unusableAnswer(
                    self::SERVICE,
                    "the audio came back as {$mime}, which is not the raw PCM this expects"
                );
            }

            return [
                'data' => $data,
                'sample_rate' => (int) (data_get($block, 'sample_rate') ?: self::DEFAULT_SAMPLE_RATE),
                'channels' => (int) (data_get($block, 'channels') ?: self::DEFAULT_CHANNELS),
            ];
        }

        throw MediaFetchFailed::unusableAnswer(self::SERVICE, 'the response carried no audio');
    }

    /**
     * Every place the clip has been known to sit, in the order worth trying.
     *
     * @param  array<string, mixed>|null  $payload
     * @return iterable<int, mixed>
     */
    private function candidateBlocks(?array $payload): iterable
    {
        yield data_get($payload, 'output_audio');

        foreach ((array) data_get($payload, 'steps', []) as $step) {
            foreach ((array) data_get($step, 'content', []) as $content) {
                if (data_get($content, 'type') === 'audio') {
                    yield $content;
                }
            }

            // Older shapes, kept because they cost nothing and an answer that
            // carries audio anywhere at all beats an exception.
            yield data_get($step, 'output_audio');
            yield data_get($step, 'audio');
            yield data_get($step, 'inline_data');
        }
    }

    private function decode(string $encoded): string
    {
        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '') {
            throw MediaFetchFailed::unusableAnswer(self::SERVICE, 'the audio was not valid base64');
        }

        return $bytes;
    }

    /**
     * Raw PCM samples are not playable on their own — nothing in them says how
     * fast to read them back. This is the canonical 44-byte RIFF header that
     * says so, which is all the difference between the two formats.
     */
    private function wrapInWav(string $pcm, int $sampleRate, int $channels): string
    {
        $bytesPerSample = (int) (self::BITS_PER_SAMPLE / 8);
        $byteRate = $sampleRate * $channels * $bytesPerSample;
        $blockAlign = $channels * $bytesPerSample;

        return 'RIFF'
            .pack('V', 36 + strlen($pcm))   // size of everything after this field
            .'WAVE'
            .'fmt '
            .pack('V', 16)                  // length of this format block
            .pack('v', 1)                   // 1 = uncompressed PCM
            .pack('v', $channels)
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
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
