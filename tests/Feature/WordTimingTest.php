<?php

namespace Tests\Feature;

use App\Actions\AlignWordTimings;
use App\Actions\TimeNoteWords;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\SpeechTranscriber;
use App\Exceptions\MediaFetchFailed;
use App\Jobs\SynthesizeNoteAudio;
use App\Models\Deck;
use App\Models\Note;
use App\Models\User;
use App\Services\Media\CloudflareTranscriber;
use App\Services\Media\FakeSpeechSynthesizer;
use App\Services\Media\FakeTranscriber;
use App\Support\WordTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Hearing a word the way it is said inside its own sentence.
 */
class WordTimingTest extends TestCase
{
    use RefreshDatabase;

    private const SENTENCE = 'The waiter spilled coffee on the tablecloth.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('flashai.media.disk'));

        config([
            'services.cloudflare.account_id' => 'test-account',
            'services.cloudflare.token' => 'test-token',
            'services.gemini.key' => 'test-key',
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: float, 2: float}>  $words
     * @return \Illuminate\Support\Collection<int, WordTiming>
     */
    private function timings(array $words): \Illuminate\Support\Collection
    {
        return collect($words)->map(fn (array $w) => new WordTiming($w[0], $w[1], $w[2]));
    }

    // --------------------------------------------------------------- aligning

    public function test_a_transcript_that_agrees_is_kept_with_the_sentences_own_spelling(): void
    {
        // Whisper returns words with a leading space; the sentence does not.
        $aligned = app(AlignWordTimings::class)->handle(
            $this->timings([
                [' The', 0.0, 0.3], [' waiter', 0.3, 0.52], [' spilled', 0.52, 0.88],
                [' coffee', 0.88, 1.24], [' on', 1.24, 1.4], [' the', 1.4, 1.52],
                [' tablecloth.', 1.52, 2.1],
            ]),
            self::SENTENCE,
        );

        $this->assertCount(7, $aligned);
        $this->assertSame('The', $aligned[0]->word);
        $this->assertSame('tablecloth.', $aligned[6]->word);
        $this->assertSame(0.52, $aligned[2]->start);
        $this->assertSame(0.88, $aligned[2]->end);
    }

    /**
     * The case this whole guard exists for.
     *
     * Without the sentence passed as a prompt, Whisper heard "tablecloth" as
     * "table cup" — eight words against seven. Mapped by position, tapping
     * "tablecloth" would have played the sound of "cup": a card teaching a
     * pronunciation that is simply wrong, which is worse than no feature.
     */
    public function test_a_transcript_that_disagrees_is_thrown_away_entirely(): void
    {
        $aligned = app(AlignWordTimings::class)->handle(
            $this->timings([
                [' The', 0.0, 0.3], [' waiter', 0.3, 0.52], [' spilled', 0.52, 0.88],
                [' coffee', 0.88, 1.24], [' on', 1.24, 1.4], [' the', 1.4, 1.52],
                [' table', 1.52, 1.78], [' cup.', 1.78, 2.1],
            ]),
            self::SENTENCE,
        );

        $this->assertTrue($aligned->isEmpty(), 'Mismatched timings were kept, so a tap could play the wrong word.');
    }

    public function test_a_transcript_of_the_right_length_but_the_wrong_words_is_also_thrown_away(): void
    {
        $aligned = app(AlignWordTimings::class)->handle(
            $this->timings([
                [' The', 0.0, 0.3], [' waiter', 0.3, 0.52], [' dropped', 0.52, 0.88],
                [' coffee', 0.88, 1.24], [' on', 1.24, 1.4], [' the', 1.4, 1.52],
                [' tablecloth.', 1.52, 2.1],
            ]),
            self::SENTENCE,
        );

        $this->assertTrue($aligned->isEmpty());
    }

    /**
     * Timings that run backwards or have no length are a model having a bad
     * moment, and slicing on them plays silence or the neighbouring word.
     */
    public function test_nonsense_timings_are_refused(): void
    {
        $align = app(AlignWordTimings::class);

        $this->assertTrue($align->handle(
            $this->timings([['One', 0.5, 0.4]]),
            'One',
        )->isEmpty(), 'A backwards timing was accepted.');

        $this->assertTrue($align->handle(
            $this->timings([['One', 0.2, 0.2]]),
            'One',
        )->isEmpty(), 'A zero-length timing was accepted.');

        $this->assertTrue($align->handle(
            $this->timings([['One', 0.0, 1.0], ['two', 0.2, 1.5]]),
            'One two',
        )->isEmpty(), 'Overlapping timings were accepted.');
    }

    // ------------------------------------------------------------- the client

    /**
     * Passing the sentence is not a nicety — it is what makes the timings
     * trustworthy at all.
     */
    public function test_the_transcriber_tells_whisper_what_the_clip_says(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => ['segments' => [['words' => [['word' => ' The', 'start' => 0, 'end' => 0.3]]]]],
        ])]);

        (new CloudflareTranscriber)->transcribe('bytes', 'en-GB', self::SENTENCE);

        Http::assertSent(fn ($request) => $request['initial_prompt'] === self::SENTENCE
            // A bare code, not the deck's BCP-47 tag.
            && $request['language'] === 'en');
    }

    public function test_a_reply_without_timings_is_reported_rather_than_returned_empty(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['segments' => []]])]);

        $this->expectExceptionMessage('carried no word timings');

        (new CloudflareTranscriber)->transcribe('bytes', 'en', self::SENTENCE);
    }

    // ---------------------------------------------------------- the whole job

    public function test_the_audio_job_records_where_each_word_falls(): void
    {
        $note = $this->note();

        $this->app->instance(SpeechTranscriber::class, new FakeTranscriber);

        (new SynthesizeNoteAudio($note))->handle(new FakeSpeechSynthesizer, app(TimeNoteWords::class));

        $timings = $note->fresh()->word_timings;

        $this->assertCount(7, $timings);
        $this->assertSame('spilled', $timings[2]['word']);
        $this->assertArrayHasKey('start', $timings[2]);
    }

    /**
     * Timings are an enhancement, never a requirement. Losing them must not
     * lose the audio, and must not stop the note becoming a card.
     */
    public function test_a_failed_transcription_leaves_the_audio_and_the_note_intact(): void
    {
        $note = $this->note();

        $this->app->instance(
            SpeechTranscriber::class,
            (new FakeTranscriber)->willFail(MediaFetchFailed::rejected('Cloudflare transcription', 429, 'busy')),
        );

        (new SynthesizeNoteAudio($note))->handle(new FakeSpeechSynthesizer, app(TimeNoteWords::class));

        $note->refresh();

        $this->assertNull($note->word_timings);
        $this->assertNotNull($note->audio_sentence_path);
        $this->assertSame('ready', $note->audio_status->value);
    }

    public function test_a_transcript_that_disagrees_leaves_no_timings_on_the_note(): void
    {
        $note = $this->note();

        $this->app->instance(
            SpeechTranscriber::class,
            (new FakeTranscriber)->willReturn($this->timings([['Something', 0.0, 0.4], ['else', 0.4, 0.8]])),
        );

        (new SynthesizeNoteAudio($note))->handle(new FakeSpeechSynthesizer, app(TimeNoteWords::class));

        $this->assertNull($note->fresh()->word_timings);
    }

    private function note(): Note
    {
        $user = User::factory()->create();

        return Note::factory()
            ->inDeck(Deck::factory()->for($user)->create())
            ->pending()
            ->create(['sentence' => self::SENTENCE, 'target' => 'spilled']);
    }
}
