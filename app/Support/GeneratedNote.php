<?php

namespace App\Support;

/**
 * What the model came back with, before the user has approved it.
 */
readonly class GeneratedNote
{
    public function __construct(
        public string $sentence,
        public string $target,
        public ?string $targetLemma = null,
        public ?string $meaning = null,
        public ?string $translation = null,
        public ?string $pronunciation = null,
        public ?string $imageQuery = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            sentence: trim((string) ($payload['sentence'] ?? '')),
            target: trim((string) ($payload['target'] ?? '')),
            targetLemma: static::clean($payload['target_lemma'] ?? null),
            meaning: static::clean($payload['meaning'] ?? null),
            translation: static::clean($payload['translation'] ?? null),
            pronunciation: static::clean($payload['pronunciation'] ?? null),
            imageQuery: static::clean($payload['image_query'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sentence' => $this->sentence,
            'target' => $this->target,
            'target_lemma' => $this->targetLemma,
            'meaning' => $this->meaning,
            'translation' => $this->translation,
            'pronunciation' => $this->pronunciation,
            'image_query' => $this->imageQuery,
        ];
    }

    /**
     * Whether a cloze card can be built from this, which is the one thing the
     * model can return valid-looking JSON for and still get wrong: a target in
     * dictionary form when the sentence uses an inflected one.
     */
    public function hasUsableTarget(): bool
    {
        if ($this->target === '') {
            return false;
        }

        return preg_match('/\b'.preg_quote($this->target, '/').'\b/iu', $this->sentence) === 1;
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
