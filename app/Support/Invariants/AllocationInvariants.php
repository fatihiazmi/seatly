<?php

namespace App\Support\Invariants;

use Illuminate\Support\Facades\DB;

/**
 * H3-06 — the post-run invariant SQL.
 *
 * A concurrency harness that reads only its own responses is grading its own
 * homework: it can report one winner while the database holds two. These queries
 * are the independent check, run against committed state after the fact and
 * answerable by anyone with a psql prompt.
 *
 * Several of them can never fire, because a constraint makes the bad state
 * unreachable — a dangling hold pointer is refused by the composite foreign key,
 * an impossible linkage by the state CHECK. They are kept anyway: constraints
 * are added by migrations, migrations can be edited, and a restored or
 * hand-repaired database has had neither applied to it.
 *
 * One check is deferred rather than missing. Duplicate *confirmed* ownership
 * needs `bookings`, which arrives in Phase 5; it reports itself as deferred so
 * the gate report can never imply a coverage it does not have.
 *
 * @phpstan-type InvariantResult array{name: string, description: string, violations: int, deferred: bool, sample: array<int, array<string, mixed>>}
 */
class AllocationInvariants
{
    /**
     * Run every check and report what each found.
     *
     * @return array<int, InvariantResult>
     */
    public function run(int $sampleSize = 5): array
    {
        $results = [];

        foreach ($this->checks() as $name => $check) {
            $results[] = $this->evaluate($name, $check['description'], $check['sql'], $sampleSize);
        }

        $results[] = [
            'name' => 'duplicate_confirmed_seat_ownership',
            'description' => 'Two confirmed bookings holding one seat (deferred: bookings arrive in Phase 5).',
            'violations' => 0,
            'deferred' => true,
            'sample' => [],
        ];

        return $results;
    }

    /**
     * True when nothing fired. Deferred checks cannot fail, so they cannot
     * make this true by accident either — they contribute zero of both.
     *
     * @param  array<int, InvariantResult>  $results
     */
    public function clean(array $results): bool
    {
        foreach ($results as $result) {
            if ($result['violations'] > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return InvariantResult
     */
    private function evaluate(string $name, string $description, string $sql, int $sampleSize): array
    {
        $rows = array_map(
            fn (object $row): array => (array) $row,
            DB::select($sql)
        );

        return [
            'name' => $name,
            'description' => $description,
            'violations' => count($rows),
            'deferred' => false,
            'sample' => array_slice($rows, 0, $sampleSize),
        ];
    }

    /**
     * @return array<string, array{description: string, sql: string}>
     */
    private function checks(): array
    {
        return [
            // The P0 invariant. If this ever returns a row, the gate has failed
            // no matter what the harness reported.
            'duplicate_active_seat_ownership' => [
                'description' => 'Two live holds claiming the same showtime seat.',
                'sql' => <<<'SQL'
                    SELECT hi.showtime_id, hi.seat_id, count(*) AS live_holds
                      FROM hold_items hi
                      JOIN holds h ON h.id = hi.hold_id
                     WHERE h.status = 'ACTIVE'
                       AND h.expires_at > transaction_timestamp()
                     GROUP BY hi.showtime_id, hi.seat_id
                    HAVING count(*) > 1
                SQL,
            ],

            // The other half of all-or-none: a live hold that snapshotted a seat
            // it does not actually own.
            'partial_hold_ownership' => [
                'description' => 'A live hold with a snapshot line it does not own in inventory.',
                'sql' => <<<'SQL'
                    SELECT h.id AS hold_id, hi.seat_id
                      FROM holds h
                      JOIN hold_items hi ON hi.hold_id = h.id
                      LEFT JOIN showtime_seats ss
                             ON ss.showtime_id = hi.showtime_id
                            AND ss.seat_id = hi.seat_id
                            AND ss.hold_id = h.id
                     WHERE h.status = 'ACTIVE'
                       AND h.expires_at > transaction_timestamp()
                       AND ss.seat_id IS NULL
                SQL,
            ],

            'hold_coverage_mismatch' => [
                'description' => 'A live hold owning a different number of seats than it snapshotted.',
                'sql' => <<<'SQL'
                    SELECT h.id AS hold_id,
                           (SELECT count(*) FROM hold_items hi WHERE hi.hold_id = h.id) AS snapshot_lines,
                           (SELECT count(*) FROM showtime_seats ss WHERE ss.hold_id = h.id) AS owned_seats
                      FROM holds h
                     WHERE h.status = 'ACTIVE'
                       AND h.expires_at > transaction_timestamp()
                       AND (SELECT count(*) FROM hold_items hi WHERE hi.hold_id = h.id)
                        <> (SELECT count(*) FROM showtime_seats ss WHERE ss.hold_id = h.id)
                SQL,
            ],

            'inventory_hold_pointer_dangling' => [
                'description' => 'Inventory pointing at a hold that does not exist.',
                'sql' => <<<'SQL'
                    SELECT ss.showtime_id, ss.seat_id, ss.hold_id
                      FROM showtime_seats ss
                      LEFT JOIN holds h ON h.id = ss.hold_id
                     WHERE ss.hold_id IS NOT NULL
                       AND h.id IS NULL
                SQL,
            ],

            // The countdown the customer sees and the instant the seat frees are
            // the same value, or the two disagree at the boundary.
            'hold_expiry_mismatch' => [
                'description' => 'Inventory expiry that disagrees with its hold.',
                'sql' => <<<'SQL'
                    SELECT ss.showtime_id, ss.seat_id, ss.hold_expires_at, h.expires_at
                      FROM showtime_seats ss
                      JOIN holds h ON h.id = ss.hold_id
                     WHERE ss.hold_expires_at IS DISTINCT FROM h.expires_at
                SQL,
            ],

            'hold_totals_disagree' => [
                'description' => 'A hold whose seat count or total does not match its items.',
                'sql' => <<<'SQL'
                    SELECT h.id AS hold_id, h.seat_count, h.total_minor, i.line_count, i.line_total
                      FROM holds h
                      JOIN (
                            SELECT hold_id, count(*) AS line_count, sum(price_minor) AS line_total
                              FROM hold_items
                             GROUP BY hold_id
                           ) i ON i.hold_id = h.id
                     WHERE i.line_count <> h.seat_count
                        OR i.line_total <> h.total_minor
                SQL,
            ],

            'orphan_companion_hold' => [
                'description' => 'A held companion seat whose linked accessible seat is not in the same hold.',
                'sql' => <<<'SQL'
                    SELECT hi.hold_id, hi.seat_id
                      FROM hold_items hi
                      JOIN seat_companion_links l ON l.companion_seat_id = hi.seat_id
                     WHERE NOT EXISTS (
                           SELECT 1 FROM hold_items sibling
                            WHERE sibling.hold_id = hi.hold_id
                              AND sibling.seat_id = l.accessible_seat_id
                       )
                SQL,
            ],

            'undeclared_accessible_hold' => [
                'description' => 'A held accessible seat without the booking-scoped declaration.',
                'sql' => <<<'SQL'
                    SELECT hi.hold_id, hi.seat_id
                      FROM hold_items hi
                      JOIN holds h ON h.id = hi.hold_id
                     WHERE hi.seat_type_snapshot = 'ACCESSIBLE'
                       AND NOT h.accessible_acknowledged
                SQL,
            ],

            'inventory_linkage_invalid' => [
                'description' => 'An inventory row whose owner columns do not match its status.',
                'sql' => <<<'SQL'
                    SELECT showtime_id, seat_id, status
                      FROM showtime_seats
                     WHERE NOT (
                            (status = 'AVAILABLE'
                                AND hold_id IS NULL AND booking_id IS NULL
                                AND hold_expires_at IS NULL AND blocked_reason_code IS NULL)
                         OR (status = 'HELD'
                                AND hold_id IS NOT NULL AND booking_id IS NULL
                                AND hold_expires_at IS NOT NULL AND blocked_reason_code IS NULL)
                         OR (status = 'CONFIRMED'
                                AND hold_id IS NULL AND booking_id IS NOT NULL
                                AND hold_expires_at IS NULL AND blocked_reason_code IS NULL)
                         OR (status = 'BLOCKED'
                                AND hold_id IS NULL AND booking_id IS NULL
                                AND hold_expires_at IS NULL AND blocked_reason_code IS NOT NULL)
                       )
                SQL,
            ],

            'hold_expiry_not_five_minutes' => [
                'description' => 'A hold whose lifetime is not the approved five minutes.',
                'sql' => <<<'SQL'
                    SELECT id AS hold_id, created_at, expires_at
                      FROM holds
                     WHERE expires_at <> created_at + interval '5 minutes'
                SQL,
            ],
        ];
    }
}
