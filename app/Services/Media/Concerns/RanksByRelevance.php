<?php

namespace App\Services\Media\Concerns;

use Illuminate\Support\Str;

/**
 * Picks the result that actually matches the query, rather than the first one.
 *
 * Stock libraries rank by popularity and downloads, not by fit, so position one
 * is the least reliable slot on the page. Measured against Pixabay with three
 * real queries, four of every five results were relevant and the wrong one was
 * the top hit twice: "umbrella rain street" leads with a night shot of Osaka
 * that happens to carry `rain` and `umbrella` at the end of twenty tags, and
 * the whole sentence "She borrowed my umbrella yesterday" leads with a
 * Christmas dinner table.
 *
 * Scoring on tag overlap fixes both, costs no extra request, and needs no
 * model: the Osaka picture matches two of the three query words while the
 * rainy street matches all three.
 */
trait RanksByRelevance
{
    /**
     * Words that appear in every other caption and would flatten the scoring.
     * Deliberately short — this is a tie-breaker, not a language model.
     *
     * @var array<int, string>
     */
    private array $ignoredWords = [
        'the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'with',
        'for', 'from', 'by', 'is', 'are', 'was', 'were', 'be', 'his', 'her',
        'my', 'your', 'their', 'its', 'it', 'he', 'she', 'they', 'we', 'you',
        'photo', 'image', 'picture', 'stock', 'free', 'nature',
    ];

    /**
     * Sort candidates so the best match is first, keeping the provider's order
     * as the tie-breaker.
     *
     * @template T
     *
     * @param  array<int, T>  $candidates
     * @param  callable(T): ?string  $keywordsOf  the tags or caption to score against
     * @return array<int, T>
     */
    protected function rankByRelevance(array $candidates, string $query, callable $keywordsOf): array
    {
        $wanted = $this->significantWords($query);

        if ($wanted === [] || count($candidates) < 2) {
            return array_values($candidates);
        }

        $scored = [];

        foreach (array_values($candidates) as $position => $candidate) {
            $scored[] = [
                'candidate' => $candidate,
                'score' => $this->overlap($wanted, (string) $keywordsOf($candidate)),
                // Keeps the sort stable: among equally good matches, the
                // provider still knows more than we do about which is better.
                'position' => $position,
            ];
        }

        usort($scored, fn (array $a, array $b) => [$b['score'], $a['position']] <=> [$a['score'], $b['position']]);

        return array_column($scored, 'candidate');
    }

    /**
     * How many of the query's words this candidate's keywords mention.
     */
    private function overlap(array $wanted, string $keywords): int
    {
        $haystack = $this->significantWords($keywords);

        if ($haystack === []) {
            return 0;
        }

        return count(array_intersect($wanted, $haystack));
    }

    /**
     * @return array<int, string>
     */
    private function significantWords(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            // Singular and plural should count as the same word: a picture
            // tagged "umbrellas" answers a query for "umbrella".
            array_map(fn (string $word) => Str::singular($word), $words),
            fn (string $word) => mb_strlen($word) > 2 && ! in_array($word, $this->ignoredWords, true),
        )));
    }
}
