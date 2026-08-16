<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Shrinks an uploaded picture to the size a card actually shows.
 *
 * A flashcard is a few hundred pixels wide, and the bucket these land in is a
 * metered gigabyte — storing a 12-megapixel phone photo is paying four times
 * over for pixels nobody sees. 640px matches what the stock libraries hand back
 * for the same reason.
 *
 * It uses ffmpeg, which is already in the image for the audio, rather than
 * adding an image library and its extension for one call. And it degrades the
 * same way the audio compressor does: no ffmpeg means the original is stored as
 * it is, which is bounded by the upload validation anyway.
 */
class ImageTranscoder
{
    private const MAX_WIDTH = 640;

    /**
     * @return array{0: string, 1: string} the bytes to store, and their extension
     */
    public function toCardSize(string $bytes, string $extension): array
    {
        $binary = (string) config('flashai.media.audio.ffmpeg');

        if ($binary === '') {
            return [$bytes, $extension];
        }

        $source = tempnam(sys_get_temp_dir(), 'img-');
        $target = tempnam(sys_get_temp_dir(), 'img-').'.jpg';

        try {
            file_put_contents($source, $bytes);

            $result = Process::timeout(30)->run([
                $binary,
                '-hide_banner', '-loglevel', 'error',
                '-y',
                '-i', $source,
                // min() rather than a fixed width so a small picture is never
                // blown up; -2 keeps the aspect ratio on an even number of
                // pixels, which the encoder requires.
                '-vf', "scale='min(".self::MAX_WIDTH.",iw)':-2",
                '-q:v', '3',
                $target,
            ]);

            if (! $result->successful() || ! is_file($target) || filesize($target) === 0) {
                return $this->giveUp($bytes, $extension, $result->errorOutput());
            }

            return [(string) file_get_contents($target), 'jpg'];
        } catch (Throwable $e) {
            return $this->giveUp($bytes, $extension, $e->getMessage());
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function giveUp(string $bytes, string $extension, string $reason): array
    {
        Log::info('Uploaded picture stored at its original size', ['reason' => $reason]);

        return [$bytes, $extension];
    }
}
