<?php

namespace App\Actions\Holds;

use App\Enums\HoldStatus;
use App\Enums\SeatInventoryStatus;
use App\Enums\SeatType;
use App\Enums\ShowtimeStatus;
use App\Exceptions\SeatsUnavailable;
use App\Models\Hold;
use App\Models\Showtime;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * H3-02 / HLD-01 — acquire an exclusive hold on a set of seats.
 *
 * This is the transaction the whole project exists to get right. Everything it
 * does follows DOMAIN_STATE_MODELS.md §12.2 in order, and the order is the
 * point: showtime first, then inventory rows sorted by seat ID, so two requests
 * competing for overlapping seats always queue behind the same first lock
 * instead of deadlocking against each other.
 *
 * Three rules it never breaks:
 *
 * 1. **PostgreSQL decides.** On sale, availability, the creation instant and the
 *    expiry instant all come from the server inside this transaction. Nothing is
 *    computed in PHP and trusted. ADR-004, RULE-021.
 * 2. **All or none.** Any unavailable seat refuses the whole request, and the
 *    refusal is an exception thrown inside the transaction, so the rollback is
 *    the enforcement — not a compensating write. RULE-003, AC-002.
 * 3. **The map is not authority.** A seat shown selectable may be gone by now.
 *    That is expected and produces a deterministic conflict, never a partial
 *    success. FR-009.
 *
 * Not here, deliberately:
 *
 * - **Idempotency.** `H3-03` adds the scoped key and lost-response replay.
 * - **Inline reclamation of expired holds.** A Phase 4 deliverable. Until then
 *   an expired `HELD` row is simply not acquirable, which can refuse a seat the
 *   map showed as free. A refusal is the safe direction; a false success is not.
 *
 * Trace: BR-02-05; FR-007-012; RULE-001-005/012-014; ADR-004; AC-001-003.
 */
class AcquireSeatHold
{
    /**
     * The declaration is booking-scoped and versioned, so a later policy change
     * cannot retroactively describe what this customer agreed to. PP-10.
     */
    public const ACCESSIBILITY_POLICY_VERSION = '2026-08-10';

    /**
     * Bounded retries with jitter, per §12.5. Deterministic ordering makes a
     * deadlock unlikely rather than impossible, and the only safe unit of retry
     * is the whole transaction.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * @param  array<int, string>  $seatPublicIds
     *
     * @throws SeatsUnavailable when the request acquires nothing
     */
    public function handle(User $user, Showtime $showtime, array $seatPublicIds, bool $acknowledged = false): Hold
    {
        // Step 1 of §12.2. Duplicates are rejected by AcquireSeatHoldRequest at
        // the boundary; collapsing them here as well means a direct caller
        // cannot inflate seat_count by asking for the same seat twice.
        $requested = array_values(array_unique($seatPublicIds));

        $correlationId = (string) Str::uuid();

        return DB::transaction(
            fn (): Hold => $this->acquire($user, $showtime, $requested, $acknowledged, $correlationId),
            self::MAX_ATTEMPTS
        );
    }

    /**
     * @param  array<int, string>  $requested
     */
    private function acquire(
        User $user,
        Showtime $showtime,
        array $requested,
        bool $acknowledged,
        string $correlationId,
    ): Hold {
        $locked = $this->lockShowtime($showtime);

        // Immutable facts about the seats: identity, type and pairing. Read
        // before the inventory lock because none of it can change while the
        // showtime is locked — the layout freeze (ADR-019) guarantees it.
        $seats = $this->resolveSeats($locked, $requested);

        $seatIds = array_map(fn (array $seat): int => (int) $seat['id'], $seats);
        sort($seatIds);

        $inventory = $this->lockInventory($locked, $seatIds);

        $this->guardAvailability($seats, $inventory);
        $this->guardCompanionPairing($seats);
        $this->guardAccessibleDeclaration($seats, $acknowledged);

        $holdsAccessibleSeat = $this->holdsAccessibleSeat($seats);

        $hold = $this->createHold($user, $locked, $seats, $inventory, $holdsAccessibleSeat);

        $this->createItems($hold, $locked, $seats, $inventory);
        $this->markInventoryHeld($locked, $hold, $seatIds);

        $this->recordOutboxEvent($hold, $locked, $seats, $correlationId);
        $this->recordAuditEvent($hold, $user, $correlationId);

        return $hold;
    }

    /**
     * §12.2 step 3 and the global lock order in LOGICAL_ERD.md §6: the showtime
     * is always the first lock any allocation path takes. Publication, holds,
     * confirmation and cancellation therefore serialize against each other on
     * one row rather than racing across many.
     */
    private function lockShowtime(Showtime $showtime): Showtime
    {
        $locked = Showtime::query()
            ->whereKey($showtime->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        // Derived from the server clock inside this transaction, never stored.
        // There is no ENDED state (RC-03), so a finished showtime is unbookable
        // even if no maintenance job has run.
        $onSale = (bool) DB::scalar(
            'SELECT ? = ? AND transaction_timestamp() < ?',
            [$locked->status->value, ShowtimeStatus::Published->value, $locked->starts_at]
        );

        if (! $onSale) {
            throw SeatsUnavailable::showtimeNotOnSale();
        }

        return $locked;
    }

    /**
     * @param  array<int, string>  $requested
     * @return array<int, array<string, mixed>> ordered by internal seat ID
     */
    private function resolveSeats(Showtime $showtime, array $requested): array
    {
        $seats = DB::table('showtime_seats as ss')
            ->join('seats as s', 's.id', '=', 'ss.seat_id')
            ->leftJoin('seat_companion_links as l', function ($join): void {
                $join->on('l.accessible_seat_id', '=', 's.id')
                    ->orOn('l.companion_seat_id', '=', 's.id');
            })
            ->where('ss.showtime_id', $showtime->getKey())
            ->whereIn('s.public_id', $requested)
            ->orderBy('s.id')
            ->select([
                's.id',
                's.public_id',
                's.display_label',
                's.row_label',
                's.seat_number',
                's.seat_type',
                DB::raw(
                    'CASE WHEN l.accessible_seat_id = s.id
                          THEN l.companion_seat_id
                          ELSE l.accessible_seat_id
                     END as linked_seat_id'
                ),
            ])
            ->get()
            // DB rows arrive as stdClass. Convert once and read every column
            // through an explicit cast, the same way GetSeatMap does, so the
            // shape is verifiable rather than assumed.
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $found = array_map(fn (array $seat): string => (string) $seat['public_id'], $seats);
        $missing = array_values(array_diff($requested, $found));

        if ($missing !== []) {
            throw SeatsUnavailable::seatsNotInShowtime($missing);
        }

        return $seats;
    }

    /**
     * §12.2 step 4 — lock the requested inventory rows in seat-ID order.
     *
     * The ordering is not cosmetic. Two requests for {A1, A2} and {A2, A1} that
     * locked in request order would each hold one row and wait for the other's;
     * sorting first means the second request simply waits for the first.
     *
     * PostgreSQL applies FOR UPDATE above the sort, so rows are locked in the
     * order this query returns them.
     *
     * @param  array<int, int>  $seatIds  ascending
     * @return array<int, array<string, mixed>> keyed by seat ID
     */
    private function lockInventory(Showtime $showtime, array $seatIds): array
    {
        $rows = DB::table('showtime_seats')
            ->where('showtime_id', $showtime->getKey())
            ->whereIn('seat_id', $seatIds)
            ->orderBy('seat_id')
            ->lockForUpdate()
            ->get();

        $indexed = [];

        foreach ($rows as $row) {
            $columns = (array) $row;
            $indexed[(int) $columns['seat_id']] = $columns;
        }

        return $indexed;
    }

    /**
     * §12.2 steps 5 and 6, and INV-02's guard: every requested row must be
     * AVAILABLE after the lock. One failure refuses all of them.
     *
     * An expired HELD row is not treated as available here — reclaiming it is a
     * Phase 4 deliverable. The consequence is a refusal for a seat the map
     * showed as selectable, which is the direction that cannot hurt anyone.
     *
     * @param  array<int, array<string, mixed>>  $seats
     * @param  array<int, array<string, mixed>>  $inventory
     */
    private function guardAvailability(array $seats, array $inventory): void
    {
        $unavailable = [];

        foreach ($seats as $seat) {
            $row = $inventory[(int) $seat['id']] ?? null;

            if ($row === null || $row['status'] !== SeatInventoryStatus::Available->value) {
                $unavailable[] = (string) $seat['public_id'];
            }
        }

        if ($unavailable !== []) {
            throw SeatsUnavailable::seatsTaken($unavailable);
        }
    }

    /**
     * §5.3 invariant 1: a companion seat may only move with its linked
     * accessible seat. Enforced here as well as in validation because the action
     * is reachable without the HTTP request, and because §5.3 states it as an
     * inventory rule rather than a form rule.
     *
     * @param  array<int, array<string, mixed>>  $seats
     */
    private function guardCompanionPairing(array $seats): void
    {
        $requestedIds = array_map(fn (array $seat): int => (int) $seat['id'], $seats);
        $orphans = [];

        foreach ($seats as $seat) {
            if ($seat['seat_type'] !== SeatType::Companion->value) {
                continue;
            }

            $linked = $seat['linked_seat_id'];

            if ($linked === null || ! in_array((int) $linked, $requestedIds, true)) {
                $orphans[] = (string) $seat['public_id'];
            }
        }

        if ($orphans !== []) {
            throw SeatsUnavailable::companionWithoutAccessible($orphans);
        }
    }

    /**
     * PP-10. Only the acknowledgement matters — never why it was given.
     *
     * @param  array<int, array<string, mixed>>  $seats
     */
    private function guardAccessibleDeclaration(array $seats, bool $acknowledged): void
    {
        if ($acknowledged) {
            return;
        }

        $undeclared = [];

        foreach ($seats as $seat) {
            if ($seat['seat_type'] === SeatType::Accessible->value) {
                $undeclared[] = (string) $seat['public_id'];
            }
        }

        if ($undeclared !== []) {
            throw SeatsUnavailable::accessibleNotDeclared($undeclared);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $seats
     */
    private function holdsAccessibleSeat(array $seats): bool
    {
        foreach ($seats as $seat) {
            if ($seat['seat_type'] === SeatType::Accessible->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * §6.2 — created_at and expires_at both come from PostgreSQL, and the
     * interval is fixed at five minutes by RULE-012 and by a CHECK constraint.
     *
     * The acknowledgement is recorded only when the hold actually contains an
     * accessible seat: a ticked box on a standard booking is not a declaration
     * of anything, and the holds CHECK requires all three columns or none.
     *
     * @param  array<int, array<string, mixed>>  $seats
     * @param  array<int, array<string, mixed>>  $inventory
     */
    private function createHold(
        User $user,
        Showtime $showtime,
        array $seats,
        array $inventory,
        bool $holdsAccessibleSeat,
    ): Hold {
        $total = 0;
        $currency = $showtime->currency;

        foreach ($seats as $seat) {
            $row = $inventory[(int) $seat['id']];
            $total += (int) $row['price_minor'];
            $currency = (string) $row['currency'];
        }

        $id = DB::table('holds')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'showtime_id' => $showtime->getKey(),
            'status' => HoldStatus::Active->value,
            'seat_count' => count($seats),
            'total_minor' => $total,
            'currency' => $currency,
            'created_at' => DB::raw('transaction_timestamp()'),
            'expires_at' => DB::raw("transaction_timestamp() + interval '5 minutes'"),
            'accessible_acknowledged' => $holdsAccessibleSeat,
            'accessible_acknowledged_at' => $holdsAccessibleSeat ? DB::raw('transaction_timestamp()') : null,
            'accessibility_policy_version' => $holdsAccessibleSeat ? self::ACCESSIBILITY_POLICY_VERSION : null,
        ]);

        return Hold::query()->whereKey($id)->firstOrFail();
    }

    /**
     * The immutable checkout snapshot. Labels and type are copied from `seats`
     * and the price from `showtime_seats`, which took its own copy at
     * publication — so a price edit cannot reach a hold already created.
     *
     * @param  array<int, array<string, mixed>>  $seats
     * @param  array<int, array<string, mixed>>  $inventory
     */
    private function createItems(Hold $hold, Showtime $showtime, array $seats, array $inventory): void
    {
        $items = [];

        foreach ($seats as $seat) {
            $row = $inventory[(int) $seat['id']];

            $items[] = [
                'hold_id' => $hold->getKey(),
                'showtime_id' => $showtime->getKey(),
                'seat_id' => (int) $seat['id'],
                'seat_label_snapshot' => (string) $seat['display_label'],
                'row_label_snapshot' => (string) $seat['row_label'],
                'seat_number_snapshot' => (string) $seat['seat_number'],
                'seat_type_snapshot' => (string) $seat['seat_type'],
                'price_minor' => (int) $row['price_minor'],
                'currency' => (string) $row['currency'],
            ];
        }

        DB::table('hold_items')->insert($items);
    }

    /**
     * INV-02 — AVAILABLE to HELD for every requested row, with the version
     * bumped so the seat map's polling number moves.
     *
     * `hold_expires_at` is read back from the hold rather than recomputed, so
     * the countdown the customer sees and the instant the seat frees are the
     * same value to the microsecond.
     *
     * @param  array<int, int>  $seatIds
     */
    private function markInventoryHeld(Showtime $showtime, Hold $hold, array $seatIds): void
    {
        $expiresAt = DB::scalar('SELECT expires_at FROM holds WHERE id = ?', [$hold->getKey()]);

        $updated = DB::table('showtime_seats')
            ->where('showtime_id', $showtime->getKey())
            ->whereIn('seat_id', $seatIds)
            ->update([
                'status' => SeatInventoryStatus::Held->value,
                'hold_id' => $hold->getKey(),
                'hold_expires_at' => $expiresAt,
                'version' => DB::raw('version + 1'),
                'updated_at' => DB::raw('transaction_timestamp()'),
            ]);

        if ($updated !== count($seatIds)) {
            // Unreachable while the rows above are locked. If it ever fires, the
            // lock was not held and the whole model is wrong — so fail the
            // transaction rather than commit a partial hold.
            throw SeatsUnavailable::seatsTaken([]);
        }
    }

    /**
     * The durable event, written in this transaction so it cannot describe a
     * hold that was rolled back. The relay that publishes it is Phase 5.
     *
     * @param  array<int, array<string, mixed>>  $seats
     */
    private function recordOutboxEvent(Hold $hold, Showtime $showtime, array $seats, string $correlationId): void
    {
        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid(),
            'aggregate_type' => 'HOLD',
            'aggregate_id' => $hold->getKey(),
            'event_type' => 'HOLD_CREATED',
            // Public identifiers and money only. No names, no email, no
            // internal keys. LOGICAL_ERD.md §2.9.
            'payload' => json_encode([
                'version' => 1,
                'hold_id' => $hold->public_id,
                'showtime_id' => $showtime->public_id,
                'seat_ids' => array_map(fn (array $seat): string => (string) $seat['public_id'], $seats),
                'expires_at' => $hold->expires_at->toIso8601String(),
                'total_minor' => $hold->total_minor,
                'currency' => $hold->currency,
            ]),
            'correlation_id' => $correlationId,
            'occurred_at' => DB::raw('transaction_timestamp()'),
            'available_at' => DB::raw('transaction_timestamp()'),
        ]);
    }

    private function recordAuditEvent(Hold $hold, User $user, string $correlationId): void
    {
        DB::table('audit_events')->insert([
            'event_id' => (string) Str::uuid(),
            'occurred_at' => DB::raw('transaction_timestamp()'),
            'actor_type' => 'USER',
            'actor_user_id' => $user->getKey(),
            'action' => 'HOLD_ACQUIRED',
            'subject_type' => 'HOLD',
            'subject_id' => $hold->getKey(),
            'correlation_id' => $correlationId,
            'result' => 'SUCCESS',
            'metadata' => json_encode([
                'seat_count' => $hold->seat_count,
                'total_minor' => $hold->total_minor,
                'currency' => $hold->currency,
            ]),
        ]);
    }
}
