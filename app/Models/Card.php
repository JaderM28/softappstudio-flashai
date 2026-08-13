<?php

namespace App\Models;

use App\Enums\CardQueue;
use App\Enums\CardState;
use App\Enums\CardType;
use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One question about a note, carrying its own schedule.
 */
#[Fillable([
    'type',
    'queue',
    'learning_step',
    'repetitions',
    'easiness',
    'interval_days',
    'next_review_at',
    'lapses',
    'buried_until',
    'last_grade',
    'last_reviewed_at',
])]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory;

    /**
     * The easiness a card starts on, and the floor it can never drop below.
     */
    public const DEFAULT_EASINESS = 2.5;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CardType::class,
            'queue' => CardQueue::class,
            'learning_step' => 'integer',
            'repetitions' => 'integer',
            // float, not decimal:2, because the scheduler multiplies by it and
            // a string cast would coerce on every calculation.
            'easiness' => 'float',
            'interval_days' => 'integer',
            'lapses' => 'integer',
            'last_grade' => 'integer',
            'next_review_at' => 'datetime',
            'buried_until' => 'datetime',
            'last_reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Note, $this> */
    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
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

    /** @return HasMany<CardReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(CardReview::class);
    }

    /**
     * The other cards made from the same note.
     *
     * @return HasMany<Card, $this>
     */
    public function siblings(): HasMany
    {
        return $this->hasMany(self::class, 'note_id', 'note_id')
            ->whereKeyNot($this->getKey());
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Everything a session could draw from: studiable, due, and not buried
     * behind a sibling answered earlier today.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDue(Builder $query, ?Carbon $at = null): void
    {
        $at ??= now();

        $query->whereIn('queue', array_map(
            fn (CardQueue $queue) => $queue->value,
            array_filter(CardQueue::cases(), fn (CardQueue $queue) => $queue->isStudiable()),
        ))
            ->where('next_review_at', '<=', $at)
            ->where(fn (Builder $sub) => $sub
                ->whereNull('buried_until')
                ->orWhere('buried_until', '<=', $at));
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeInQueue(Builder $query, CardQueue $queue): void
    {
        $query->where('queue', $queue->value);
    }

    /**
     * @return Attribute<CardState, never>
     */
    protected function state(): Attribute
    {
        return Attribute::get(fn (): CardState => CardState::fromCard(
            $this->queue,
            $this->interval_days,
        ));
    }

    public function isBuried(?Carbon $at = null): bool
    {
        return $this->buried_until !== null
            && $this->buried_until->greaterThan($at ?? now());
    }

    public function isSuspended(): bool
    {
        return $this->queue === CardQueue::Suspended;
    }

    /**
     * Whether the note behind this card can actually supply the question. A
     * card whose audio has not been generated yet cannot be a listening card.
     */
    public function isAnswerable(): bool
    {
        return $this->note->supports($this->type);
    }
}
