<?php

namespace App\Enums;

/**
 * Lifecycle of a showtime. DOMAIN_STATE_MODELS.md §4.
 *
 * There is deliberately no ENDED state (RC-03). "Still to come" is derived from
 * the server clock, so a finished showtime cannot read as bookable because a
 * lifecycle job failed to run.
 */
enum ShowtimeStatus: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
    case Cancelled = 'CANCELLED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
