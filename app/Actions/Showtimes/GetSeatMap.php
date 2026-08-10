<?php

namespace App\Actions\Showtimes;

use App\Enums\SeatInventoryStatus;
use App\Models\Showtime;
use Illuminate\Support\Facades\DB;

/**
 * C2-04 — the authoritative seat map for one published showtime.
 *
 * Minimal by intent: stable seat identity, one selectable flag per seat, and an
 * inventory version for polling. Geometry, colour and the accessible seat grid
 * belong to `L4-03`.
 *
 * Two rules this read path shares with the write path, so the two cannot
 * disagree:
 *
 * 1. "On sale" is derived from the PostgreSQL clock, never stored (`RC-03`).
 * 2. An expired `HELD` row is reclaimable (`LOGICAL_ERD.md` §5.1). Reporting it
 *    as unavailable would tell a customer no when a hold request would say yes.
 *
 * The map is a snapshot and is never authority: a seat shown selectable may be
 * taken before the customer acts. That is what `H3-02` revalidation is for —
 * a stale map may cause rejection, but never false success.
 *
 * Trace: FR-003/004/005; RULE-001/004; ADR-005/007/012/014; AC-001/002.
 */
class GetSeatMap
{
    /**
     * @return array<string, mixed>
     */
    public function handle(Showtime $showtime): array
    {
        $showtime->loadMissing(['movie', 'hall.venue']);

        $onSale = (bool) DB::scalar(
            'SELECT ? = ? AND transaction_timestamp() < ?',
            [$showtime->status->value, 'PUBLISHED', $showtime->starts_at]
        );

        // DB::select() hands back untyped stdClass rows. Convert once, then read
        // every column through an explicit cast, so the response shape is
        // verifiable rather than assumed.
        $seats = array_map(fn (object $row): array => (array) $row, DB::select(<<<'SQL'
            SELECT
                s.public_id,
                s.display_label,
                s.row_label,
                s.seat_number,
                s.seat_type,
                s.sort_order,
                ss.version,
                (
                    ss.status = ?
                    OR (ss.status = ? AND ss.hold_expires_at <= transaction_timestamp())
                ) AS is_free,
                partner.public_id AS linked_public_id
            FROM showtime_seats ss
            JOIN seats s ON s.id = ss.seat_id
            LEFT JOIN seat_companion_links l
                   ON l.accessible_seat_id = s.id OR l.companion_seat_id = s.id
            LEFT JOIN seats partner
                   ON partner.id = CASE
                          WHEN l.accessible_seat_id = s.id THEN l.companion_seat_id
                          ELSE l.accessible_seat_id
                      END
            WHERE ss.showtime_id = ?
            ORDER BY s.sort_order
        SQL, [
            SeatInventoryStatus::Available->value,
            SeatInventoryStatus::Held->value,
            $showtime->getKey(),
        ]));

        return [
            'showtime' => [
                'id' => $showtime->public_id,
                'starts_at' => $showtime->starts_at->toIso8601String(),
                'ends_at' => $showtime->ends_at->toIso8601String(),
                'on_sale' => $onSale,
                'price_minor' => $showtime->price_minor,
                'currency' => $showtime->currency,
                'movie' => [
                    'title' => $showtime->movie->title,
                    'runtime_minutes' => $showtime->movie->runtime_minutes,
                    'classification' => $showtime->movie->classification,
                ],
                'hall' => ['name' => $showtime->hall->name],
                'venue' => [
                    'name' => $showtime->hall->venue->name,
                    'timezone' => $showtime->hall->venue->timezone,
                ],
            ],
            // Monotonic across any inventory change, because every transition
            // increments the row's version. A client discards a poll carrying a
            // lower number. DOMAIN_STATE_MODELS.md §13.
            'inventory_version' => array_sum(array_map(
                fn (array $row): int => (int) $row['version'],
                $seats
            )),
            'seats' => array_map(fn (array $row): array => [
                'id' => (string) $row['public_id'],
                'label' => (string) $row['display_label'],
                'row' => (string) $row['row_label'],
                'number' => (string) $row['seat_number'],
                'type' => (string) $row['seat_type'],
                // Deliberately a boolean, not the stored status: "held" versus
                // "confirmed" would tell a stranger whether someone is midway
                // through checkout. FR-004 asks only for selectable versus
                // unavailable.
                'selectable' => $onSale && (bool) $row['is_free'],
                'linked_seat_id' => $row['linked_public_id'] === null
                    ? null
                    : (string) $row['linked_public_id'],
            ], $seats),
        ];
    }
}
