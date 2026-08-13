<?php

namespace Tests\Feature;

use App\Enums\CardState;
use App\Enums\GenerationStatus;
use App\Models\Card;
use App\Models\CardProgress;
use App\Models\CardReview;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deck_belongs_to_a_user_and_has_cards(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->inDeck($deck)->create();

        $this->assertTrue($deck->user->is($deck->user));
        $this->assertTrue($deck->cards->first()->is($card));
        $this->assertSame($deck->user_id, $card->user_id);
    }

    public function test_deck_names_are_unique_per_user_but_not_globally(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Deck::factory()->for($alice)->create(['name' => 'Inglés B2']);

        // Bob may reuse the name.
        $bobsDeck = Deck::factory()->for($bob)->create(['name' => 'Inglés B2']);
        $this->assertSame('Inglés B2', $bobsDeck->name);

        // Alice may not.
        $this->expectException(QueryException::class);
        Deck::factory()->for($alice)->create(['name' => 'Inglés B2']);
    }

    public function test_a_card_survives_the_deletion_of_its_deck(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->inDeck($deck)->create();

        $deck->delete();

        $this->assertNull($card->fresh()->deck_id);
        $this->assertModelExists($card->fresh());
    }

    public function test_deleting_a_user_removes_their_cards_decks_and_progress(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->inDeck($deck)->create();
        CardProgress::factory()->create(['card_id' => $card->id]);
        CardReview::factory()->create(['card_id' => $card->id]);

        $user->delete();

        $this->assertDatabaseCount('decks', 0);
        $this->assertDatabaseCount('cards', 0);
        $this->assertDatabaseCount('card_progress', 0);
        $this->assertDatabaseCount('card_reviews', 0);
    }

    public function test_a_card_has_one_progress_row_and_many_reviews(): void
    {
        $card = Card::factory()->create();
        CardProgress::factory()->create(['card_id' => $card->id]);
        CardReview::factory()->count(3)->create(['card_id' => $card->id]);

        $card->refresh();

        $this->assertInstanceOf(CardProgress::class, $card->progress);
        $this->assertCount(3, $card->reviews);
    }

    public function test_a_card_can_only_have_one_progress_row(): void
    {
        $card = Card::factory()->create();
        CardProgress::factory()->create(['card_id' => $card->id]);

        $this->expectException(QueryException::class);
        CardProgress::factory()->create(['card_id' => $card->id]);
    }

    public function test_card_attributes_are_cast(): void
    {
        $card = Card::factory()->create();

        $this->assertSame(GenerationStatus::Completed, $card->fresh()->generation_status);
        $this->assertIsArray($card->fresh()->image_attribution);
        $this->assertArrayHasKey('photographer', $card->fresh()->image_attribution);
    }

    public function test_easiness_is_a_float_so_sm2_arithmetic_works(): void
    {
        $progress = CardProgress::factory()->create();

        $this->assertIsFloat($progress->fresh()->easiness);
        $this->assertSame(2.5, $progress->fresh()->easiness);
    }

    public function test_card_state_is_derived_from_progress(): void
    {
        $new = CardProgress::factory()->create();
        $learning = CardProgress::factory()->learning()->create();
        $mastered = CardProgress::factory()->mastered()->create();

        $this->assertSame(CardState::New, $new->state);
        $this->assertSame(CardState::Learning, $learning->state);
        $this->assertSame(CardState::Mastered, $mastered->state);
    }

    public function test_the_due_scope_only_returns_cards_at_or_past_their_review_date(): void
    {
        $dueNow = CardProgress::factory()->create(['next_review_at' => now()->subMinute()]);
        CardProgress::factory()->dueIn(3)->create();

        $due = CardProgress::query()->due()->get();

        $this->assertCount(1, $due);
        $this->assertTrue($due->first()->is($dueNow));
    }

    public function test_a_pending_card_is_not_reviewable_until_generation_completes(): void
    {
        $this->assertFalse(Card::factory()->pending()->create()->isReviewable());
        $this->assertFalse(Card::factory()->failed()->create()->isReviewable());
        $this->assertTrue(Card::factory()->create()->isReviewable());
    }

    public function test_a_card_prefers_its_cached_image_over_the_remote_url(): void
    {
        $cached = Card::factory()->create([
            'image_path' => 'cards/images/apple.jpg',
            'image_url' => 'https://images.unsplash.com/apple',
        ]);
        $notYetCached = Card::factory()->create([
            'image_path' => null,
            'image_url' => 'https://images.unsplash.com/apple',
        ]);

        $this->assertStringContainsString('cards/images/apple.jpg', $cached->imageSrc());
        $this->assertSame('https://images.unsplash.com/apple', $notYetCached->imageSrc());
    }
}
