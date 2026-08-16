<?php

namespace App\Enums;

/**
 * The question a card asks about its note. One sentence produces one card of
 * each type the deck has switched on, and each is scheduled on its own.
 */
enum CardType: string
{
    /** Image and audio, sentence with the target blanked out. You produce the word. */
    case Cloze = 'cloze';

    /** Audio only, no text. Reveals the sentence and image. */
    case Listening = 'listening';

    /** Image and a hint. You produce the whole sentence. */
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Cloze => 'Fill in the blank',
            self::Listening => 'Listening',
            self::Production => 'Say the sentence',
        };
    }

    /**
     * Types generated for a new deck. Production is deliberately left off: it
     * is the type that makes sessions long, and it is worth adding only once
     * the daily load of the other two is known.
     *
     * @return array<int, self>
     */
    public static function defaults(): array
    {
        return [self::Cloze, self::Listening];
    }

    /**
     * A card is useless without the media its question is built from, so this
     * is what the review queue checks before offering it.
     *
     * @return array<int, string>
     */
    public function requiredNoteAttributes(): array
    {
        return match ($this) {
            self::Cloze => ['sentence', 'target'],
            self::Listening => ['sentence', 'audio_sentence_path'],
            self::Production => ['sentence', 'meaning'],
        };
    }

    /**
     * What a card additionally needs before it is worth studying at all.
     *
     * The picture is the definition — that is the whole argument for this app
     * over a word list — and the audio is what teaches the word's shape in the
     * ear rather than only on the page. A cloze card can technically be asked
     * without either; it is just a worse flashcard than the one the deck was
     * created to produce, so decks require both by default.
     *
     * Kept apart from requiredNoteAttributes() because these are a deck's
     * policy and those are the question's mechanics: a listening card without
     * audio cannot be asked at all, whatever anyone prefers.
     *
     * @return array<int, string>
     */
    public function requiredMediaAttributes(): array
    {
        return match ($this) {
            self::Cloze, self::Production => ['image_path', 'audio_sentence_path'],
            // Its own mechanics already demand the audio, and a listening card
            // reveals with the picture rather than asking with it.
            self::Listening => ['image_path'],
        };
    }
}
