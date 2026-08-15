<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\CardQueue;
use App\Enums\CardState;
use App\Enums\CardType;
use App\Models\Card;
use App\Models\CardReview;
use App\Models\Deck;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- decks

    public function test_a_new_deck_generates_the_default_card_types(): void
    {
        $deck = new Deck(['name' => 'Inglés B2']);
        $deck->user_id = User::factory()->create()->id;
        $deck->save();

        $this->assertTrue($deck->generates(CardType::Cloze));
        $this->assertTrue($deck->generates(CardType::Listening));
        $this->assertFalse($deck->generates(CardType::Production));
    }

    public function test_deck_names_are_unique_per_user_but_not_globally(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Deck::factory()->for($alice)->create(['name' => 'Inglés B2']);
        $bobsDeck = Deck::factory()->for($bob)->create(['name' => 'Inglés B2']);

        $this->assertSame('Inglés B2', $bobsDeck->name);

        $this->expectException(UniqueConstraintViolationException::class);
        Deck::factory()->for($alice)->create(['name' => 'Inglés B2']);
    }

    public function test_deck_limits_fall_back_to_the_configured_defaults(): void
    {
        config(['flashai.session.new_per_day' => 5, 'flashai.session.reviews_per_day' => 120]);

        $unset = Deck::factory()->create();
        $overridden = Deck::factory()->withLimits(newPerDay: 20, reviewsPerDay: 300)->create();

        $this->assertSame(5, $unset->newPerDay());
        $this->assertSame(120, $unset->reviewsPerDay());
        $this->assertSame(20, $overridden->newPerDay());
        $this->assertSame(300, $overridden->reviewsPerDay());
    }

    public function test_a_user_can_find_their_default_deck(): void
    {
        $user = User::factory()->create();
        Deck::factory()->for($user)->create();
        $inbox = Deck::factory()->for($user)->isDefault()->create();

        $this->assertTrue($user->defaultDeck()->is($inbox));
    }

    // ---------------------------------------------------------------- notes

    public function test_a_note_belongs_to_a_deck_and_a_user(): void
    {
        $deck = Deck::factory()->create();
        $note = Note::factory()->inDeck($deck)->create();

        $this->assertTrue($note->deck->is($deck));
        $this->assertSame($deck->user_id, $note->user_id);
    }

    public function test_deleting_a_deck_takes_its_notes_and_cards(): void
    {
        $deck = Deck::factory()->create();
        $note = Note::factory()->inDeck($deck)->create();
        Card::factory()->inNote($note)->create();

        $deck->delete();

        $this->assertDatabaseCount('notes', 0);
        $this->assertDatabaseCount('cards', 0);
    }

    public function test_deleting_a_user_removes_everything_they_own(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $note = Note::factory()->inDeck($deck)->create();
        $card = Card::factory()->inNote($note)->create();
        CardReview::factory()->forCard($card)->create();

        $user->delete();

        $this->assertDatabaseCount('decks', 0);
        $this->assertDatabaseCount('notes', 0);
        $this->assertDatabaseCount('cards', 0);
        $this->assertDatabaseCount('card_reviews', 0);
    }

    public function test_a_note_keeps_both_a_meaning_and_a_translation(): void
    {
        $note = Note::factory()->create();

        // Both are stored so switching what the review screen shows never means
        // regenerating anything.
        $this->assertNotNull($note->meaning);
        $this->assertNotNull($note->translation);
    }

    public function test_asset_statuses_are_tracked_independently(): void
    {
        $note = Note::factory()->withoutImage()->create();

        $this->assertSame(AssetStatus::Ready, $note->content_status);
        $this->assertSame(AssetStatus::Failed, $note->image_status);
        $this->assertSame(AssetStatus::Ready, $note->audio_status);
        $this->assertSame('Pixabay rate limit reached', $note->generation_errors['image']);
    }

    // ------------------------------------------------------------ cloze prompt

    public function test_the_cloze_prompt_blanks_out_the_target(): void
    {
        $note = Note::factory()->create([
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'borrowed',
        ]);

        $this->assertSame('She _____ my umbrella yesterday.', $note->clozePrompt());
        $this->assertTrue($note->hasCloze());
    }

    public function test_the_cloze_prompt_handles_multi_word_targets(): void
    {
        $note = Note::factory()->create([
            'sentence' => 'They called off the meeting at the last minute.',
            'target' => 'called off',
        ]);

        $this->assertSame('They _____ the meeting at the last minute.', $note->clozePrompt());
    }

    public function test_the_cloze_prompt_ignores_case(): void
    {
        $note = Note::factory()->create([
            'sentence' => 'Borrowing money is easy; giving it back is not.',
            'target' => 'borrowing',
        ]);

        $this->assertSame('_____ money is easy; giving it back is not.', $note->clozePrompt());
    }

    /**
     * Without a word boundary, blanking "own" would gut "downtown".
     */
    public function test_the_cloze_prompt_only_matches_whole_words(): void
    {
        $note = Note::factory()->create([
            'sentence' => 'They own a flat downtown.',
            'target' => 'own',
        ]);

        $this->assertSame('They _____ a flat downtown.', $note->clozePrompt());
    }

    public function test_the_cloze_prompt_blanks_only_the_first_occurrence(): void
    {
        $note = Note::factory()->create([
            'sentence' => 'I borrowed it because she borrowed mine.',
            'target' => 'borrowed',
        ]);

        $this->assertSame('I _____ it because she borrowed mine.', $note->clozePrompt());
    }

    public function test_a_target_that_is_not_in_the_sentence_leaves_it_untouched(): void
    {
        $note = Note::factory()->withoutClozeTarget()->create();

        $this->assertSame('She borrowed my umbrella yesterday.', $note->clozePrompt());
        $this->assertFalse($note->hasCloze());
    }

    // ------------------------------------------------------- note supports type

    public function test_a_note_without_audio_cannot_support_a_listening_card(): void
    {
        $note = Note::factory()->withoutAudio()->create();

        $this->assertTrue($note->supports(CardType::Cloze));
        $this->assertFalse($note->supports(CardType::Listening));
    }

    public function test_a_note_whose_target_is_missing_cannot_support_a_cloze_card(): void
    {
        $note = Note::factory()->withoutClozeTarget()->create();

        $this->assertFalse($note->supports(CardType::Cloze));
        $this->assertTrue($note->supports(CardType::Listening));
    }

    public function test_a_note_without_an_image_still_supports_every_card_type(): void
    {
        // The picture helps, but a sentence with audio is entirely studiable
        // without it — that is the whole reason image status is tracked apart.
        $note = Note::factory()->withoutImage()->create();

        $this->assertTrue($note->supports(CardType::Cloze));
        $this->assertTrue($note->supports(CardType::Listening));
    }

    // ---------------------------------------------------------------- cards

    public function test_a_note_can_only_have_one_card_of_each_type(): void
    {
        $note = Note::factory()->create();
        Card::factory()->inNote($note)->ofType(CardType::Cloze)->create();
        Card::factory()->inNote($note)->ofType(CardType::Listening)->create();

        $this->assertSame(2, $note->cards()->count());

        $this->expectException(UniqueConstraintViolationException::class);
        Card::factory()->inNote($note)->ofType(CardType::Cloze)->create();
    }

    public function test_a_card_finds_its_siblings_but_not_itself(): void
    {
        $note = Note::factory()->create();
        $cloze = Card::factory()->inNote($note)->ofType(CardType::Cloze)->create();
        $listening = Card::factory()->inNote($note)->ofType(CardType::Listening)->create();

        // A card from another note must not show up as a sibling.
        Card::factory()->create();

        $siblings = $cloze->siblings()->get();

        $this->assertCount(1, $siblings);
        $this->assertTrue($siblings->first()->is($listening));
    }

    public function test_card_scheduling_attributes_are_cast(): void
    {
        $card = Card::factory()->review()->create()->fresh();

        $this->assertSame(CardType::Cloze, $card->type);
        $this->assertSame(CardQueue::Review, $card->queue);
        $this->assertIsFloat($card->easiness);
        $this->assertSame(2.5, $card->easiness);
    }

    public function test_card_state_is_derived_from_queue_and_interval(): void
    {
        $this->assertSame(CardState::New, Card::factory()->create()->state);
        $this->assertSame(CardState::Learning, Card::factory()->learning()->create()->state);
        $this->assertSame(CardState::Learning, Card::factory()->review(6)->create()->state);
        $this->assertSame(CardState::Mastered, Card::factory()->mastered()->create()->state);
        $this->assertSame(CardState::Suspended, Card::factory()->suspended()->create()->state);
    }

    public function test_a_card_becomes_mastered_exactly_at_the_maturity_threshold(): void
    {
        $justUnder = Card::factory()->review(CardState::MASTERED_INTERVAL_DAYS - 1)->create();
        $atThreshold = Card::factory()->review(CardState::MASTERED_INTERVAL_DAYS)->create();

        $this->assertSame(CardState::Learning, $justUnder->state);
        $this->assertSame(CardState::Mastered, $atThreshold->state);
    }

    // ------------------------------------------------------------- due scope

    public function test_the_due_scope_returns_cards_at_or_past_their_review_date(): void
    {
        $due = Card::factory()->dueAt(now()->subMinute())->create();
        Card::factory()->dueIn(3)->create();

        $found = Card::query()->due()->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($due));
    }

    public function test_the_due_scope_skips_suspended_cards(): void
    {
        Card::factory()->suspended()->dueAt(now()->subDay())->create();

        $this->assertSame(0, Card::query()->due()->count());
    }

    public function test_the_due_scope_skips_cards_buried_behind_a_sibling(): void
    {
        Card::factory()
            ->dueAt(now()->subMinute())
            ->buriedUntil(now()->addHours(6))
            ->create();

        $this->assertSame(0, Card::query()->due()->count());
    }

    public function test_a_card_returns_once_its_burial_expires(): void
    {
        $card = Card::factory()
            ->dueAt(now()->subDay())
            ->buriedUntil(now()->subHour())
            ->create();

        $this->assertFalse($card->isBuried());
        $this->assertSame(1, Card::query()->due()->count());
    }

    public function test_the_in_queue_scope_filters_by_queue(): void
    {
        Card::factory()->count(2)->create();
        Card::factory()->review()->create();

        $this->assertSame(2, Card::query()->inQueue(CardQueue::New)->count());
        $this->assertSame(1, Card::query()->inQueue(CardQueue::Review)->count());
    }

    // -------------------------------------------------------------- reviews

    public function test_a_review_records_the_state_on_both_sides(): void
    {
        $card = Card::factory()->create();
        $review = CardReview::factory()->forCard($card)->create([
            'queue_before' => CardQueue::New,
            'queue_after' => CardQueue::Learning,
            'interval_days_before' => 0,
            'interval_days_after' => 1,
        ]);

        $this->assertSame(CardQueue::New, $review->queue_before);
        $this->assertSame(CardQueue::Learning, $review->queue_after);
        $this->assertSame($card->user_id, $review->user_id);
    }

    public function test_a_review_can_describe_the_state_to_restore_on_undo(): void
    {
        $review = CardReview::factory()->create([
            'queue_before' => CardQueue::Review,
            'learning_step_before' => null,
            'repetitions_before' => 4,
            'easiness_before' => 2.35,
            'interval_days_before' => 18,
        ]);

        $this->assertSame([
            'queue' => CardQueue::Review,
            'learning_step' => null,
            'repetitions' => 4,
            'easiness' => 2.35,
            'interval_days' => 18,
        ], $review->undoState());
    }

    public function test_reviews_survive_alongside_many_per_card(): void
    {
        $card = Card::factory()->create();
        CardReview::factory()->count(4)->forCard($card)->create();

        $this->assertCount(4, $card->reviews);
    }

    public function test_ownership_columns_are_not_mass_assignable(): void
    {
        $attacker = User::factory()->create();

        $card = new Card(['user_id' => $attacker->id, 'type' => CardType::Cloze]);

        // user_id must come from the note, never from request input.
        $this->assertNull($card->user_id);
    }

    public function test_the_schema_rejects_a_card_without_an_owner(): void
    {
        $note = Note::factory()->create();

        $this->expectException(QueryException::class);

        $card = new Card(['type' => CardType::Cloze]);
        $card->note_id = $note->id;
        $card->deck_id = $note->deck_id;
        $card->next_review_at = now();
        $card->save();
    }
}
