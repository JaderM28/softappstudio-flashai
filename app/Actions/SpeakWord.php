<?php

namespace App\Actions;

use App\Contracts\SpeechSynthesizer;
use App\Exceptions\MediaFetchFailed;
use Illuminate\Support\Facades\Storage;

/**
 * The sound of one word, made once and kept.
 *
 * Storage is by word rather than by card, and that is the whole economy of the
 * feature: `coffee` is `coffee` in every sentence that contains it, and in
 * everybody's. A clip is paid for the first time anyone taps that word and
 * never again — which is what makes tapping cheap enough to do freely.
 *
 * At roughly ten neurons for a short word against ten thousand a day, the first
 * tap of a thousand different words would still fit inside one day's free tier.
 */
class SpeakWord
{
    public function __construct(private readonly SpeechSynthesizer $speech) {}

    /**
     * The public URL of this word's clip, generating it if nobody has asked
     * before.
     *
     * @throws MediaFetchFailed
     */
    public function handle(string $word, string $language): string
    {
        $path = $this->pathFor($word, $language);
        $disk = Storage::disk(config('flashai.media.disk'));

        if (! $disk->exists($path)) {
            $audio = $this->speech->synthesize($this->clean($word), $language);

            $disk->put($path, $audio->bytes);
        }

        return $disk->url($path);
    }

    /**
     * Keyed by word, language and voice.
     *
     * The voice belongs in the key: changing it in config would otherwise leave
     * every stored word in the old voice and every new one in the new, and a
     * learner would hear the sentence in one voice and its words in another.
     */
    private function pathFor(string $word, string $language): string
    {
        $voice = (string) config('services.cloudflare.tts.voice');

        return sprintf(
            'words/%s/%s/%s.mp3',
            preg_replace('/[^a-z0-9-]/', '', mb_strtolower($language)) ?: 'xx',
            preg_replace('/[^a-z0-9_-]/', '', mb_strtolower($voice)) ?: 'default',
            sha1($this->clean($word)),
        );
    }

    /**
     * Punctuation is not part of the word, and a trailing full stop would make
     * `coffee.` a different cache entry from `coffee` — and read aloud with a
     * falling intonation that the word does not have on its own.
     */
    private function clean(string $word): string
    {
        return trim(preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', trim($word)) ?? '');
    }
}
