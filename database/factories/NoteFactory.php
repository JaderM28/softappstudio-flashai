<?php

namespace Database\Factories;

use App\Enums\AssetStatus;
use App\Models\Deck;
use App\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    /**
     * Real sentences rather than fake()->sentence(), because a cloze prompt is
     * only meaningful if the target actually appears in the sentence.
     *
     * @var array<int, array{sentence: string, target: string, lemma: string, meaning: string, translation: string}>
     */
    private const SAMPLES = [
        [
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'borrowed',
            'lemma' => 'borrow',
            'meaning' => 'took something to use and give back later',
            'translation' => 'Ayer me pidió prestado el paraguas.',
        ],
        [
            'sentence' => 'He is always running late for work.',
            'target' => 'running late',
            'lemma' => 'run late',
            'meaning' => 'arriving later than planned',
            'translation' => 'Siempre llega tarde al trabajo.',
        ],
        [
            'sentence' => 'They called off the meeting at the last minute.',
            'target' => 'called off',
            'lemma' => 'call off',
            'meaning' => 'cancelled something that was planned',
            'translation' => 'Cancelaron la reunión a último momento.',
        ],
        [
            'sentence' => 'The bakery on the corner closes early on Sundays.',
            'target' => 'bakery',
            'lemma' => 'bakery',
            'meaning' => 'a shop that makes and sells bread and cakes',
            'translation' => 'La panadería de la esquina cierra temprano los domingos.',
        ],
    ];

    /**
     * Defaults to a fully generated note, since that is the state most tests
     * and screens care about.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sample = fake()->randomElement(self::SAMPLES);
        $slug = Str::slug($sample['lemma']);

        return [
            'deck_id' => Deck::factory(),
            'user_id' => fn (array $attributes) => Deck::findOrFail($attributes['deck_id'])->user_id,
            'sentence' => $sample['sentence'],
            'target' => $sample['target'],
            'target_lemma' => $sample['lemma'],
            'meaning' => $sample['meaning'],
            'translation' => $sample['translation'],
            'pronunciation' => null,
            'image_query' => 'street scene daylight',
            'image_url' => 'https://pixabay.com/get/'.fake()->numerify('##########').'.jpg',
            'image_path' => "notes/images/{$slug}.jpg",
            'image_attribution' => [
                'photographer' => fake()->name(),
                'profile_url' => 'https://pixabay.com/users/'.fake()->userName().'/',
                'source' => 'pixabay',
            ],
            'audio_sentence_path' => "notes/audio/{$slug}-sentence.mp3",
            'audio_target_path' => "notes/audio/{$slug}-target.mp3",
            'source' => null,
            'content_status' => AssetStatus::Ready,
            'image_status' => AssetStatus::Ready,
            'audio_status' => AssetStatus::Ready,
            'generation_errors' => null,
        ];
    }

    /**
     * A sentence the user has just submitted, before any job has run.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'meaning' => null,
            'translation' => null,
            'pronunciation' => null,
            'image_query' => null,
            'image_url' => null,
            'image_path' => null,
            'image_attribution' => null,
            'audio_sentence_path' => null,
            'audio_target_path' => null,
            'content_status' => AssetStatus::Pending,
            'image_status' => AssetStatus::Pending,
            'audio_status' => AssetStatus::Pending,
        ]);
    }

    /**
     * Text and audio came through, the picture did not — the common partial
     * failure, since image search rate-limits long before the rest.
     */
    public function withoutImage(): static
    {
        return $this->state(fn (array $attributes) => [
            'image_url' => null,
            'image_path' => null,
            'image_attribution' => null,
            'image_status' => AssetStatus::Failed,
            'generation_errors' => ['image' => 'Pixabay rate limit reached'],
        ]);
    }

    public function withoutAudio(): static
    {
        return $this->state(fn (array $attributes) => [
            'audio_sentence_path' => null,
            'audio_target_path' => null,
            'audio_status' => AssetStatus::Failed,
            'generation_errors' => ['audio' => 'TTS quota exceeded'],
        ]);
    }

    public function inDeck(Deck $deck): static
    {
        return $this->state(fn (array $attributes) => [
            'deck_id' => $deck->id,
            'user_id' => $deck->user_id,
        ]);
    }

    /**
     * A note whose target does not appear verbatim in the sentence, so no cloze
     * prompt can be built from it.
     */
    public function withoutClozeTarget(): static
    {
        return $this->state(fn (array $attributes) => [
            'sentence' => 'She borrowed my umbrella yesterday.',
            'target' => 'lend',
            'target_lemma' => 'lend',
        ]);
    }
}
