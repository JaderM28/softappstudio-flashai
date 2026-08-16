<?php

namespace App\Contracts;

use App\Exceptions\MediaFetchFailed;
use App\Support\WordTiming;
use Illuminate\Support\Collection;

interface SpeechTranscriber
{
    /**
     * Find where each word falls inside a clip.
     *
     * @param  string  $audio  the bytes of the clip, as stored
     * @param  string  $knownText  what the clip says, which we already know
     *                             because we asked for it to be said. Passing it
     *                             is not a nicety: without it, "tablecloth" came
     *                             back as "table cup" — eight words where the
     *                             sentence has seven — and any timing mapped by
     *                             position would have landed on the wrong word.
     * @return Collection<int, WordTiming>
     *
     * @throws MediaFetchFailed
     */
    public function transcribe(string $audio, string $language, string $knownText): Collection;
}
