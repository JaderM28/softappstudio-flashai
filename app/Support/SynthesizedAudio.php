<?php

namespace App\Support;

/**
 * A finished audio clip, in memory, waiting to be stored.
 */
readonly class SynthesizedAudio
{
    public function __construct(
        public string $bytes,
        public string $extension = 'mp3',
    ) {}

    public function isEmpty(): bool
    {
        return $this->bytes === '';
    }
}
