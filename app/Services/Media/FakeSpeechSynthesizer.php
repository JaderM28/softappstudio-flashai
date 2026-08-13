<?php

namespace App\Services\Media;

use App\Contracts\SpeechSynthesizer;
use App\Exceptions\MediaFetchFailed;
use App\Support\SynthesizedAudio;

/**
 * Stand-in for tests.
 */
class FakeSpeechSynthesizer implements SpeechSynthesizer
{
    /** @var array<int, array{text: string, language: string}> */
    public array $calls = [];

    private ?MediaFetchFailed $failure = null;

    public function willFail(?MediaFetchFailed $failure = null): self
    {
        $this->failure = $failure ?? MediaFetchFailed::rejected('Google TTS', 429, 'quota exceeded');

        return $this;
    }

    public function synthesize(string $text, string $language): SynthesizedAudio
    {
        $this->calls[] = ['text' => $text, 'language' => $language];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        // Not a real MP3, but bytes that differ per input so tests can tell the
        // sentence clip from the target clip.
        return new SynthesizedAudio('ID3'.md5($text.$language));
    }

    /**
     * @return array<int, string>
     */
    public function spokenTexts(): array
    {
        return array_column($this->calls, 'text');
    }
}
