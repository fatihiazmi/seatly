<?php

namespace App\Enums;

/**
 * Authoritative state of one seat within one showtime.
 * DOMAIN_STATE_MODELS.md §5.
 *
 * Linkage columns name the current owner only (RC-02): confirmation clears
 * hold_id, blocking clears both. History lives in hold_items, booking_items,
 * bookings.hold_id and audit_events — never in a stale pointer here.
 */
enum SeatInventoryStatus: string
{
    case Available = 'AVAILABLE';
    case Held = 'HELD';
    case Confirmed = 'CONFIRMED';
    case Blocked = 'BLOCKED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
