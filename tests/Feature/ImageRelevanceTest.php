<?php

namespace Tests\Feature;

use App\Services\Media\PixabayImageProvider;
use App\Support\ImageQuery;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Both halves of "why is the picture not the one we searched for".
 *
 * The fixtures here are not invented: they are the tags Pixabay actually
 * returned for these exact queries, trimmed to the fields the provider reads.
 */
class ImageRelevanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pixabay.key' => 'test-key']);
    }

    /**
     * @param  array<int, string>  $tagsInOrder
     */
    private function pixabayReturns(array $tagsInOrder): void
    {
        Http::fake(['pixabay.com/*' => Http::response([
            'hits' => array_map(fn (string $tags, int $i) => [
                'webformatURL' => "https://pixabay.example/{$i}.jpg",
                'user' => 'Someone',
                'user_id' => $i,
                'tags' => $tags,
            ], $tagsInOrder, array_keys($tagsInOrder)),
        ])]);
    }

    /**
     * Real result for "umbrella rain street": Pixabay leads with a night shot
     * of Osaka that carries `rain` and `umbrella` at the end of twenty tags,
     * and the rainy street it should have picked sits second.
     */
    public function test_the_popular_result_does_not_beat_the_matching_one(): void
    {
        $this->pixabayReturns([
            'japan, osaka, night, asia, landmark, travel, japanese, architecture, city, neon, rain, umbrella',
            'adult, blur, bokeh, city, evening, light, man, outdoors, person, rain, reflection, road, street, umbrella, wet',
        ]);

        $found = (new PixabayImageProvider)->search('umbrella rain street');

        $this->assertSame('https://pixabay.example/1.jpg', $found->url);
        $this->assertStringContainsString('street', (string) $found->description);
    }

    /**
     * Real result for the whole sentence: a Christmas dinner table first, a
     * girl with an umbrella second.
     */
    public function test_an_irrelevant_top_hit_is_passed_over(): void
    {
        $this->pixabayReturns([
            'decoration, christmas, sylvester, to celebrate, table, covered, festival season',
            'girl, rain, raincoat, umbrella, drop',
        ]);

        $found = (new PixabayImageProvider)->search('person holding umbrella rain');

        $this->assertSame('https://pixabay.example/1.jpg', $found->url);
    }

    /**
     * With nothing to tell them apart, the library still knows more than we do.
     */
    public function test_the_providers_own_order_survives_a_tie(): void
    {
        $this->pixabayReturns([
            'umbrella, rain, first',
            'umbrella, rain, second',
        ]);

        $found = (new PixabayImageProvider)->search('umbrella rain');

        $this->assertSame('https://pixabay.example/0.jpg', $found->url);
    }

    public function test_a_plural_tag_answers_a_singular_query(): void
    {
        $this->pixabayReturns([
            'building, sky, city',
            'umbrellas, rain',
        ]);

        $found = (new PixabayImageProvider)->search('umbrella');

        $this->assertSame('https://pixabay.example/1.jpg', $found->url);
    }

    public function test_the_scene_wins_over_anything_guessed(): void
    {
        $this->assertSame(
            'person holding umbrella rain',
            ImageQuery::forNote('person holding umbrella rain', 'She borrowed my umbrella yesterday.', 'borrowed'),
        );
    }

    /**
     * Hand-typed notes have no scene, and the sentence is the worst possible
     * substitute. Three content words, target first, no grammar.
     */
    public function test_a_sentence_is_reduced_to_content_words(): void
    {
        $query = ImageQuery::forNote(null, 'She borrowed my umbrella yesterday.', 'borrowed');

        $this->assertSame('borrowed umbrella', $query);
        $this->assertStringNotContainsString('yesterday', $query);
        $this->assertStringNotContainsString('she', $query);
    }

    public function test_the_guess_never_grows_past_three_words(): void
    {
        $query = ImageQuery::forNote(
            null,
            'The tired postman delivered several heavy parcels to the corner shop.',
            'delivered',
        );

        $this->assertCount(3, explode(' ', $query));
        $this->assertStringStartsWith('delivered', $query);
    }
}
