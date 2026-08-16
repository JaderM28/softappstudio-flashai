<?php

namespace Tests\Feature;

use App\Enums\CardQueue;
use App\Enums\ReviewGrade;
use App\Models\Card;
use App\Models\Deck;
use App\Models\Note;
use App\Models\User;
use App\Services\Scheduler;
use App\Support\IntervalLabel;
use App\Support\SchedulingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What the four buttons promise, and what the end of a session means.
 */
class SessionEndingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'flashai.scheduler.learning_steps' => [1, 10],
            'flashai.session.learn_ahead_minutes' => 20,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function cardFor(User $user, array $state = []): Card
    {
        $note = Note::factory()->inDeck(Deck::factory()->for($user)->create())->create();

        return Card::factory()->inNote($note)->create($state);
    }

    // ------------------------------------------------------ button previews

    /**
     * The preview has to be what actually happens, or it is worse than nothing.
     */
    public function test_the_preview_matches_what_grading_really_does(): void
    {
        config(['flashai.scheduler.fuzz_percent' => 0]);

        $state = new SchedulingState(
            queue: CardQueue::Review,
            learningStep: null,
            repetitions: 4,
            easiness: 2.5,
            intervalDays: 10,
            lapses: 0,
            nextReviewAt: now(),
        );

        $scheduler = app(Scheduler::class);
        $previews = $scheduler->previewAll($state, now());

        foreach (ReviewGrade::cases() as $grade) {
            $this->assertSame(
                $scheduler->grade($state, $grade, now())->intervalDays,
                $previews[$grade->value]->intervalDays,
                "The preview for {$grade->name} disagreed with the real grade.",
            );
        }
    }

    /**
     * Intervals are jittered by ±5%, which is right for scheduling and wrong
     * for a label: a button that reads "8d" and then schedules 9 is a button
     * telling small lies all day.
     */
    public function test_the_preview_is_stable_even_with_fuzz_turned_up(): void
    {
        config(['flashai.scheduler.fuzz_percent' => 50]);

        $state = new SchedulingState(
            queue: CardQueue::Review,
            learningStep: null,
            repetitions: 4,
            easiness: 2.5,
            intervalDays: 30,
            lapses: 0,
            nextReviewAt: now(),
        );

        $scheduler = app(Scheduler::class);

        $seen = collect(range(1, 20))
            ->map(fn () => $scheduler->previewAll($state, now())[ReviewGrade::Good->value]->intervalDays)
            ->unique();

        $this->assertCount(1, $seen, 'The preview moved between calls, so it was fuzzed.');
    }

    /**
     * And the fuzz has to survive previewing — a preview that switched it off
     * permanently would clump every card added on the same day forever.
     */
    public function test_previewing_does_not_disable_the_fuzz_afterwards(): void
    {
        config(['flashai.scheduler.fuzz_percent' => 50]);

        $state = new SchedulingState(
            queue: CardQueue::Review,
            learningStep: null,
            repetitions: 4,
            easiness: 2.5,
            intervalDays: 100,
            lapses: 0,
            nextReviewAt: now(),
        );

        $scheduler = app(Scheduler::class);
        $scheduler->previewAll($state, now());

        $seen = collect(range(1, 30))
            ->map(fn () => $scheduler->grade($state, ReviewGrade::Good, now())->intervalDays)
            ->unique();

        $this->assertGreaterThan(1, $seen->count(), 'Grading stopped fuzzing after a preview.');
    }

    public function test_the_buttons_show_what_each_one_would_do(): void
    {
        config(['flashai.scheduler.fuzz_percent' => 0]);

        $user = User::factory()->create();
        $this->cardFor($user, ['queue' => CardQueue::New, 'next_review_at' => now()]);

        $response = $this->actingAs($user)->get(route('review.show'))->assertOk();

        $previews = $response->viewData('previews');

        // A new card: Again and Hard sit on the first step, Good on the second,
        // Easy skips straight to the graduating interval.
        $this->assertSame('1m', $previews[ReviewGrade::Again->value]);
        $this->assertSame('1m', $previews[ReviewGrade::Hard->value]);
        $this->assertSame('10m', $previews[ReviewGrade::Good->value]);
        $this->assertSame('4d', $previews[ReviewGrade::Easy->value]);

        $response->assertSee('10m')->assertSee('4d');
    }

    public function test_interval_labels_read_the_way_a_button_needs_them_to(): void
    {
        $from = now();

        $this->assertSame('1m', IntervalLabel::between($from, $from->copy()->addMinute()));
        $this->assertSame('10m', IntervalLabel::between($from, $from->copy()->addMinutes(10)));
        $this->assertSame('2h', IntervalLabel::between($from, $from->copy()->addHours(2)));
        $this->assertSame('1d', IntervalLabel::between($from, $from->copy()->addDay()));
        $this->assertSame('4d', IntervalLabel::between($from, $from->copy()->addDays(4)));
        $this->assertSame('2mo', IntervalLabel::between($from, $from->copy()->addDays(61)));
        $this->assertSame('1y', IntervalLabel::between($from, $from->copy()->addDays(365)));

        // Already due, which is what a card mid-session looks like.
        $this->assertSame('<1m', IntervalLabel::between($from, $from->copy()->subMinute()));
    }

    // ---------------------------------------------------- the three endings

    public function test_an_empty_collection_says_to_add_something(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('review.show'))
            ->assertOk()
            ->assertSee('Nothing to study yet')
            ->assertSee('Add your first sentence');
    }

    /**
     * The ending that was invisible before: sentences exist, but none of them
     * has both its picture and its audio yet.
     */
    public function test_sentences_waiting_for_their_media_are_reported(): void
    {
        $user = User::factory()->create();
        Note::factory()->inDeck(Deck::factory()->for($user)->create())->pending()->create();

        $this->actingAs($user)
            ->get(route('review.show'))
            ->assertOk()
            ->assertSee('Nothing ready yet')
            ->assertSee('still waiting');
    }

    /**
     * And the one the whole learn-ahead change is about: a card coming back in
     * minutes is not the same as a session that is over.
     */
    public function test_a_card_coming_back_soon_says_when(): void
    {
        $user = User::factory()->create();
        $this->cardFor($user, [
            'queue' => CardQueue::Learning,
            'next_review_at' => now()->addMinutes(35),
        ]);

        $this->actingAs($user)
            ->get(route('review.show'))
            ->assertOk()
            ->assertSee('Back in 35m')
            ->assertSee('refresh itself');
    }

    /**
     * Hitting the daily limit used to look exactly like running out of cards,
     * which is the difference between "well done" and "come back tomorrow".
     */
    public function test_the_daily_limit_says_how_much_is_waiting_behind_it(): void
    {
        config(['flashai.session.reviews_per_day' => 1]);

        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();

        foreach (range(1, 3) as $i) {
            $note = Note::factory()->inDeck($deck)->create();
            Card::factory()->inNote($note)->create([
                'queue' => CardQueue::Review,
                'interval_days' => 5,
                'next_review_at' => now()->subDay(),
            ]);
        }

        $first = Card::query()->first();
        $this->actingAs($user)->post(route('review.grade', $first), ['grade' => ReviewGrade::Good->value]);

        $this->actingAs($user)
            ->get(route('review.show'))
            ->assertOk()
            // Escaped, not raw: the apostrophes arrive as &#039; in the HTML
            // and assertSee's default escaping is what matches them.
            ->assertSee("That's today's session")
            ->assertSee("waiting behind today's limit");
    }
    /**
     * "Nothing left due" on its own is not an answer to "when do I come back".
     * The screen already knows; it just never said.
     */
    public function test_a_finished_session_says_when_the_next_card_is_due(): void
    {
        $user = User::factory()->create();
        $this->cardFor($user, [
            'queue' => CardQueue::Review,
            'interval_days' => 3,
            'next_review_at' => now()->addDays(3),
        ]);

        $this->actingAs($user)
            ->get(route('review.show'))
            ->assertOk()
            ->assertSee('Nothing left due')
            ->assertSee('Next card in 3d');
    }

}
