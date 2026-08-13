<?php

namespace App\Models;

use App\Enums\CardState;
use Database\Factories\CardProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The current SM-2 state of a single card. One row per card; the history of how
 * it got here lives in card_reviews.
 */
#[Fillable([
    'repetitions',
    'easiness',
    'interval_days',
    'next_review_at',
    'last_quality',
    'last_reviewed_at',
])]
class CardProgress extends Model
{
    /** @use HasFactory<CardProgressFactory> */
    use HasFactory;

    /**
     * Eloquent would pluralise this to "card_progresses".
     */
    protected $table = 'card_progress';

    /**
     * The values SM-2 starts a brand new card with.
     */
    public const DEFAULT_EASINESS = 2.5;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // float, not decimal:2, because the SM-2 recurrence multiplies by it
            // and a string cast would silently coerce on every calculation.
            'easiness' => 'float',
            'repetitions' => 'integer',
            'interval_days' => 'integer',
            'last_quality' => 'integer',
            'next_review_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Card, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeDue(Builder $query, ?Carbon $at = null): void
    {
        $query->where('next_review_at', '<=', $at ?? now());
    }

    /**
     * @return Attribute<CardState, never>
     */
    protected function state(): Attribute
    {
        return Attribute::get(fn (): CardState => CardState::fromProgress(
            $this->repetitions,
            $this->interval_days,
        ));
    }
}
