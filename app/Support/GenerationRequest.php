<?php

namespace App\Support;

use App\Models\Deck;

/**
 * What the user typed, plus the deck settings that shape the prompt.
 */
readonly class GenerationRequest
{
    public function __construct(
        public string $input,
        public string $targetLanguage,
        public string $nativeLanguage,
        public ?string $instructions = null,
        /**
         * Words this learner is already studying.
         *
         * Passed so the sentence can be built out of them. The rule the whole
         * design rests on is that a sentence contains exactly one thing you do
         * not know — and until now nothing enforced it, because the model had
         * no idea what you knew. These lemmas are already stored on every note;
         * they were simply never used for anything.
         *
         * @var array<int, string>
         */
        public array $knownWords = [],
    ) {}

    /**
     * @param  array<int, string>  $knownWords
     */
    public static function forDeck(string $input, Deck $deck, array $knownWords = []): self
    {
        return new self(
            input: trim($input),
            targetLanguage: $deck->target_language,
            nativeLanguage: $deck->native_language,
            instructions: $deck->prompt_instructions,
            knownWords: $knownWords,
        );
    }

    /**
     * Whether the user gave a single word or a whole sentence. The model is
     * told which, because the two need different work: one has to have a
     * sentence written around it, the other has its key part picked out.
     */
    public function looksLikeSentence(): bool
    {
        return str_word_count($this->input) > 3;
    }

    /**
     * Identifies a request for caching. Deck settings are part of it because
     * the same word in a medical deck should not reuse a general answer.
     */
    public function fingerprint(): string
    {
        return sha1(implode('|', [
            mb_strtolower($this->input),
            $this->targetLanguage,
            $this->nativeLanguage,
            $this->instructions ?? '',
            // The known words are part of the question, so they have to be part
            // of its identity — otherwise one learner's sentence is served to
            // another whose vocabulary it was never shaped around.
            //
            // It does cost hit rate: the list grows with every note, so the same
            // word looked up next week is a different question. That is the
            // honest answer rather than the convenient one, and the text tier is
            // 1,500 calls a day against a five-a-day habit.
            implode(',', $this->knownWords),
        ]));
    }
}
