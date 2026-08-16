<?php

namespace Tests\Feature;

use App\Contracts\ImageProvider;
use App\Contracts\SpeechSynthesizer;
use App\Enums\AssetStatus;
use App\Jobs\SynthesizeNoteAudio;
use App\Models\Deck;
use App\Models\Note;
use App\Models\User;
use App\Services\Media\FakeImageProvider;
use App\Services\Media\FakeSpeechSynthesizer;
use App\Services\Media\ImageCandidates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The screen where a saved sentence becomes a card.
 */
class NoteCompositionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FakeImageProvider $images;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('flashai.media.disk'));
        Process::fake();

        config([
            'services.pixabay.key' => 'test-key',
            'services.gemini.key' => 'test-key',
        ]);

        Http::fake(fn () => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']));

        $this->images = new FakeImageProvider;
        $this->app->instance(ImageProvider::class, $this->images);
        $this->app->instance(SpeechSynthesizer::class, new FakeSpeechSynthesizer);
        $this->app->instance(\App\Contracts\SpeechTranscriber::class, new \App\Services\Media\FakeTranscriber);

        $this->user = User::factory()->create();
    }

    private function draft(array $attributes = []): Note
    {
        return Note::factory()
            ->inDeck(Deck::factory()->for($this->user)->create())
            ->pending()
            ->create(array_merge([
                'sentence' => 'She borrowed my umbrella yesterday.',
                'target' => 'borrowed',
                'image_query' => 'umbrella rain street',
            ], $attributes));
    }

    public function test_a_draft_cannot_be_composed_by_someone_else(): void
    {
        $note = $this->draft();

        $this->actingAs(User::factory()->create())
            ->get(route('notes.compose', $note))
            ->assertForbidden();
    }

    public function test_the_screen_shows_the_sentence_and_what_is_missing(): void
    {
        $note = $this->draft();

        $this->actingAs($this->user)
            ->get(route('notes.compose', $note))
            ->assertOk()
            ->assertSee($note->sentence)
            ->assertSee('Create the card');
    }

    /**
     * The button is the whole point of the screen: it must not be pressable
     * until there is something to look at and something to hear.
     */
    public function test_a_card_cannot_be_created_before_its_media_exists(): void
    {
        $note = $this->draft();

        $this->actingAs($this->user)
            ->post(route('notes.complete', $note))
            ->assertSessionHasErrors('complete');

        $this->assertSame(0, $note->cards()->count());
    }

    public function test_completing_a_finished_note_creates_its_cards(): void
    {
        $note = $this->draft();

        app(ImageCandidates::class)->refresh($note, 'umbrella rain street');
        $this->actingAs($this->user)->post(route('notes.image.choose', $note), ['index' => 0]);
        (new SynthesizeNoteAudio($note))->handle(app(SpeechSynthesizer::class), app(\App\Actions\TimeNoteWords::class));

        $this->actingAs($this->user)
            ->post(route('notes.complete', $note))
            ->assertRedirect(route('notes.create'));

        $this->assertSame(2, $note->fresh()->cards()->count());

        // The grid has done its job; keeping six URLs per note for a day after
        // that is just rent.
        $this->assertTrue(app(ImageCandidates::class)->for($note)->isEmpty());
    }

    // --------------------------------------------------------------- picture

    public function test_a_candidate_is_chosen_by_index_and_stored(): void
    {
        $note = $this->draft();

        $candidates = app(ImageCandidates::class)->refresh($note, 'umbrella rain street');

        $this->actingAs($this->user)
            ->post(route('notes.image.choose', $note), ['index' => 2])
            ->assertRedirect();

        $note->refresh();

        $this->assertSame(AssetStatus::Ready, $note->image_status);
        $this->assertSame($candidates[2]->url, $note->image_url);
        Storage::disk(config('flashai.media.disk'))->assertExists($note->image_path);
    }

    /**
     * The candidates live server-side and the form sends an index, so a request
     * cannot name a URL for the server to go and fetch.
     */
    public function test_an_index_outside_the_offered_set_is_refused(): void
    {
        $note = $this->draft();

        app(ImageCandidates::class)->refresh($note, 'umbrella rain street');

        $this->actingAs($this->user)
            ->post(route('notes.image.choose', $note), ['index' => 99])
            ->assertSessionHasErrors('index');

        $this->actingAs($this->user)
            ->post(route('notes.image.choose', $note), ['index' => 'https://evil.example/x.jpg'])
            ->assertSessionHasErrors('index');

        $this->assertNull($note->fresh()->image_path);
    }

    public function test_choosing_from_an_expired_set_says_so_instead_of_failing(): void
    {
        $note = $this->draft();

        $this->actingAs($this->user)
            ->post(route('notes.image.choose', $note), ['index' => 0])
            ->assertSessionHasErrors('index');
    }

    /**
     * The scene is what gets searched, never the sentence: stock libraries
     * match tags, and the full sentence leads with a Christmas dinner table.
     */
    public function test_the_scene_can_be_rewritten_and_searched_again(): void
    {
        $note = $this->draft();

        $this->actingAs($this->user)
            ->post(route('notes.image.search', $note), ['image_query' => 'coffee spilled table'])
            ->assertRedirect();

        $this->assertSame('coffee spilled table', $note->fresh()->image_query);
        $this->assertSame(['coffee spilled table'], $this->images->queries);
        $this->assertCount(ImageCandidates::LIMIT, app(ImageCandidates::class)->for($note));
    }

    /**
     * Some concepts are in no stock library at any price, and a sentence must
     * never be stuck because of that.
     */
    public function test_a_picture_of_your_own_can_be_uploaded(): void
    {
        $note = $this->draft();

        $this->actingAs($this->user)
            ->post(route('notes.image.upload', $note), [
                'image' => UploadedFile::fake()->image('mine.jpg', 800, 600),
            ])
            ->assertRedirect();

        $note->refresh();

        $this->assertSame(AssetStatus::Ready, $note->image_status);
        $this->assertNull($note->image_url);
        $this->assertSame('upload', $note->image_attribution['source']);
        Storage::disk(config('flashai.media.disk'))->assertExists($note->image_path);
    }

    public function test_an_upload_that_is_not_an_image_is_refused(): void
    {
        $note = $this->draft();

        $this->actingAs($this->user)
            ->post(route('notes.image.upload', $note), [
                'image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors('image');

        $this->assertNull($note->fresh()->image_path);
    }

    public function test_a_tiny_upload_is_refused_before_it_reaches_a_card(): void
    {
        $note = $this->draft();

        $this->actingAs($this->user)
            ->post(route('notes.image.upload', $note), [
                'image' => UploadedFile::fake()->image('thumb.jpg', 40, 40),
            ])
            ->assertSessionHasErrors('image');
    }

    // ---------------------------------------------------------------- status

    public function test_the_status_endpoint_reports_progress_and_stops_when_settled(): void
    {
        $note = $this->draft();

        $this->actingAs($this->user)
            ->getJson(route('notes.compose.status', $note))
            ->assertOk()
            ->assertJson([
                'image_status' => 'pending',
                'audio_status' => 'pending',
                'complete' => false,
                'settled' => false,
            ])
            ->assertJsonPath('missing', fn (array $missing) => in_array('image_path', $missing, true));
    }

    public function test_the_status_endpoint_is_not_readable_by_anyone_else(): void
    {
        $note = $this->draft();

        $this->actingAs(User::factory()->create())
            ->getJson(route('notes.compose.status', $note))
            ->assertForbidden();
    }

    // ----------------------------------------------------------------- tray

    public function test_unfinished_sentences_are_findable_rather_than_lost(): void
    {
        $deck = Deck::factory()->for($this->user)->create();

        $unfinished = Note::factory()->inDeck($deck)->pending()->create([
            'sentence' => 'This one never got its picture.',
        ]);
        $finished = Note::factory()->inDeck($deck)->create(['sentence' => 'This one is being studied.']);
        app(\App\Actions\SyncNoteCards::class)->handle($finished);

        $this->actingAs($this->user)
            ->get(route('notes.index', ['status' => 'incomplete']))
            ->assertOk()
            ->assertSee($unfinished->sentence)
            ->assertDontSee($finished->sentence);

        $this->actingAs($this->user)
            ->get(route('notes.index'))
            ->assertOk()
            ->assertSee('still waiting');
    }
}
