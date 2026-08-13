<?php

namespace App\Contracts;

use App\Exceptions\MediaFetchFailed;
use App\Support\SynthesizedAudio;

interface SpeechSynthesizer
{
    /**
     * Read a piece of text aloud.
     *
     * @param  string  $language  BCP-47 tag, e.g. "en"
     *
     * @throws MediaFetchFailed
     */
    public function synthesize(string $text, string $language): SynthesizedAudio;
}
