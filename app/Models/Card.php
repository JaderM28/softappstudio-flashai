<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'deck_id',
    'front_text',
    'back_text',
    'example_sentence',
    'pronunciation_note',
    'image_url',
    'image_path',
    'image_attribution',
    'audio_front_path',
    'audio_back_path',
    'generation_status',
    'generation_error',
])]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'image_attribution' => 'array',
            'generation_status' => GenerationStatus::class,
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

    /** @return HasOne<CardProgress, $this> */
    public function progress(): HasOne
    {
        return $this->hasOne(CardProgress::class);
    }

    /** @return HasMany<CardReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(CardReview::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Prefer our cached copy so a review session never hits the Unsplash rate
     * limit, and fall back to the remote URL while the cache job is still queued.
     */
    public function imageSrc(): ?string
    {
        if ($this->image_path !== null) {
            return Storage::disk('public')->url($this->image_path);
        }

        return $this->image_url;
    }

    public function audioFrontSrc(): ?string
    {
        return $this->audio_front_path === null
            ? null
            : Storage::disk('public')->url($this->audio_front_path);
    }

    public function audioBackSrc(): ?string
    {
        return $this->audio_back_path === null
            ? null
            : Storage::disk('public')->url($this->audio_back_path);
    }

    /**
     * A card is only worth showing in a review once the AI has filled in the
     * back of it.
     */
    public function isReviewable(): bool
    {
        return $this->generation_status === GenerationStatus::Completed
            && $this->back_text !== null;
    }
}
