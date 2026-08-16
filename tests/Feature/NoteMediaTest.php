<?php

namespace Tests\Feature;

use App\Contracts\ImageProvider;
use App\Contracts\SpeechSynthesizer;
use App\Enums\AssetStatus;
use App\Enums\CardType;
use App\Exceptions\MediaFetchFailed;
use App\Jobs\FetchNoteImage;
use App\Jobs\SynthesizeNoteAudio;
use App\Models\Deck;
use App\Actions\StoreNoteImage;
use App\Actions\TimeNoteWords;
use App\Actions\SyncNoteCards;
use App\Models\Note;
use App\Services\Media\ImageCandidates;
use App\Models\User;
use App\Services\Media\FakeImageProvider;
use App\Services\Media\FakeSpeechSynthesizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NoteMediaTest extends TestCase
{
    use RefreshDatabase;

    private FakeImageProvider $images;

    private FakeSpeechSynthesizer $speech;

    /**
     * How the CDN answers when the job downloads the picture. A property rather
     * than a per-test Http::fake because repeat calls to fake() merge with the
     * stub set here instead of replacing it, so the first one would always win.
     */
    private int $downloadStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('flashai.media.disk'));

        config([
            'services.pixabay.key' => 'test-key',
            'services.gemini.key' => 'test-key',
        ]);

        // The picture is downloaded now rather than hotlinked, so the job makes
        // a second request the fake provider knows nothing about.
        Http::fake(fn () => Http::response(
            $this->downloadStatus === 200 ? 'jpeg-bytes' : '',
            $this->downloadStatus,
            ['Content-Type' => 'image/jpeg'],
        ));

        $this->images = new FakeImageProvider;
        $this->speech = new FakeSpeechSynthesizer;

        $this->app->instance(ImageProvider::class, $this->images);
        $this->app->instance(SpeechSynthesizer::class, $this->speech);
        $this->app->instance(\App\Contracts\SpeechTranscriber::class, new \App\Services\Media\FakeTranscriber);
    }

    private function note(array $attributes = []): Note
    {
        return Note::factory()->pending()->create(array_merge([
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'borrowed',
            'image_query' => 'umbrella rain street',
        ], $attributes));
    }

    // -------------------------------------------------------------- images

    public function test_the_image_job_searches_the_scene(): void
    {
        $note = $this->note();

        (new FetchNoteImage($note))->handle(app(ImageCandidates::class), app(StoreNoteImage::class));

        $note->refresh();

        // Searched on the scene, not on "borrowed".
        $this->assertSame(['umbrella rain street'], $this->images->queries);
        $this->assertSame(AssetStatus::Ready, $note->image_status);
    }

    /**
     * Pixabay allows its URLs for displaying search results and forbids
     * permanent hotlinking from inside an app, so the file is kept. It also
     * means a card survives the picture being deleted at the source.
     */
    public function test_the_picture_is_downloaded_and_stored(): void
    {
        $note = $this->note();

        (new FetchNoteImage($note))->handle(app(ImageCandidates::class), app(StoreNoteImage::class));

        $note->refresh();

        $this->assertNotNull($note->image_path);
        Storage::disk(config('flashai.media.disk'))->assertExists($note->image_path);
        $this->assertSame('jpeg-bytes', Storage::disk(config('flashai.media.disk'))->get($note->image_path));

        // The card is served the stored copy, not the remote one.
        $this->assertStringNotContainsString('pixabay.test', (string) $note->imageSrc());
    }

    /**
     * Kept beside the copy because it is where the credit on the card links
     * back to.
     */
    public function test_the_original_url_is_remembered(): void
    {
        $note = $this->note();

        (new FetchNoteImage($note))->handle(app(ImageCandidates::class), app(StoreNoteImage::class));

        $this->assertStringStartsWith('https://images.pixabay.test/', $note->fresh()->image_url);
    }

    /**
     * A picture that cannot be downloaded is not a picture, whatever the search
     * said.
     */
    public function test_a_download_that_fails_marks_the_image_failed(): void
    {
        $this->downloadStatus = 404;
        $note = $this->note();

        (new FetchNoteImage($note))->handle(app(ImageCandidates::class), app(StoreNoteImage::class));

        $note->refresh();

        $this->assertSame(AssetStatus::Failed, $note->image_status);
        $this->assertNull($note->image_path);
    }

    /**
     * Only Unsplash requires this, and it stays a no-op on the other two — but
     * the chain has to keep passing it along for that to be true.
     */
    public function test_using_a_picture_is_reported_back(): void
    {
        $note = $this->note();

        (new FetchNoteImage($note))->handle(app(ImageCandidates::class), app(StoreNoteImage::class));

        $this->assertCount(1, $this->images->reported);
    }

    public function test_the_photographer_is_credited(): void
    {
        $note = $this->note();

        (new FetchNoteImage($note))->handle(app(ImageCandidates::class), app(StoreNoteImage::class));

        // Which library the picture came from decides how it must be credited,
        // so the source travels with the photographer.
        $this->assertSame('Ada Lovelace', $note->fresh()->image_attribution['photographer']);
        $this->assertSame('pixabay', $note->fresh()->image_attribution['source']);
    }

    /**
     * Without a scene the sentence has to be cut down to content words, never
     * searched whole. Measured against Pixabay, "She borrowed my umbrella
     * yesterday." returns a Christmas dinner table first: the library matches
     * tags, and `umbrella` is the only word in that sentence anyone tags with.
     */
    public function test_a_note_without_a_scene_is_searched_on_its_content_words(): void
    {
        $note = $this->note(['image_query' => null]);

        (new FetchNoteImage($note))->handle(app(ImageCandidates::class), app(StoreNoteImage::class));

        $this->assertSame(['borrowed umbrella'], $this->images->queries);
    }

    public function test_a_rejected_key_marks_the_image_failed_without_retrying(): void
    {
        $note = $this->note();
        $this->images->willFail(MediaFetchFailed::rejected('Pixabay', 401, 'bad key'));

        (new FetchNoteImage($note))->handle(app(ImageCandidates::class), app(StoreNoteImage::class));

        $note->refresh();

        $this->assertSame(AssetStatus::Failed, $note->image_status);
        $this->assertStringContainsString('rejected the API key', $note->generation_errors['image']);
    }

    /**
     * This reverses the old rule on purpose. A sentence whose picture failed is
     * not a lesser card, it is an unfinished one: the image is the definition,
     * and without it the card teaches an English-to-Spanish lookup, which is the
     * thing this app exists not to be.
     *
     * The sentence is never lost, though — it waits in the tray with a note of
     * what is missing, and the compose screen offers three ways out.
     */
    public function test_a_note_whose_picture_failed_makes_no_cards_and_says_what_is_missing(): void
    {
        $note = $this->note();
        $this->images->willFail(MediaFetchFailed::rejected('Pixabay', 401, 'bad key'));

        (new FetchNoteImage($note))->handle(app(ImageCandidates::class), app(StoreNoteImage::class));
        (new SynthesizeNoteAudio($note))->handle($this->speech, app(TimeNoteWords::class));

        $note->refresh();

        // Status is still tracked per asset: the audio came through fine.
        $this->assertSame(AssetStatus::Failed, $note->image_status);
        $this->assertSame(AssetStatus::Ready, $note->audio_status);

        $this->assertFalse($note->supports(CardType::Cloze));
        $this->assertFalse($note->isComplete());
        $this->assertSame(['image_path'], $note->missingForCards());

        // And the sentence itself survived, which is the part that must never
        // depend on a third party having a good afternoon.
        $this->assertSame('She borrowed my umbrella yesterday.', $note->sentence);
    }

    // --------------------------------------------------------------- audio

    public function test_the_audio_job_records_the_sentence_and_the_target(): void
    {
        $note = $this->note();

        (new SynthesizeNoteAudio($note))->handle($this->speech, app(TimeNoteWords::class));

        $note->refresh();

        $this->assertSame(
            ['She borrowed my umbrella yesterday.', 'borrowed'],
            $this->speech->spokenTexts(),
        );
        $this->assertSame(AssetStatus::Ready, $note->audio_status);
        Storage::disk(config('flashai.media.disk'))->assertExists($note->audio_sentence_path);
        Storage::disk(config('flashai.media.disk'))->assertExists($note->audio_target_path);
    }

    public function test_audio_is_spoken_in_the_decks_target_language(): void
    {
        $deck = Deck::factory()->create(['target_language' => 'fr']);
        $note = $this->note(['deck_id' => $deck->id, 'user_id' => $deck->user_id]);

        (new SynthesizeNoteAudio($note))->handle($this->speech, app(TimeNoteWords::class));

        $this->assertSame('fr', $this->speech->calls[0]['language']);
    }

    /**
     * Media arriving does not create cards any more.
     *
     * It used to: the audio job called SyncNoteCards the moment the clip
     * landed. That is exactly what has to stop, because a clip existing is not
     * a clip being any good, and the whole point of this app is that the media
     * is the card — so a person hears it before it becomes one. The compose
     * screen is where that happens.
     */
    public function test_media_arriving_does_not_create_cards_on_its_own(): void
    {
        $note = $this->note();

        (new FetchNoteImage($note))->handle(app(ImageCandidates::class), app(StoreNoteImage::class));
        (new SynthesizeNoteAudio($note))->handle($this->speech, app(TimeNoteWords::class));

        $note->refresh();

        // Everything it needs is there...
        $this->assertTrue($note->isComplete());
        $this->assertSame(AssetStatus::Ready, $note->image_status);
        $this->assertSame(AssetStatus::Ready, $note->audio_status);

        // ...and still no cards, because nobody has approved it yet.
        $this->assertSame(0, $note->cards()->count());

        // Approval is what creates them, and it creates both at once.
        app(SyncNoteCards::class)->handle($note);

        $this->assertSame(2, $note->fresh()->cards()->count());
        $this->assertTrue($note->fresh()->cards->contains(fn ($card) => $card->type === CardType::Listening));
    }

    public function test_failed_audio_is_reported_on_the_note(): void
    {
        $note = $this->note();
        $this->speech->willFail(MediaFetchFailed::rejected('Gemini speech', 403, 'forbidden'));

        (new SynthesizeNoteAudio($note))->handle($this->speech, app(TimeNoteWords::class));

        $note->refresh();

        $this->assertSame(AssetStatus::Failed, $note->audio_status);
        $this->assertStringContainsString('rejected the API key', $note->generation_errors['audio']);
        $this->assertNull($note->audio_sentence_path);
    }

    public function test_a_note_with_no_target_still_gets_sentence_audio(): void
    {
        $note = $this->note(['target' => '']);

        (new SynthesizeNoteAudio($note))->handle($this->speech, app(TimeNoteWords::class));

        $note->refresh();

        $this->assertSame(AssetStatus::Ready, $note->audio_status);
        $this->assertNotNull($note->audio_sentence_path);
        $this->assertNull($note->audio_target_path);
    }

    // -------------------------------------------------------- retry policy

    /**
     * A quota resets, a bad key does not — only the first is worth waiting for.
     */
    public function test_only_transient_failures_are_worth_retrying(): void
    {
        $this->assertTrue(MediaFetchFailed::rejected('Pixabay', 429, '')->isWorthRetrying());
        $this->assertTrue(MediaFetchFailed::rejected('Pixabay', 503, '')->isWorthRetrying());
        $this->assertTrue(MediaFetchFailed::unreachable('Pixabay')->isWorthRetrying());

        $this->assertFalse(MediaFetchFailed::rejected('Pixabay', 401, '')->isWorthRetrying());
        $this->assertFalse(MediaFetchFailed::notConfigured('Pixabay')->isWorthRetrying());
        $this->assertFalse(MediaFetchFailed::nothingFound('Pixabay', 'x')->isWorthRetrying());
    }

    // ------------------------------------------------------------ queueing

    public function test_saving_a_sentence_queues_both_jobs(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('notes.store'), [
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'borrowed',
            'image_query' => 'umbrella rain street',
        ]);

        Queue::assertPushed(FetchNoteImage::class);
        Queue::assertPushed(SynthesizeNoteAudio::class);
    }

    public function test_nothing_is_queued_for_a_service_that_has_no_key(): void
    {
        Queue::fake();
        config([
            'services.pixabay.key' => null,
            'services.unsplash.key' => null,
            'services.openverse.base_url' => null,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('notes.store'), [
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'borrowed',
        ]);

        Queue::assertNotPushed(FetchNoteImage::class);
        Queue::assertPushed(SynthesizeNoteAudio::class);
    }

    /**
     * A recording of the old wording is worse than none.
     */
    public function test_rewording_a_sentence_re_records_the_audio(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $note = Note::factory()->inDeck(Deck::factory()->for($user)->create())->create();

        $this->actingAs($user)->patch(route('notes.update', $note), [
            'sentence' => 'She borrowed my bike yesterday.',
            'target' => 'borrowed',
        ]);

        Queue::assertPushed(SynthesizeNoteAudio::class);
        Queue::assertNotPushed(FetchNoteImage::class);
    }

    public function test_editing_something_else_leaves_the_media_alone(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $note = Note::factory()->inDeck(Deck::factory()->for($user)->create())->create();

        $this->actingAs($user)->patch(route('notes.update', $note), [
            'sentence' => $note->sentence,
            'target' => $note->target,
            'meaning' => 'a completely rewritten gloss',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_media_can_be_queued_again_by_hand(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $note = Note::factory()
            ->inDeck(Deck::factory()->for($user)->create())
            ->withoutImage()
            ->create();

        $this->actingAs($user)
            ->post(route('notes.retry-media', $note))
            ->assertRedirect();

        Queue::assertPushed(FetchNoteImage::class);
        // The audio came through the first time, so it is left alone.
        Queue::assertNotPushed(SynthesizeNoteAudio::class);
    }

    public function test_a_user_cannot_requeue_someone_elses_note(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $others = Note::factory()->create();

        $this->actingAs($user)
            ->post(route('notes.retry-media', $others))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }
    /**
     * Waiting five minutes when the service asked for fifteen seconds is time
     * spent for nothing, and with a quota this tight it is the difference
     * between a card finishing now and finishing after lunch.
     */
    public function test_the_wait_the_service_asks_for_is_the_wait_it_gets(): void
    {
        $failure = MediaFetchFailed::rejected(
            'Gemini speech',
            429,
            '{"error":{"message":"You exceeded your current quota. Please retry in 15.5s"}}',
        );

        $this->assertSame(16, $failure->retryAfterSeconds);
        $this->assertTrue($failure->isWorthRetrying());
    }

    public function test_a_retry_after_header_is_read_when_there_is_one(): void
    {
        $failure = MediaFetchFailed::rejected('Pixabay', 429, 'slow down', '90');

        $this->assertSame(90, $failure->retryAfterSeconds);
    }

    /**
     * Most failures say nothing about when to come back, and guessing is then
     * the only option — so the absence has to be recognisable.
     */
    public function test_a_failure_that_names_no_wait_says_so(): void
    {
        $this->assertNull(MediaFetchFailed::rejected('Pixabay', 500, 'server error')->retryAfterSeconds);
        $this->assertNull(MediaFetchFailed::notConfigured('Pixabay')->retryAfterSeconds);
    }

    /**
     * The hole this replaced.
     *
     * Retry decisions used to be made by searching the message for "HTTP 429" —
     * and that message carries five hundred characters of the provider's own
     * response body. An error page that merely mentioned the number was retried
     * forever, and a provider rewording its errors would have stopped being
     * retried at all. Both silently.
     */
    public function test_the_providers_own_words_cannot_decide_whether_we_retry(): void
    {
        // A bad key, whose body happens to mention a rate limit.
        $misleading = MediaFetchFailed::rejected(
            'Pixabay',
            401,
            '<html><body>Invalid key. See our docs on HTTP 429 rate limiting.</body></html>',
        );

        $this->assertFalse(
            $misleading->isWorthRetrying(),
            'A 401 was treated as retryable because its body mentioned 429.',
        );

        // And the reverse: a real quota failure whose body says nothing at all.
        $quiet = MediaFetchFailed::rejected('Cloudflare speech', 429, '');

        $this->assertTrue(
            $quiet->isWorthRetrying(),
            'A real 429 was not retried because its body was empty.',
        );
    }

    /**
     * An answer cut short by the network is worth another go; one that arrived
     * whole and was simply empty is not.
     */
    public function test_a_truncated_answer_is_retryable_and_an_empty_one_is_not(): void
    {
        $this->assertTrue(
            MediaFetchFailed::unusableAnswer('Gemini speech', 'the reply stopped mid-stream', transient: true)
                ->isWorthRetrying(),
        );

        $this->assertFalse(
            MediaFetchFailed::unusableAnswer('Gemini speech', 'there was nothing to read aloud')
                ->isWorthRetrying(),
        );
    }

    /**
     * dontRelease() throws the job away when the limiter is full; without it the
     * job is put back to be tried when the limiter reopens. The comment above
     * this middleware claimed the second and the code did the first, so a batch
     * large enough to hit the ceiling lost every note past it, silently.
     */
    public function test_a_rate_limited_job_waits_rather_than_being_discarded(): void
    {
        $middleware = (new FetchNoteImage($this->note()))->middleware();

        $this->assertCount(1, $middleware);

        $limiter = $middleware[0];

        $this->assertInstanceOf(\Illuminate\Queue\Middleware\RateLimited::class, $limiter);

        // The property the flag sets. False means release-and-retry.
        $shouldRelease = (new \ReflectionProperty($limiter, 'shouldRelease'))->getValue($limiter);

        $this->assertTrue($shouldRelease, 'The job would be discarded instead of retried.');
    }

    /**
     * Any voice will do.
     *
     * This gate asked for the Gemini key alone. The moment Cloudflare became
     * the primary that became a trap: drop the Gemini key and audio would stop
     * being queued at all, while the service that actually produces it sat
     * there working — and nothing would have said so.
     */
    public function test_audio_is_queued_whenever_any_voice_is_configured(): void
    {
        Queue::fake();

        config(['services.gemini.key' => null, 'services.cloudflare.token' => 'x', 'services.cloudflare.account_id' => 'y']);
        app(\App\Actions\QueueNoteMedia::class)->handle($this->note(), image: false);
        Queue::assertPushed(SynthesizeNoteAudio::class, 1);

        config(['services.gemini.key' => 'x', 'services.cloudflare.token' => null, 'services.cloudflare.account_id' => null]);
        app(\App\Actions\QueueNoteMedia::class)->handle($this->note(), image: false);
        Queue::assertPushed(SynthesizeNoteAudio::class, 2);
    }

    public function test_audio_is_not_queued_when_no_voice_is_configured(): void
    {
        Queue::fake();

        config([
            'services.gemini.key' => null,
            'services.cloudflare.token' => null,
            'services.cloudflare.account_id' => null,
        ]);

        app(\App\Actions\QueueNoteMedia::class)->handle($this->note(), image: false);

        Queue::assertNothingPushed();
    }

}
