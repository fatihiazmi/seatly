<?php

namespace App\Enums;

/**
 * Classification of a fixed seat in a hall layout.
 *
 * The database holds a matching CHECK constraint as the backstop; this enum is
 * the application guard. ADR-012, MVP_POLICY_BASELINE.md §1.
 */
enum SeatType: string
{
    case Standard = 'STANDARD';
    case Accessible = 'ACCESSIBLE';
    case Companion = 'COMPANION';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * A companion seat is only ever bookable alongside its linked accessible
     * seat. ADR-012.
     */
    public function requiresLinkedAccessibleSeat(): bool
    {
        return $this === self::Companion;
    }
}
