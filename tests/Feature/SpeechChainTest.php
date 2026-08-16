<?php

namespace Tests\Feature;

use App\Contracts\SpeechSynthesizer;
use App\Exceptions\MediaFetchFailed;
use App\Services\Media\CloudflareTextToSpeech;
use App\Services\Media\FakeSpeechSynthesizer;
use App\Services\Media\FallbackSpeechSynthesizer;
use App\Support\SynthesizedAudio;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The two voices, and the chain that keeps one of them answering.
 */
class SpeechChainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.cloudflare.account_id' => 'test-account',
            'services.cloudflare.token' => 'test-token',
            'services.cloudflare.tts.voice' => 'angus',
        ]);
    }

    // ------------------------------------------------------------ cloudflare

    /**
     * Workers AI has a REST endpoint, so there is no Worker to deploy — the URL
     * shape is the whole integration and is worth pinning down.
     */
    public function test_it_posts_to_the_rest_endpoint_with_the_chosen_voice(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response('mp3', 200, ['Content-Type' => 'audio/mpeg'])]);

        (new CloudflareTextToSpeech)->synthesize('Hello', 'en');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.cloudflare.com/client/v4/accounts/test-account/ai/run/@cf/deepgram/aura-1'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['text'] === 'Hello'
            && $request['speaker'] === 'angus');
    }

    /**
     * The clip is the body, already encoded — no base64, no WAV header to build,
     * nothing for ffmpeg to do. That is most of why this is the primary.
     */
    public function test_the_answer_is_an_mp3_that_needs_no_further_work(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);

        $audio = (new CloudflareTextToSpeech)->synthesize('Hello', 'en');

        $this->assertSame('mp3', $audio->extension);
        $this->assertSame('mp3-bytes', $audio->bytes);
    }

    /**
     * Every other Cloudflare endpoint answers in a JSON envelope, and errors
     * from this one arrive that way too. Storing that as an .mp3 would hand the
     * browser a file full of an error message.
     */
    public function test_a_json_answer_is_refused_rather_than_stored_as_audio(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(
            ['success' => false, 'errors' => [['message' => 'Out of neurons']]],
            200,
        )]);

        try {
            (new CloudflareTextToSpeech)->synthesize('Hello', 'en');
            $this->fail('A JSON body should not have been treated as audio.');
        } catch (MediaFetchFailed $e) {
            $this->assertStringContainsString('JSON instead of audio', $e->getMessage());
            $this->assertStringContainsString('Out of neurons', $e->getMessage());
        }
    }

    public function test_nothing_is_spoken_without_credentials(): void
    {
        config(['services.cloudflare.token' => null]);
        Http::fake();

        try {
            (new CloudflareTextToSpeech)->synthesize('Hello', 'en');
            $this->fail('A missing token should have been reported.');
        } catch (MediaFetchFailed $e) {
            $this->assertStringContainsString('no API key', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_used_up_daily_allowance_is_worth_retrying(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response('daily limit reached', 429)]);

        try {
            (new CloudflareTextToSpeech)->synthesize('Hello', 'en');
            $this->fail('A 429 should have been reported.');
        } catch (MediaFetchFailed $e) {
            $this->assertTrue($e->isWorthRetrying());
        }
    }

    // ----------------------------------------------------------- the chain

    public function test_the_second_voice_answers_when_the_first_declines(): void
    {
        $broken = new FakeSpeechSynthesizer;
        $broken->willFail(MediaFetchFailed::rejected('Cloudflare speech', 429, 'out of neurons'));

        $working = new FakeSpeechSynthesizer;

        $audio = (new FallbackSpeechSynthesizer($broken, $working))->synthesize('Hello', 'en');

        $this->assertNotSame('', $audio->bytes);
        $this->assertCount(1, $working->calls);
    }

    public function test_the_chain_stops_at_the_first_voice_that_answers(): void
    {
        $first = new FakeSpeechSynthesizer;
        $second = new FakeSpeechSynthesizer;

        (new FallbackSpeechSynthesizer($first, $second))->synthesize('Hello', 'en');

        $this->assertCount(1, $first->calls);
        $this->assertCount(0, $second->calls);
    }

    /**
     * The job downstream decides whether to retry by asking the failure, so
     * reporting "not set up" when the real cause was a busy minute would
     * abandon a note that only needed to wait.
     */
    public function test_the_chain_reports_the_failure_worth_acting_on(): void
    {
        $unconfigured = new FakeSpeechSynthesizer;
        $unconfigured->willFail(MediaFetchFailed::notConfigured('Cloudflare speech'));

        $busy = new FakeSpeechSynthesizer;
        $busy->willFail(MediaFetchFailed::rejected('Gemini speech', 429, 'slow down'));

        try {
            (new FallbackSpeechSynthesizer($unconfigured, $busy))->synthesize('Hello', 'en');
            $this->fail('Both voices declined; that should have thrown.');
        } catch (MediaFetchFailed $e) {
            $this->assertTrue($e->isWorthRetrying());
            $this->assertStringContainsString('Gemini speech', $e->getMessage());
        }
    }

    public function test_the_container_hands_out_the_chain(): void
    {
        $speech = app(SpeechSynthesizer::class);

        // Wrapped by the compressor, which is what turns Gemini's WAV into an
        // MP3 and leaves Cloudflare's alone.
        $this->assertInstanceOf(SpeechSynthesizer::class, $speech);

        Http::fake(['api.cloudflare.com/*' => Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);
        config(['flashai.media.audio.compress' => false]);

        $audio = $speech->synthesize('Hello', 'en');

        $this->assertInstanceOf(SynthesizedAudio::class, $audio);
        $this->assertSame('mp3', $audio->extension);
    }
}
