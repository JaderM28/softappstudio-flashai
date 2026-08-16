<?php

namespace App\Services\Media;

use App\Contracts\SpeechTranscriber;
use App\Exceptions\MediaFetchFailed;
use App\Support\WordTiming;
use Illuminate\Support\Collection;

/**
 * Stand-in for tests.
 *
 * Times the words of whatever it is told the clip says, evenly and in order —
 * which is what a transcript that agrees with the sentence looks like. Tests
 * that want the disagreement case set it explicitly.
 */
class FakeTranscriber implements SpeechTranscriber
{
    /** @var array<int, array{language: string, text: string}> */
    public array $calls = [];

    private ?MediaFetchFailed $failure = null;

    /** @var Collection<int, WordTiming>|null */
    private ?Collection $answer = null;

    public function willFail(?MediaFetchFailed $failure = null): self
    {
        $this->failure = $failure ?? MediaFetchFailed::rejected('Cloudflare transcription', 429, 'busy');

        return $this;
    }

    /**
     * @param  Collection<int, WordTiming>  $timings
     */
    public function willReturn(Collection $timings): self
    {
        $this->answer = $timings;

        return $this;
    }

    public function transcribe(string $audio, string $language, string $knownText): Collection
    {
        $this->calls[] = ['language' => $language, 'text' => $knownText];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->answer !== null) {
            return $this->answer;
        }

        return collect(preg_split('/\s+/u', trim($knownText), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->values()
            ->map(fn (string $word, int $i) => new WordTiming($word, $i * 0.4, ($i * 0.4) + 0.3));
    }
}
