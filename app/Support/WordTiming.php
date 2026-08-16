<?php

namespace App\Support;

/**
 * Where one word sits inside a clip.
 *
 * Stored on the note so that tapping a word can play that stretch of audio the
 * note already has, instead of asking a service for the word on its own. It
 * costs nothing per tap, waits for nothing, and — the reason it is worth the
 * trouble — the word sounds the way it is actually said in that sentence,
 * elisions, stress and all.
 */
readonly class WordTiming
{
    public function __construct(
        public string $word,
        public float $start,
        public float $end,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['word' => $this->word, 'start' => $this->start, 'end' => $this->end];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            word: (string) ($data['word'] ?? ''),
            start: (float) ($data['start'] ?? 0),
            end: (float) ($data['end'] ?? 0),
        );
    }

    /**
     * Words as they are compared, never as they are shown.
     *
     * Transcripts arrive with leading spaces and trailing punctuation that the
     * sentence does not have in the same places, and neither difference means
     * the words disagree.
     */
    public static function normalise(string $word): string
    {
        return mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\']+/u', '', $word) ?? ''));
    }
}
