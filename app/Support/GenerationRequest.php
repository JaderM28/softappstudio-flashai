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
    ) {}

    public static function forDeck(string $input, Deck $deck): self
    {
        return new self(
            input: trim($input),
            targetLanguage: $deck->target_language,
            nativeLanguage: $deck->native_language,
            instructions: $deck->prompt_instructions,
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
        ]));
    }
}
