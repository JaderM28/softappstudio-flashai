<?php

namespace Tests\Feature;

use App\Actions\EnsureDefaultDeck;
use App\Enums\CardQueue;
use App\Enums\CardType;
use App\Models\Card;
use App\Models\Deck;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'borrowed',
            'meaning' => 'took something to use and give back later',
            'translation' => 'Ayer me pidió prestado el paraguas.',
        ], $overrides);
    }

    public function test_a_guest_cannot_reach_the_sentence_screens(): void
    {
        $this->get(route('notes.index'))->assertRedirect(route('login'));
        $this->get(route('notes.create'))->assertRedirect(route('login'));
    }

    public function test_the_add_screen_renders_with_a_deck_to_choose(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('notes.create'))
            ->assertOk()
            ->assertSee('Sentence')
            ->assertSee(EnsureDefaultDeck::NAME);
    }

    public function test_the_edit_screen_renders_with_the_cards_made_from_the_sentence(): void
    {
        $user = User::factory()->create();
        $note = Note::factory()->inDeck(Deck::factory()->for($user)->create())->create();
        Card::factory()->inNote($note)->ofType(CardType::Cloze)->create();

        $this->actingAs($user)
            ->get(route('notes.edit', $note))
            ->assertOk()
            ->assertSee($note->target)
            ->assertSee(CardType::Cloze->label());
    }

    public function test_saving_a_sentence_creates_a_note_and_its_cards(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('notes.store'), $this->payload())
            ->assertRedirect();

        $note = Note::query()->sole();

        $this->assertSame('She borrowed my umbrella yesterday.', $note->sentence);
        $this->assertSame('borrowed', $note->target);
        $this->assertSame($user->id, $note->user_id);

        // Only cloze: a hand-typed sentence has no audio yet, so no listening
        // card can be asked from it.
        $this->assertSame([CardType::Cloze->value], $note->cards->pluck('type.value')->all());
    }

    public function test_a_sentence_lands_in_the_default_deck_when_none_is_chosen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('notes.store'), $this->payload());

        $note = Note::query()->sole();

        $this->assertTrue($note->deck->is_default);
        $this->assertSame($user->id, $note->deck->user_id);
    }

    public function test_a_sentence_can_be_filed_into_a_chosen_deck(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create(['name' => 'Medical']);

        $this->actingAs($user)
            ->post(route('notes.store'), $this->payload(['deck_id' => $deck->id]));

        $this->assertSame($deck->id, Note::query()->sole()->deck_id);
    }

    public function test_a_sentence_cannot_be_filed_into_someone_elses_deck(): void
    {
        $user = User::factory()->create();
        $othersDeck = Deck::factory()->create();

        $this->actingAs($user)
            ->post(route('notes.store'), $this->payload(['deck_id' => $othersDeck->id]))
            ->assertSessionHasErrors('deck_id');

        $this->assertDatabaseCount('notes', 0);
    }

    public function test_the_sentence_and_target_are_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('notes.store'), ['sentence' => '', 'target' => ''])
            ->assertSessionHasErrors(['sentence', 'target']);
    }

    /**
     * A target that is not in the sentence produces no cloze card, so the user
     * has to be told rather than left with a sentence that never comes up.
     */
    public function test_a_target_missing_from_the_sentence_is_reported(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('notes.store'), $this->payload(['target' => 'lend']))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'no card'));

        $this->assertSame(0, Note::query()->sole()->cards()->count());
    }

    public function test_a_lemma_is_guessed_so_duplicates_can_be_spotted_later(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('notes.store'), $this->payload([
            'sentence' => 'The bakeries open early.',
            'target' => 'bakeries',
        ]));

        $this->assertSame('bakery', Note::query()->sole()->target_lemma);
    }

    public function test_a_user_only_sees_their_own_sentences(): void
    {
        $user = User::factory()->create();
        $mine = Note::factory()->inDeck(Deck::factory()->for($user)->create())->create([
            'sentence' => 'Mine to study.',
        ]);
        Note::factory()->create(['sentence' => 'Somebody else entirely.']);

        $this->actingAs($user)
            ->get(route('notes.index'))
            ->assertOk()
            ->assertSee($mine->sentence)
            ->assertDontSee('Somebody else entirely.');
    }

    public function test_sentences_can_be_searched(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        Note::factory()->inDeck($deck)->create(['sentence' => 'She borrowed my umbrella.', 'target' => 'borrowed']);
        Note::factory()->inDeck($deck)->create(['sentence' => 'They called off the meeting.', 'target' => 'called off']);

        $this->actingAs($user)
            ->get(route('notes.index', ['q' => 'umbrella']))
            ->assertOk()
            ->assertSee('umbrella')
            ->assertDontSee('called off the meeting');
    }

    public function test_editing_a_sentence_updates_it(): void
    {
        $user = User::factory()->create();
        $note = Note::factory()->inDeck(Deck::factory()->for($user)->create())->create();

        $this->actingAs($user)
            ->patch(route('notes.update', $note), $this->payload([
                'sentence' => 'She borrowed my bike yesterday.',
            ]))
            ->assertRedirect(route('notes.index'));

        $this->assertSame('She borrowed my bike yesterday.', $note->fresh()->sentence);
    }

    /**
     * Fixing a typo so the target finally appears must produce the card that
     * could not be built before.
     */
    public function test_fixing_a_sentence_creates_the_card_it_could_not_build(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $note = Note::factory()->inDeck($deck)->withoutClozeTarget()->withoutAudio()->create();

        $this->assertSame(0, $note->cards()->count());

        $this->actingAs($user)->patch(route('notes.update', $note), $this->payload([
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'borrowed',
        ]));

        $this->assertSame(
            [CardType::Cloze->value],
            $note->fresh()->cards->pluck('type.value')->all(),
        );
    }

    /**
     * Cards are only ever added. Editing a sentence must not throw away the
     * schedule the cards attached to it have built up.
     */
    public function test_editing_a_sentence_keeps_the_cards_it_already_had(): void
    {
        $user = User::factory()->create();
        $note = Note::factory()->inDeck(Deck::factory()->for($user)->create())->create();
        $card = Card::factory()->inNote($note)->review(intervalDays: 30)->create();

        $this->actingAs($user)->patch(route('notes.update', $note), $this->payload([
            'sentence' => 'She borrowed my bike yesterday.',
        ]));

        $card->refresh();

        // Same row, same schedule — the edit did not replace it.
        $this->assertSame(30, $card->interval_days);
        $this->assertSame(CardQueue::Review, $card->queue);
        $this->assertTrue($note->fresh()->cards->contains($card));
    }

    public function test_a_user_cannot_edit_someone_elses_sentence(): void
    {
        $user = User::factory()->create();
        $others = Note::factory()->create();

        $this->actingAs($user)->get(route('notes.edit', $others))->assertForbidden();
        $this->actingAs($user)->patch(route('notes.update', $others), $this->payload())->assertForbidden();
        $this->actingAs($user)->delete(route('notes.destroy', $others))->assertForbidden();
    }

    public function test_deleting_a_sentence_takes_its_cards(): void
    {
        $user = User::factory()->create();
        $note = Note::factory()->inDeck(Deck::factory()->for($user)->create())->create();
        Card::factory()->inNote($note)->create();

        $this->actingAs($user)
            ->delete(route('notes.destroy', $note))
            ->assertRedirect(route('notes.index'));

        $this->assertDatabaseCount('notes', 0);
        $this->assertDatabaseCount('cards', 0);
    }

    public function test_registering_creates_a_default_deck(): void
    {
        $this->post(route('register'), [
            'name' => 'Jader',
            'email' => 'jader@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::query()->where('email', 'jader@example.com')->sole();

        $this->assertNotNull($user->defaultDeck());
        $this->assertTrue($user->defaultDeck()->generates(CardType::Cloze));
    }
}
