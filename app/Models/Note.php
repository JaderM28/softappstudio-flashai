<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\CardType;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One sentence being learned, stored once. The questions asked about it are
 * Cards.
 */
#[Fillable([
    'sentence',
    'target',
    'target_lemma',
    'meaning',
    'translation',
    'pronunciation',
    'image_query',
    'image_url',
    'image_path',
    'image_attribution',
    'audio_sentence_path',
    'audio_target_path',
    'source',
    'content_status',
    'image_status',
    'audio_status',
    'generation_errors',
])]
class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    /**
     * The placeholder a cloze card puts where the target word was.
     */
    public const CLOZE_PLACEHOLDER = '_____';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'image_attribution' => 'array',
            'generation_errors' => 'array',
            'content_status' => AssetStatus::class,
            'image_status' => AssetStatus::class,
            'audio_status' => AssetStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Deck, $this> */
    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    /** @return HasMany<Card, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * The sentence with the target replaced by a blank, which is the prompt a
     * cloze card shows.
     *
     * Matched case-insensitively and on a word boundary so "She borrowed" is
     * blanked but "borrowers" inside another word is left alone.
     */
    public function clozePrompt(): string
    {
        if ($this->target === null || $this->target === '') {
            return $this->sentence;
        }

        $pattern = '/\b'.preg_quote($this->target, '/').'\b/iu';

        $blanked = preg_replace($pattern, self::CLOZE_PLACEHOLDER, $this->sentence, 1);

        // A target that does not appear verbatim — an inflected form the AI
        // returned in dictionary shape, say — leaves the sentence unchanged.
        // Blanking nothing is better than showing a broken prompt.
        return $blanked ?? $this->sentence;
    }

    public function hasCloze(): bool
    {
        return $this->clozePrompt() !== $this->sentence;
    }

    /**
     * Prefer the cached copy so a review session never depends on Unsplash's
     * rate limit, falling back to the remote URL while the cache job is queued.
     */
    public function imageSrc(): ?string
    {
        if ($this->image_path !== null) {
            return Storage::disk('public')->url($this->image_path);
        }

        return $this->image_url;
    }

    public function audioSentenceSrc(): ?string
    {
        return $this->audio_sentence_path === null
            ? null
            : Storage::disk('public')->url($this->audio_sentence_path);
    }

    public function audioTargetSrc(): ?string
    {
        return $this->audio_target_path === null
            ? null
            : Storage::disk('public')->url($this->audio_target_path);
    }

    /**
     * Whether this note can supply everything a card of the given type needs to
     * ask its question.
     */
    public function supports(CardType $type): bool
    {
        foreach ($type->requiredNoteAttributes() as $attribute) {
            if (blank($this->{$attribute})) {
                return false;
            }
        }

        return $type !== CardType::Cloze || $this->hasCloze();
    }

    /**
     * A best-effort dictionary form, used to warn about sentences that teach a
     * word already being studied. The AI supplies this properly; this is the
     * fallback for notes typed by hand.
     */
    public static function guessLemma(string $target): string
    {
        return Str::of($target)->trim()->lower()->singular()->value();
    }
}
