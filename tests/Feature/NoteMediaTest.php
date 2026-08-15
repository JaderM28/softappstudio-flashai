<?php

namespace Tests\Feature;

use App\Actions\SyncNoteCards;
use App\Contracts\ImageProvider;
use App\Contracts\SpeechSynthesizer;
use App\Enums\AssetStatus;
use App\Enums\CardType;
use App\Exceptions\MediaFetchFailed;
use App\Jobs\FetchNoteImage;
use App\Jobs\SynthesizeNoteAudio;
use App\Models\Deck;
use App\Models\Note;
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

        (new FetchNoteImage($note))->handle($this->images);

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

        (new FetchNoteImage($note))->handle($this->images);

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

        (new FetchNoteImage($note))->handle($this->images);

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

        (new FetchNoteImage($note))->handle($this->images);

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

        (new FetchNoteImage($note))->handle($this->images);

        $this->assertCount(1, $this->images->reported);
    }

    public function test_the_photographer_is_credited(): void
    {
        $note = $this->note();

        (new FetchNoteImage($note))->handle($this->images);

        // Which library the picture came from decides how it must be credited,
        // so the source travels with the photographer.
        $this->assertSame('Ada Lovelace', $note->fresh()->image_attribution['photographer']);
        $this->assertSame('pixabay', $note->fresh()->image_attribution['source']);
    }

    public function test_the_sentence_is_searched_when_there_is_no_scene_query(): void
    {
        $note = $this->note(['image_query' => null]);

        (new FetchNoteImage($note))->handle($this->images);

        $this->assertSame(['She borrowed my umbrella yesterday.'], $this->images->queries);
    }

    public function test_a_rejected_key_marks_the_image_failed_without_retrying(): void
    {
        $note = $this->note();
        $this->images->willFail(MediaFetchFailed::rejected('Pixabay', 401, 'bad key'));

        (new FetchNoteImage($note))->handle($this->images);

        $note->refresh();

        $this->assertSame(AssetStatus::Failed, $note->image_status);
        $this->assertStringContainsString('rejected the API key', $note->generation_errors['image']);
    }

    public function test_a_note_without_a_picture_is_still_studiable(): void
    {
        $note = $this->note();
        $this->images->willFail(MediaFetchFailed::rejected('Pixabay', 401, 'bad key'));

        (new FetchNoteImage($note))->handle($this->images);
        (new SynthesizeNoteAudio($note))->handle($this->speech, app(SyncNoteCards::class));

        $note->refresh();

        // This is the whole reason asset status is tracked per asset.
        $this->assertSame(AssetStatus::Failed, $note->image_status);
        $this->assertSame(AssetStatus::Ready, $note->audio_status);
        $this->assertTrue($note->supports(CardType::Cloze));
        $this->assertTrue($note->supports(CardType::Listening));
    }

    // --------------------------------------------------------------- audio

    public function test_the_audio_job_records_the_sentence_and_the_target(): void
    {
        $note = $this->note();

        (new SynthesizeNoteAudio($note))->handle($this->speech, app(SyncNoteCards::class));

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

        (new SynthesizeNoteAudio($note))->handle($this->speech, app(SyncNoteCards::class));

        $this->assertSame('fr', $this->speech->calls[0]['language']);
    }

    /**
     * The listening card cannot exist until there is something to listen to,
     * so this is the moment it appears.
     */
    public function test_the_listening_card_appears_once_the_audio_exists(): void
    {
        $note = $this->note();
        app(SyncNoteCards::class)->handle($note);

        $this->assertSame([CardType::Cloze->value], $note->cards()->pluck('type')->map(
            fn ($type) => $type instanceof CardType ? $type->value : $type
        )->all());

        (new SynthesizeNoteAudio($note))->handle($this->speech, app(SyncNoteCards::class));

        $this->assertSame(2, $note->fresh()->cards()->count());
        $this->assertTrue($note->fresh()->cards->contains(fn ($card) => $card->type === CardType::Listening));
    }

    public function test_failed_audio_is_reported_on_the_note(): void
    {
        $note = $this->note();
        $this->speech->willFail(MediaFetchFailed::rejected('Gemini speech', 403, 'forbidden'));

        (new SynthesizeNoteAudio($note))->handle($this->speech, app(SyncNoteCards::class));

        $note->refresh();

        $this->assertSame(AssetStatus::Failed, $note->audio_status);
        $this->assertStringContainsString('rejected the API key', $note->generation_errors['audio']);
        $this->assertNull($note->audio_sentence_path);
    }

    public function test_a_note_with_no_target_still_gets_sentence_audio(): void
    {
        $note = $this->note(['target' => '']);

        (new SynthesizeNoteAudio($note))->handle($this->speech, app(SyncNoteCards::class));

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
}
