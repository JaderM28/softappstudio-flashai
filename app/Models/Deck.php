<?php

namespace App\Models;

use App\Enums\CardType;
use Database\Factories\DeckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'description',
    'target_language',
    'native_language',
    'prompt_instructions',
    'card_types',
    'show_translation',
    'new_per_day',
    'reviews_per_day',
])]
class Deck extends Model
{
    /** @use HasFactory<DeckFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'show_translation' => true,
        'is_default' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'card_types' => 'array',
            'show_translation' => 'boolean',
            'is_default' => 'boolean',
            'new_per_day' => 'integer',
            'reviews_per_day' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The column has no database default so it stays tied to CardType
        // rather than being frozen into the schema.
        static::creating(function (Deck $deck): void {
            if ($deck->card_types === null) {
                $deck->card_types = array_map(
                    fn (CardType $type) => $type->value,
                    CardType::defaults(),
                );
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Note, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
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
     * The card types this deck generates, as enums.
     *
     * @return Attribute<array<int, CardType>, never>
     */
    protected function cardTypeEnums(): Attribute
    {
        return Attribute::get(fn (): array => array_values(array_filter(
            array_map(
                fn (string $value) => CardType::tryFrom($value),
                $this->card_types ?? [],
            ),
        )));
    }

    public function generates(CardType $type): bool
    {
        return in_array($type->value, $this->card_types ?? [], true);
    }

    /**
     * Deck settings win, and fall back to the global defaults when unset.
     */
    public function newPerDay(): int
    {
        return $this->new_per_day ?? (int) config('flashai.session.new_per_day');
    }

    public function reviewsPerDay(): int
    {
        return $this->reviews_per_day ?? (int) config('flashai.session.reviews_per_day');
    }
}
