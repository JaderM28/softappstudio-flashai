<?php

namespace App\Enums;

/**
 * Where a card is in the async AI generation pipeline.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isSettled(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En cola',
            self::Processing => 'Generando…',
            self::Completed => 'Lista',
            self::Failed => 'Falló',
        };
    }
}
