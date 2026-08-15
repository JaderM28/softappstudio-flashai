<?php

namespace Tests\Feature;

use App\Contracts\ImageProvider;
use App\Exceptions\MediaFetchFailed;
use App\Services\Media\FakeImageProvider;
use App\Services\Media\FallbackImageProvider;
use App\Services\Media\GeminiTextToSpeech;
use App\Services\Media\OpenverseImageProvider;
use App\Services\Media\PixabayImageProvider;
use App\Support\FoundImage;
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

        Http::fake(['pixabay.com/*' => Http::response([
            'hits' => [[
                'webformatURL' => 'https://pixabay.com/get/640.jpg',
                'largeImageURL' => 'https://pixabay.com/get/1920.jpg',
                'user' => 'Ada',
                'user_id' => 42,
                'tags' => 'umbrella, rain, street',
            ]],
        ])]);

        $found = (new PixabayImageProvider)->search('umbrella rain street');

        // The 640px variant, not the original: a card is shown a few hundred
        // pixels wide and the bucket it lands in is metered.
        $this->assertSame('https://pixabay.com/get/640.jpg', $found->url);
        $this->assertSame('Ada', $found->photographer);
        $this->assertSame('pixabay', $found->source);
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
        Http::fake(['pixabay.com/*' => Http::response(['hits' => []])]);

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
        Http::fake(['api.openverse.org/*' => Http::response([
            'results' => [[
                'thumbnail' => 'https://api.openverse.org/thumb/1.jpg',
                'url' => 'https://live.staticflickr.com/original.jpg',
                'creator' => 'Grace Hopper',
                'creator_url' => 'https://flickr.com/gh',
                'title' => 'Umbrella in the rain',
                'license' => 'by-sa',
            ]],
        ])]);

        $found = (new OpenverseImageProvider)->search('umbrella rain street');

        // Their cached thumbnail, not the original, which may be a dead link on
        // whichever site it was indexed from years ago.
        $this->assertSame('https://api.openverse.org/thumb/1.jpg', $found->url);
        $this->assertSame('openverse', $found->source);

        // Licences differ per picture here, unlike the other two, so the credit
        // shown on the card has to be able to say which one.
        $this->assertStringContainsString('CC BY-SA', (string) $found->description);
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

        $pcm = str_repeat("\x01\x00", 1000);

        Http::fake(['*/interactions' => Http::response([
            'output_audio' => ['data' => base64_encode($pcm)],
        ])]);

        $audio = (new GeminiTextToSpeech)->synthesize('She borrowed my umbrella.', 'en');

        $this->assertSame('wav', $audio->extension);
        $this->assertStringStartsWith('RIFF', $audio->bytes);
        $this->assertSame('WAVE', substr($audio->bytes, 8, 4));

        // 44 bytes of header in front of every sample that was sent.
        $this->assertSame(44 + strlen($pcm), strlen($audio->bytes));

        // What the header has to declare for the clip to play at the right
        // pitch: 24 kHz, mono, 16-bit.
        $this->assertSame(1, unpack('v', substr($audio->bytes, 22, 2))[1]);
        $this->assertSame(24000, unpack('V', substr($audio->bytes, 24, 4))[1]);
        $this->assertSame(16, unpack('v', substr($audio->bytes, 34, 2))[1]);
    }

    /**
     * The same endpoint and the same key as the sentence generator — the reason
     * this is Gemini and not Google Cloud Text-to-Speech.
     */
    public function test_speech_uses_the_interactions_endpoint(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['*' => Http::response(['output_audio' => ['data' => base64_encode('ab')]])]);

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

    public function test_speech_reads_the_clip_out_of_a_steps_response(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake(['*' => Http::response([
            'steps' => [
                ['name' => 'thinking'],
                ['output_audio' => ['data' => base64_encode('sound')]],
            ],
        ])]);

        $audio = (new GeminiTextToSpeech)->synthesize('Hello', 'en');

        $this->assertStringEndsWith('sound', $audio->bytes);
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
}
