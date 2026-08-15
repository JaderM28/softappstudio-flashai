<?php

namespace App\Services\Media;

use App\Contracts\SpeechSynthesizer;
use App\Support\SynthesizedAudio;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Squeezes a synthesised clip down to MP3.
 *
 * Gemini answers in WAV, which does not compress: about 230 KB a note against
 * about 20 KB for the same speech as MP3. On a laptop that is not worth a
 * paragraph, but the files live in Supabase Storage, whose free tier is a 1 GB
 * bucket — the difference between roughly nineteen months of daily use and
 * roughly four years.
 *
 * It wraps a synthesiser rather than living inside one because it is about
 * where the bytes are going, not where they came from: whatever replaces Gemini
 * the day its preview models stop being free gets the same treatment for free.
 *
 * Compression is the one part of this pipeline allowed to fail silently. A note
 * with a bulky clip is still a note with a clip, and refusing to store audio
 * because it could not be made smaller would be a worse outcome than the
 * problem it solves.
 */
class CompressedSpeechSynthesizer implements SpeechSynthesizer
{
    public function __construct(
        private readonly SpeechSynthesizer $inner,
    ) {}

    public function synthesize(string $text, string $language): SynthesizedAudio
    {
        $audio = $this->inner->synthesize($text, $language);

        if (! config('flashai.media.audio.compress') || $audio->isEmpty() || $audio->extension === 'mp3') {
            return $audio;
        }

        return $this->toMp3($audio) ?? $audio;
    }

    private function toMp3(SynthesizedAudio $audio): ?SynthesizedAudio
    {
        $source = tempnam(sys_get_temp_dir(), 'tts-').'.'.$audio->extension;
        $target = tempnam(sys_get_temp_dir(), 'tts-').'.mp3';

        try {
            file_put_contents($source, $audio->bytes);

            $result = Process::timeout(30)->run([
                config('flashai.media.audio.ffmpeg'),
                '-hide_banner', '-loglevel', 'error',
                '-y',
                '-i', $source,
                '-ac', '1',
                '-b:a', (string) config('flashai.media.audio.bitrate'),
                $target,
            ]);

            if ($result->failed()) {
                return $this->giveUp('ffmpeg refused the clip', $result->errorOutput());
            }

            $bytes = file_get_contents($target);

            // A zero-length result is a failure ffmpeg did not report as one,
            // and storing it would lose the audio entirely.
            if ($bytes === false || $bytes === '') {
                return $this->giveUp('ffmpeg produced an empty file');
            }

            return new SynthesizedAudio($bytes, 'mp3');
        } catch (Throwable $e) {
            // Overwhelmingly: ffmpeg is not installed on this host. That is a
            // supported way to run — the WAV goes to storage as it is.
            return $this->giveUp('ffmpeg could not be run', $e->getMessage());
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    private function giveUp(string $reason, string $detail = ''): null
    {
        Log::info('Audio stored uncompressed', array_filter([
            'reason' => $reason,
            'detail' => mb_substr($detail, 0, 300),
        ]));

        return null;
    }
}
