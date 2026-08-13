<?php

namespace App\Models;

use App\Enums\CardQueue;
use App\Enums\ReviewGrade;
use Database\Factories\CardReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per grade given. Append-only: nothing updates a review after the
 * fact, which is what lets the stats screen trust it.
 */
#[Fillable([
    'grade',
    'queue_before',
    'queue_after',
    'learning_step_before',
    'learning_step_after',
    'repetitions_before',
    'repetitions_after',
    'easiness_before',
    'easiness_after',
    'interval_days_before',
    'interval_days_after',
    'lapses_after',
    'duration_ms',
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
            'grade' => ReviewGrade::class,
            'queue_before' => CardQueue::class,
            'queue_after' => CardQueue::class,
            'learning_step_before' => 'integer',
            'learning_step_after' => 'integer',
            'repetitions_before' => 'integer',
            'repetitions_after' => 'integer',
            'easiness_before' => 'float',
            'easiness_after' => 'float',
            'interval_days_before' => 'integer',
            'interval_days_after' => 'integer',
            'lapses_after' => 'integer',
            'duration_ms' => 'integer',
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

    /**
     * The state to restore when this review is undone.
     *
     * @return array<string, mixed>
     */
    public function undoState(): array
    {
        return [
            'queue' => $this->queue_before,
            'learning_step' => $this->learning_step_before,
            'repetitions' => $this->repetitions_before,
            'easiness' => $this->easiness_before,
            'interval_days' => $this->interval_days_before,
        ];
    }
}
