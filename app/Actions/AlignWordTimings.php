<?php

namespace App\Actions;

use App\Support\WordTiming;
use Illuminate\Support\Collection;

/**
 * Matches a transcript's timings to the words of the sentence we actually have.
 *
 * The transcript is not the source of truth — the sentence is. Whisper is only
 * being asked *when* each word is said, and its own idea of *what* was said is
 * checked against ours before any of it is believed.
 *
 * The check is not theoretical. Without the sentence passed as a prompt,
 * "The waiter spilled coffee on the tablecloth." came back as "... on the table
 * cup." — eight words against seven. Mapping by position would have made
 * tapping "tablecloth" play the sound of "cup", which is worse than not
 * offering the feature: a card that teaches the wrong pronunciation is a card
 * doing harm.
 *
 * So this returns nothing at all rather than something plausible. An empty
 * result means the caller falls back to synthesising the word on its own, which
 * always works.
 */
class AlignWordTimings
{
    /**
     * @param  Collection<int, WordTiming>  $transcribed
     * @return Collection<int, WordTiming> the sentence's own words, timed — or empty
     */
    public function handle(Collection $transcribed, string $sentence): Collection
    {
        $spoken = $this->wordsOf($sentence);

        if ($spoken->isEmpty() || $transcribed->count() !== $spoken->count()) {
            return collect();
        }

        $aligned = collect();

        foreach ($spoken as $index => $word) {
            $timing = $transcribed[$index];

            if (WordTiming::normalise($word) !== WordTiming::normalise($timing->word)) {
                return collect();
            }

            // Carrying the sentence's own spelling forward, not the transcript's
            // — they agree on the word, and the sentence is what is on screen.
            $aligned->push(new WordTiming($word, $timing->start, $timing->end));
        }

        return $this->sane($aligned) ? $aligned : collect();
    }

    /**
     * @return Collection<int, string>
     */
    private function wordsOf(string $sentence): Collection
    {
        return collect(preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->filter(fn (string $word) => WordTiming::normalise($word) !== '')
            ->values();
    }

    /**
     * Timings that run backwards, or overlap, or sit at zero length, are not
     * timings — they are a model having a bad moment, and slicing on them would
     * play silence or the wrong word.
     *
     * @param  Collection<int, WordTiming>  $timings
     */
    private function sane(Collection $timings): bool
    {
        $previousEnd = -1.0;

        foreach ($timings as $timing) {
            if ($timing->end <= $timing->start || $timing->start < $previousEnd - 0.01) {
                return false;
            }

            $previousEnd = $timing->end;
        }

        return true;
    }
}
