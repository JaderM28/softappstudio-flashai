<?php

namespace App\Models;

use Database\Factories\CardReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per grade the user has given. Append-only: nothing updates a review
 * after the fact, which is what lets the stats screen trust it.
 */
// card_id and user_id are deliberately absent: ownership is derived from the
// card being graded, never from anything the request can influence.
#[Fillable([
    'quality',
    'repetitions_before',
    'repetitions_after',
    'easiness_before',
    'easiness_after',
    'interval_days_before',
    'interval_days_after',
    'reviewed_at',
])]
class CardReview extends Model
{
    /** @use HasFactory<CardReviewFactory> */
    use HasFactory;

    /**
     * reviewed_at is the only time this table cares about.
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quality' => 'integer',
            'repetitions_before' => 'integer',
            'repetitions_after' => 'integer',
            'easiness_before' => 'float',
            'easiness_after' => 'float',
            'interval_days_before' => 'integer',
            'interval_days_after' => 'integer',
            'reviewed_at' => 'datetime',
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
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->whereBetween('reviewed_at', [$from, $to]);
    }
}
