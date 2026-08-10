<?php

namespace App\Enums;

/**
 * Lifecycle of one temporary seat hold. DOMAIN_STATE_MODELS.md §6.1.
 *
 * Every state except ACTIVE is terminal: a hold is never extended, never
 * reopened, and never has seats added, removed or swapped (§6.4).
 */
enum HoldStatus: string
{
    case Active = 'ACTIVE';
    case Confirmed = 'CONFIRMED';
    case Expired = 'EXPIRED';
    case Released = 'RELEASED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTerminal(): bool
    {
        return $this !== self::Active;
    }
}
