<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turns a sentence into something a stock library can actually search.
 *
 * Stock libraries match on tags, not on meaning, so handing them a whole
 * sentence is close to handing them noise. Measured against Pixabay: searching
 * "She borrowed my umbrella yesterday." puts a Christmas dinner table at the
 * top, because `umbrella` is the only word in it that is anybody's tag.
 *
 * The right query is the scene, and the scene comes from the model — see the
 * `image_query` the generator is asked for. This is the fallback for notes
 * typed by hand, which have no scene: not a good query, but a far better one
 * than the raw sentence, and honest about being a guess.
 */
final class ImageQuery
{
    /**
     * More than this and stock search narrows to nothing rather than getting
     * more precise: adding "street" to "umbrella rain" is what dragged a night
     * shot of Osaka to the top of the results.
     */
    private const MAX_WORDS = 3;

    /**
     * Grammar, not content. A picture is never tagged with any of these.
     *
     * @var array<int, string>
     */
    private const FUNCTION_WORDS = [
        'the', 'a', 'an', 'and', 'or', 'but', 'of', 'in', 'on', 'at', 'to',
        'with', 'for', 'from', 'by', 'as', 'into', 'about', 'over', 'after',
        'before', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'am',
        'do', 'does', 'did', 'have', 'has', 'had', 'will', 'would', 'can',
        'could', 'should', 'may', 'might', 'must', 'i', 'you', 'he', 'she',
        'it', 'we', 'they', 'me', 'him', 'her', 'us', 'them', 'my', 'your',
        'his', 'its', 'our', 'their', 'this', 'that', 'these', 'those',
        'here', 'there', 'when', 'where', 'what', 'who', 'why', 'how',
        'yesterday', 'today', 'tomorrow', 'very', 'just', 'not', 'no',
        'so', 'than', 'then', 'too', 'also', 'again', 'some', 'any', 'all',
    ];

    /**
     * The scene if the note has one, otherwise a guess made from the sentence.
     */
    public static function forNote(?string $scene, ?string $sentence, ?string $target = null): string
    {
        if (filled($scene)) {
            return trim($scene);
        }

        return self::fromSentence((string) $sentence, $target);
    }

    /**
     * Keep the content words, drop the grammar, and stop at three.
     *
     * The target word goes first when there is one: it is the thing being
     * learned, so it is the thing the picture has to show.
     */
    public static function fromSentence(string $sentence, ?string $target = null): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $content = array_values(array_filter(
            $words,
            fn (string $word) => mb_strlen($word) > 2 && ! in_array($word, self::FUNCTION_WORDS, true),
        ));

        if (filled($target)) {
            $targetWord = mb_strtolower(trim($target));

            $content = array_values(array_unique(array_merge(
                [$targetWord],
                array_filter($content, fn (string $word) => Str::singular($word) !== Str::singular($targetWord)),
            )));
        }

        return implode(' ', array_slice($content, 0, self::MAX_WORDS));
    }
}
