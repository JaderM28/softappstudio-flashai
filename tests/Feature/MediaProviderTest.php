<?php

namespace Tests\Feature;

use App\Contracts\ImageProvider;
use App\Exceptions\MediaFetchFailed;
use App\Services\Media\FakeImageProvider;
use App\Services\Media\FallbackImageProvider;
use App\Services\Media\GeminiTextToSpeech;
use App\Services\Media\OpenverseImageProvider;
use App\Services\Media\PixabayImageProvider;
use App\Services\Media\UnsplashImageProvider;
use App\Support\FoundImage;
use Tests\Fixtures\Fixture;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The clients that talk to the free media services, and the chain that puts
 * the three image libraries in order.
 */
class MediaProviderTest extends TestCase
{
    // ------------------------------------------------------------- pixabay

    public function test_pixabay_returns_the_display_sized_picture(): void
    {
        config(['services.pixabay.key' => 'test-key']);

        Http::fake(['pixabay.com/*' => Fixture::response('pixabay-search')]);

        $found = (new PixabayImageProvider)->search('umbrella rain street');

        // The 640px variant, not the original: a card is shown a few hundred
        // pixels wide and the bucket it lands in is metered.
        $this->assertStringContainsString('_640', $found->url);
        $this->assertSame('pixabay', $found->source);
        $this->assertNotSame('Unknown', $found->photographer);
        $this->assertStringStartsWith('https://pixabay.com/users/-', $found->photographerUrl);
    }

    public function test_pixabay_without_a_key_says_so_rather_than_asking(): void
    {
        config(['services.pixabay.key' => null]);
        Http::fake();

        $this->expectException(MediaFetchFailed::class);

        (new PixabayImageProvider)->search('anything');

        Http::assertNothingSent();
    }

    public function test_pixabay_reports_an_empty_result_as_nothing_found(): void
    {
        config(['services.pixabay.key' => 'test-key']);
        Http::fake(['pixabay.com/*' => Http::response(Fixture::jsonWith('pixabay-search', ['hits' => []]))]);

        $this->expectExceptionMessage('Pixabay found nothing');

        (new PixabayImageProvider)->search('a scene nobody photographed');
    }

    // ----------------------------------------------------------- openverse

    /**
     * The one provider in the chain that cannot be misconfigured, which is why
     * it sits above Unsplash rather than below it.
     */
    public function test_openverse_needs_no_key(): void
    {
        Http::fake(['api.openverse.org/*' => Fixture::response('openverse-search')]);

        $found = (new OpenverseImageProvider)->search('umbrella rain street');

        // Their cached thumbnail, not the original, which may be a dead link on
        // whichever site it was indexed from years ago.
        $this->assertStringStartsWith('https://api.openverse.org/', $found->url);
        $this->assertSame('openverse', $found->source);

        // Licences differ per picture here, unlike the other two, so the credit
        // shown on the card has to be able to say which one.
        $this->assertStringContainsString('CC ', (string) $found->description);
    }

    // --------------------------------------------------------- the chain

    public function test_the_chain_moves_on_when_a_library_declines(): void
    {
        $exhausted = (new FakeImageProvider)->willFail(
            MediaFetchFailed::rejected('Pixabay', 429, 'rate limited')
        );
        $answers = new FakeImageProvider;

        $found = (new FallbackImageProvider($exhausted, $answers))->search('umbrella rain street');

        $this->assertSame('pixabay', $found->source);
        $this->assertSame(['umbrella rain street'], $answers->queries);
    }

    public function test_the_chain_stops_at_the_first_library_that_answers(): void
    {
        $first = new FakeImageProvider;
        $second = new FakeImageProvider;

        (new FallbackImageProvider($first, $second))->search('umbrella rain street');

        $this->assertCount(1, $first->queries);
        $this->assertSame([], $second->queries);
    }

    /**
     * "No picture matched this sentence" when the real cause was an
     * unconfigured key sends someone looking in the wrong place.
     */
    public function test_the_chain_surfaces_the_failure_worth_acting_on(): void
    {
        $missing = (new FakeImageProvider)->willFail(MediaFetchFailed::notConfigured('Pixabay'));
        $rateLimited = (new FakeImageProvider)->willFail(
            MediaFetchFailed::rejected('Unsplash', 429, 'rate limited')
        );

        try {
            (new FallbackImageProvider($missing, $rateLimited))->search('anything');
            $this->fail('The chain should have thrown once every library declined.');
        } catch (MediaFetchFailed $e) {
            // The transient one, because it is the one that will fix itself and
            // therefore the one worth another attempt.
            $this->assertTrue($e->isWorthRetrying());
        }
    }

    /**
     * Unsplash requires it, the other two do not, and only the provider that
     * supplied the picture knows which case it is in.
     */
    public function test_the_chain_passes_usage_reports_to_every_library(): void
    {
        $first = new FakeImageProvider;
        $second = new FakeImageProvider;
        $image = new FoundImage('https://x.test/a.jpg', 'Ada', 'https://x.test/ada', 'pixabay');

        (new FallbackImageProvider($first, $second))->reportUsage($image);

        $this->assertCount(1, $first->reported);
        $this->assertCount(1, $second->reported);
    }

    public function test_the_chain_is_what_the_container_hands_out(): void
    {
        $this->assertInstanceOf(FallbackImageProvider::class, app(ImageProvider::class));
    }

    // ---------------------------------------------------------------- tts

    /**
     * Gemini answers with raw PCM, which nothing can play: there is nothing in
     * the samples saying how fast to read them back. The header is what makes
     * the bytes a file.
     */
    public function test_speech_comes_back_as_playable_wav(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake(['*/interactions' => Fixture::response('gemini-tts-interaction')]);

        $audio = (new GeminiTextToSpeech)->synthesize('Hola', 'es');

        $this->assertSame('wav', $audio->extension);
        $this->assertStringStartsWith('RIFF', $audio->bytes);
        $this->assertSame('WAVE', substr($audio->bytes, 8, 4));

        // What the header has to declare for the clip to play at the right
        // pitch, read from the answer itself rather than assumed: the recorded
        // reply says 24 kHz, mono, and L16 means sixteen bits.
        $this->assertSame(1, unpack('v', substr($audio->bytes, 22, 2))[1]);
        $this->assertSame(24000, unpack('V', substr($audio->bytes, 24, 4))[1]);
        $this->assertSame(16, unpack('v', substr($audio->bytes, 34, 2))[1]);

        // 44 bytes of header in front of every sample that arrived.
        $expected = strlen(base64_decode(
            Fixture::json('gemini-tts-interaction')['steps'][0]['content'][0]['data'], true
        ));
        $this->assertSame(44 + $expected, strlen($audio->bytes));
    }

    /**
     * The test this project did not have, and the reason it shipped a speech
     * client that produced nothing for weeks.
     *
     * Google's documentation shows the clip at `output_audio.data`. The live
     * API puts it under `steps[].content[]` with `type: "audio"`. The old fake
     * asserted the documented shape, so it passed while nothing worked. This
     * asserts against the recorded reply instead: re-record it after an API
     * change and if the audio has moved, this fails here rather than in
     * production.
     */
    public function test_the_recorded_gemini_reply_still_carries_audio_where_we_read_it(): void
    {
        $payload = Fixture::json('gemini-tts-interaction');

        $block = $payload['steps'][0]['content'][0];

        $this->assertSame('audio', $block['type']);
        $this->assertStringContainsString('l16', strtolower($block['mime_type']));
        $this->assertSame(24000, $block['sample_rate']);
        $this->assertSame(1, $block['channels']);

        $pcm = base64_decode($block['data'], true);

        $this->assertNotFalse($pcm, 'The recorded audio is not valid base64.');
        $this->assertGreaterThan(0, strlen($pcm));
        $this->assertSame(0, strlen($pcm) % 2, '16-bit PCM cannot have an odd byte count.');
    }

    /**
     * The same endpoint and the same key as the sentence generator — the reason
     * this is Gemini and not Google Cloud Text-to-Speech.
     */
    public function test_speech_uses_the_interactions_endpoint(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['*' => Fixture::response('gemini-tts-interaction')]);

        (new GeminiTextToSpeech)->synthesize('Hola', 'es');

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/interactions')
                && $request['response_format']['type'] === 'audio'
                && $request->hasHeader('x-goog-api-key', 'test-key')
                // Direction travels with the text, since this API has no
                // speakingRate to set.
                && str_contains($request['input'], 'Spanish')
                && str_contains($request['input'], 'Hola');
        });
    }

    /**
     * The documented shape is still read first, so the day Google aligns the
     * REST answer with its own examples nothing here has to change.
     */
    public function test_speech_still_reads_the_documented_top_level_field(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake(['*' => Http::response([
            'output_audio' => ['data' => base64_encode(str_repeat("\x01\x00", 100))],
        ])]);

        $audio = (new GeminiTextToSpeech)->synthesize('Hello', 'en');

        $this->assertStringStartsWith('RIFF', $audio->bytes);
    }

    /**
     * Wrapping something that is not raw PCM in a WAV header would hand the
     * browser a mislabelled file, which is worse than saying it cannot be used.
     */
    public function test_speech_refuses_a_format_it_cannot_wrap(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake(['*' => Http::response(Fixture::jsonWith('gemini-tts-interaction', [
            'steps.0.content.0.mime_type' => 'audio/mpeg',
        ]))]);

        $this->expectExceptionMessage('not the raw PCM this expects');

        (new GeminiTextToSpeech)->synthesize('Hello', 'en');
    }

    public function test_speech_without_audio_in_the_reply_is_an_error_not_an_empty_file(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['*' => Http::response(['steps' => []])]);

        $this->expectExceptionMessage('carried no audio');

        (new GeminiTextToSpeech)->synthesize('Hello', 'en');
    }

    public function test_nothing_is_spoken_without_a_key(): void
    {
        config(['services.gemini.key' => null]);
        Http::fake();

        $this->expectException(MediaFetchFailed::class);

        (new GeminiTextToSpeech)->synthesize('Hello', 'en');
    }

    // ------------------------------------------------------------ unsplash

    public function test_unsplash_returns_the_regular_variant_and_its_credit(): void
    {
        config(['services.unsplash.key' => 'test-key']);

        Http::fake(['api.unsplash.com/*' => Fixture::response('unsplash-search')]);

        $found = (new UnsplashImageProvider)->search('umbrella rain street');

        $this->assertStringStartsWith('https://images.unsplash.com/', $found->url);
        $this->assertSame('unsplash', $found->source);
        $this->assertNotSame('Unknown', $found->photographer);

        // Their terms require the download to be reported when a picture is
        // used, so the URL that does it has to survive the mapping.
        $this->assertNotNull($found->downloadTrackingUrl);
    }

    public function test_unsplash_identifies_itself_the_way_its_api_requires(): void
    {
        config(['services.unsplash.key' => 'test-key']);
        Http::fake(['api.unsplash.com/*' => Fixture::response('unsplash-search')]);

        (new UnsplashImageProvider)->search('umbrella');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Client-ID test-key')
            && $request->hasHeader('Accept-Version', 'v1'));
    }

    public function test_unsplash_without_a_key_never_reaches_the_network(): void
    {
        config(['services.unsplash.key' => null]);
        Http::fake();

        try {
            (new UnsplashImageProvider)->search('anything');
            $this->fail('A missing key should have been reported.');
        } catch (MediaFetchFailed $e) {
            $this->assertStringContainsString('no API key', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_unsplash_reports_a_used_up_quota_as_worth_retrying(): void
    {
        config(['services.unsplash.key' => 'test-key']);
        Http::fake(['api.unsplash.com/*' => Http::response('Rate Limit Exceeded', 429)]);

        try {
            (new UnsplashImageProvider)->search('umbrella');
            $this->fail('A 429 should have been reported.');
        } catch (MediaFetchFailed $e) {
            $this->assertTrue($e->isWorthRetrying());
            $this->assertStringContainsString('free limit is used up', $e->userMessage());
        }
    }

    /**
     * Reporting a download is a condition of Unsplash's terms, and it must
     * never be the reason a card ends up without its picture — so it swallows
     * its own failures.
     */
    public function test_unsplash_reports_usage_and_survives_it_failing(): void
    {
        config(['services.unsplash.key' => 'test-key']);
        Http::fake(['api.unsplash.com/*' => Http::response('nope', 500)]);

        $image = new FoundImage(
            url: 'https://images.unsplash.com/photo-1.jpg',
            photographer: 'Cy',
            photographerUrl: 'https://unsplash.com/@cy',
            source: 'unsplash',
            downloadTrackingUrl: 'https://api.unsplash.com/photos/abc/download',
        );

        (new UnsplashImageProvider)->reportUsage($image);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/download'));
    }

    public function test_unsplash_reports_nothing_when_there_is_nowhere_to_report_it(): void
    {
        config(['services.unsplash.key' => 'test-key']);
        Http::fake();

        (new UnsplashImageProvider)->reportUsage(new FoundImage(
            url: 'https://images.unsplash.com/photo-1.jpg',
            photographer: 'Cy',
            photographerUrl: 'https://unsplash.com/@cy',
            source: 'unsplash',
        ));

        Http::assertNothingSent();
    }
}
