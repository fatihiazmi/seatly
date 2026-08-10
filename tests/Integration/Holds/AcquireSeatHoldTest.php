<?php

namespace Tests\Integration\Holds;

use App\Actions\Holds\AcquireSeatHold;
use App\Actions\Showtimes\PublishShowtime;
use App\Enums\HoldStatus;
use App\Enums\SeatInventoryStatus;
use App\Enums\SeatType;
use App\Exceptions\SeatsUnavailable;
use App\Models\Hall;
use App\Models\Seat;
use App\Models\SeatCompanionLink;
use App\Models\Showtime;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Integration\IntegrationTestCase;

/**
 * H3-02 — the atomic acquisition transaction, against real PostgreSQL.
 *
 * Single-threaded correctness only: the shape of one hold, and the refusals a
 * hold must make when it can see the conflict on its own. Contention between
 * concurrent requests is `H3-04`/`H3-05`, and it is the reason every assertion
 * here is about state PostgreSQL committed rather than a value PHP computed.
 *
 * Trace: BR-02-05; FR-007-012; RULE-001-005/012-014; ADR-004;
 * AC-001-003; DOMAIN_STATE_MODELS.md §6.2/§6.3 HLD-01, §5.2 INV-02, §12.2.
 */
class AcquireSeatHoldTest extends IntegrationTestCase
{
    use RefreshDatabase;

    private const PRICE = 1850;

    /**
     * @return array{Showtime, Collection<int, Seat>}
     */
    private function publishedShowtime(int $seats = 6): array
    {
        $hall = Hall::factory()->create();
        $created = Seat::factory()->count($seats)->for($hall)->sequence(
            fn ($sequence) => [
                'row_label' => 'A',
                'seat_number' => (string) ($sequence->index + 1),
                'display_label' => 'A'.($sequence->index + 1),
                'sort_order' => $sequence->index,
            ]
        )->create();

        $showtime = Showtime::factory()->for($hall)->create(['price_minor' => self::PRICE]);
        app(PublishShowtime::class)->handle($showtime);

        return [$showtime->fresh(), $created];
    }

    /**
     * @param  Collection<int, Seat>|array<int, Seat>  $seats
     * @return array<int, string>
     */
    private function ids(Collection|array $seats): array
    {
        return collect($seats)->pluck('public_id')->all();
    }

    /**
     * @param  array<int, string>  $seatIds
     */
    private function acquire(User $user, Showtime $showtime, array $seatIds, bool $acknowledged = false): mixed
    {
        return app(AcquireSeatHold::class)->handle($user, $showtime, $seatIds, $acknowledged);
    }

    // --- the shape of one hold (HLD-01, AC-003) ---------------------------

    public function test_it_creates_an_active_hold_for_the_requested_seats(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();
        $user = User::factory()->create();

        $hold = $this->acquire($user, $showtime, $this->ids($seats->take(2)));

        $this->assertSame(HoldStatus::Active, $hold->status);
        $this->assertSame($user->id, $hold->user_id);
        $this->assertSame($showtime->id, $hold->showtime_id);
        $this->assertSame(2, $hold->seat_count);
        $this->assertSame(2 * self::PRICE, $hold->total_minor);
        $this->assertSame('MYR', $hold->currency);
        $this->assertNull($hold->closed_at);
    }

    public function test_the_expiry_is_five_minutes_from_the_postgresql_clock(): void
    {
        // RULE-012/PP-03/FR-014, and holds carries the same rule as a CHECK.
        // The instant must come from the database, not from PHP: the whole
        // expiry model depends on one clock. ADR-004.
        [$showtime, $seats] = $this->publishedShowtime();

        $hold = $this->acquire(User::factory()->create(), $showtime, $this->ids($seats->take(1)));

        $row = DB::selectOne(
            'SELECT expires_at - created_at AS span,
                    abs(extract(epoch FROM (created_at - transaction_timestamp()))) AS drift
             FROM holds WHERE id = ?',
            [$hold->id]
        );

        $this->assertNotNull($row);
        $this->assertSame('00:05:00', (string) $row->span);
        $this->assertLessThan(5, (float) $row->drift);
    }

    public function test_it_snapshots_every_seat_it_holds(): void
    {
        // hold_items is an immutable checkout snapshot: a later hall rename or
        // price change must not rewrite what the customer was quoted.
        [$showtime, $seats] = $this->publishedShowtime();
        $seat = $seats->first();

        $hold = $this->acquire(User::factory()->create(), $showtime, [$seat->public_id]);

        $item = DB::selectOne('SELECT * FROM hold_items WHERE hold_id = ?', [$hold->id]);

        $this->assertNotNull($item);
        $this->assertSame($seat->id, (int) $item->seat_id);
        $this->assertSame($showtime->id, (int) $item->showtime_id);
        $this->assertSame($seat->display_label, $item->seat_label_snapshot);
        $this->assertSame('A', $item->row_label_snapshot);
        $this->assertSame('1', $item->seat_number_snapshot);
        $this->assertSame(SeatType::Standard->value, $item->seat_type_snapshot);
        $this->assertSame(self::PRICE, (int) $item->price_minor);
        $this->assertSame('MYR', $item->currency);
    }

    public function test_the_total_is_the_sum_of_its_items(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();

        $hold = $this->acquire(User::factory()->create(), $showtime, $this->ids($seats->take(3)));

        $sum = (int) DB::scalar('SELECT sum(price_minor) FROM hold_items WHERE hold_id = ?', [$hold->id]);

        $this->assertSame($hold->total_minor, $sum);
        $this->assertSame(3 * self::PRICE, $sum);
    }

    // --- inventory (INV-02) -----------------------------------------------

    public function test_it_moves_every_requested_seat_to_held(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();
        $requested = $seats->take(2);

        $hold = $this->acquire(User::factory()->create(), $showtime, $this->ids($requested));

        foreach ($requested as $seat) {
            $row = $this->inventory($showtime, $seat);
            $this->assertSame(SeatInventoryStatus::Held->value, $row->status);
            $this->assertSame($hold->id, (int) $row->hold_id);
            $this->assertNotNull($row->hold_expires_at);
            $this->assertNull($row->booking_id);
            $this->assertSame(2, (int) $row->version);
        }
    }

    public function test_it_leaves_seats_it_was_not_asked_for_alone(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();

        $this->acquire(User::factory()->create(), $showtime, $this->ids($seats->take(2)));

        foreach ($seats->skip(2) as $seat) {
            $row = $this->inventory($showtime, $seat);
            $this->assertSame(SeatInventoryStatus::Available->value, $row->status);
            $this->assertNull($row->hold_id);
            $this->assertSame(1, (int) $row->version);
        }
    }

    public function test_the_inventory_hold_expiry_matches_the_hold(): void
    {
        // The countdown the customer sees and the instant the seat frees must be
        // the same instant, or the two disagree at the boundary.
        [$showtime, $seats] = $this->publishedShowtime();

        $hold = $this->acquire(User::factory()->create(), $showtime, $this->ids($seats->take(1)));

        $mismatched = (int) DB::scalar(
            'SELECT count(*) FROM showtime_seats ss
             JOIN holds h ON h.id = ss.hold_id
             WHERE ss.hold_id = ? AND ss.hold_expires_at <> h.expires_at',
            [$hold->id]
        );

        $this->assertSame(0, $mismatched);
    }

    // --- refusals (AC-001, AC-002, RULE-003) ------------------------------

    public function test_a_seat_already_held_cannot_be_held_again(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();
        $seat = $seats->first();
        $this->acquire(User::factory()->create(), $showtime, [$seat->public_id]);

        $this->expectException(SeatsUnavailable::class);

        $this->acquire(User::factory()->create(), $showtime, [$seat->public_id]);
    }

    public function test_a_refused_multi_seat_request_holds_nothing(): void
    {
        // AC-002/RULE-003: all-or-none. The two free seats in this request must
        // be exactly as they were, and no hold row may survive.
        [$showtime, $seats] = $this->publishedShowtime();
        $taken = $seats->get(1);
        $this->acquire(User::factory()->create(), $showtime, [$taken->public_id]);

        $holdsBefore = (int) DB::scalar('SELECT count(*) FROM holds');

        try {
            $this->acquire(User::factory()->create(), $showtime, $this->ids($seats->take(3)));
            $this->fail('Expected the request to be refused.');
        } catch (SeatsUnavailable $e) {
            $this->assertSame([$taken->public_id], $e->seatIds());
        }

        $this->assertSame($holdsBefore, (int) DB::scalar('SELECT count(*) FROM holds'));

        foreach ([$seats->get(0), $seats->get(2)] as $seat) {
            $row = $this->inventory($showtime, $seat);
            $this->assertSame(SeatInventoryStatus::Available->value, $row->status);
            $this->assertNull($row->hold_id);
            $this->assertSame(1, (int) $row->version);
        }
    }

    public function test_a_showtime_that_started_between_map_and_request_is_refused(): void
    {
        // The map said on sale; PostgreSQL decides at commit time. RULE-021.
        [$showtime, $seats] = $this->publishedShowtime();
        DB::table('showtimes')->where('id', $showtime->id)->update([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->expectException(SeatsUnavailable::class);

        $this->acquire(User::factory()->create(), $showtime->fresh(), $this->ids($seats->take(1)));
    }

    public function test_a_cancelled_showtime_is_refused(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();
        DB::table('showtimes')->where('id', $showtime->id)->update([
            'status' => 'CANCELLED',
            'cancelled_at' => now(),
        ]);

        $this->expectException(SeatsUnavailable::class);

        $this->acquire(User::factory()->create(), $showtime->fresh(), $this->ids($seats->take(1)));
    }

    public function test_a_blocked_seat_is_refused(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();
        DB::table('showtime_seats')
            ->where('showtime_id', $showtime->id)->where('seat_id', $seats->first()->id)
            ->update(['status' => 'BLOCKED', 'blocked_reason_code' => 'MAINTENANCE']);

        $this->expectException(SeatsUnavailable::class);

        $this->acquire(User::factory()->create(), $showtime, $this->ids($seats->take(1)));
    }

    public function test_an_expired_hold_does_not_yet_release_its_seat(): void
    {
        // Deliberate phase boundary. Inline reclamation of an expired hold is a
        // Phase 4 deliverable; H3-02 acquires AVAILABLE rows only. The map shows
        // such a seat as selectable, so the customer can be refused a seat that
        // looked free — a rejection, never a false success, which is the side
        // GetSeatMap already documents as safe.
        [$showtime, $seats] = $this->publishedShowtime();
        $seat = $seats->first();
        $hold = $this->acquire(User::factory()->create(), $showtime, [$seat->public_id]);

        // created_at moves with it: holds_expiry_is_five_minutes forbids
        // rewriting the deadline on its own, which is how "no extension" is
        // expressed in the schema rather than only in the code.
        DB::statement(
            "UPDATE holds SET created_at = created_at - interval '6 minutes',
                              expires_at = expires_at - interval '6 minutes'
             WHERE id = ?",
            [$hold->id]
        );
        DB::table('showtime_seats')
            ->where('showtime_id', $showtime->id)->where('seat_id', $seat->id)
            ->update(['hold_expires_at' => now()->subMinute()]);

        $this->expectException(SeatsUnavailable::class);

        $this->acquire(User::factory()->create(), $showtime, [$seat->public_id]);
    }

    // --- accessible and companion under lock (§5.3) -----------------------

    public function test_a_companion_seat_is_refused_without_its_accessible_seat(): void
    {
        // Validation already rejects this, but the action is reachable directly
        // and §5.3 makes it an inventory invariant, not a form rule.
        [$showtime, , $companion] = $this->showtimeWithCompanionPair();

        $this->expectException(SeatsUnavailable::class);

        $this->acquire(User::factory()->create(), $showtime, [$companion->public_id], acknowledged: true);
    }

    public function test_an_accessible_seat_is_refused_without_the_declaration(): void
    {
        [$showtime, $accessible] = $this->showtimeWithCompanionPair();

        $this->expectException(SeatsUnavailable::class);

        $this->acquire(User::factory()->create(), $showtime, [$accessible->public_id], acknowledged: false);
    }

    public function test_a_declared_accessible_hold_records_the_acknowledgement(): void
    {
        // PP-10: the flag, when it was given, and the policy version. Nothing else.
        [$showtime, $accessible, $companion] = $this->showtimeWithCompanionPair();

        $hold = $this->acquire(
            User::factory()->create(),
            $showtime,
            [$accessible->public_id, $companion->public_id],
            acknowledged: true
        );

        $this->assertTrue($hold->accessible_acknowledged);
        $this->assertNotNull($hold->accessible_acknowledged_at);
        $this->assertNotNull($hold->accessibility_policy_version);
    }

    public function test_a_standard_hold_records_no_acknowledgement(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();

        $hold = $this->acquire(User::factory()->create(), $showtime, $this->ids($seats->take(1)));

        $this->assertFalse($hold->accessible_acknowledged);
        $this->assertNull($hold->accessible_acknowledged_at);
        $this->assertNull($hold->accessibility_policy_version);
    }

    // --- durable effects (HLD-01) -----------------------------------------

    public function test_it_writes_one_outbox_event_in_the_same_transaction(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();

        $hold = $this->acquire(User::factory()->create(), $showtime, $this->ids($seats->take(2)));

        $event = DB::selectOne(
            "SELECT * FROM outbox_events WHERE aggregate_type = 'HOLD' AND aggregate_id = ?",
            [$hold->id]
        );

        $this->assertNotNull($event);
        $this->assertSame('HOLD_CREATED', $event->event_type);
        $this->assertNull($event->processed_at);
        $this->assertSame(0, (int) $event->attempt_count);
    }

    public function test_it_writes_one_audit_event(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();
        $user = User::factory()->create();

        $hold = $this->acquire($user, $showtime, $this->ids($seats->take(1)));

        $event = DB::selectOne(
            "SELECT * FROM audit_events WHERE subject_type = 'HOLD' AND subject_id = ?",
            [$hold->id]
        );

        $this->assertNotNull($event);
        $this->assertSame('HOLD_ACQUIRED', $event->action);
        $this->assertSame('USER', $event->actor_type);
        $this->assertSame($user->id, (int) $event->actor_user_id);
        $this->assertSame('SUCCESS', $event->result);
    }

    public function test_a_refused_request_leaves_no_outbox_or_audit_trace(): void
    {
        // Everything is one transaction, so a refusal must not leak a durable
        // event describing a hold that does not exist.
        [$showtime, $seats] = $this->publishedShowtime();
        $seat = $seats->first();
        $this->acquire(User::factory()->create(), $showtime, [$seat->public_id]);

        $outbox = (int) DB::scalar('SELECT count(*) FROM outbox_events');
        $audit = (int) DB::scalar('SELECT count(*) FROM audit_events');

        try {
            $this->acquire(User::factory()->create(), $showtime, [$seat->public_id]);
        } catch (SeatsUnavailable) {
            // expected
        }

        $this->assertSame($outbox, (int) DB::scalar('SELECT count(*) FROM outbox_events'));
        $this->assertSame($audit, (int) DB::scalar('SELECT count(*) FROM audit_events'));
    }

    // --- database backstops (LOGICAL_ERD.md §2.4) -------------------------

    public function test_the_database_rejects_a_hold_longer_than_five_minutes(): void
    {
        // The application sets the interval, so this proves the constraint is a
        // real backstop rather than decoration.
        [$showtime, $seats] = $this->publishedShowtime();
        $hold = $this->acquire(User::factory()->create(), $showtime, $this->ids($seats->take(1)));

        $this->expectException(QueryException::class);

        DB::statement("UPDATE holds SET expires_at = created_at + interval '6 minutes' WHERE id = ?", [$hold->id]);
    }

    public function test_the_database_rejects_more_than_eight_seats(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();
        $hold = $this->acquire(User::factory()->create(), $showtime, $this->ids($seats->take(1)));

        $this->expectException(QueryException::class);

        DB::statement('UPDATE holds SET seat_count = 9 WHERE id = ?', [$hold->id]);
    }

    public function test_the_database_rejects_an_item_count_that_disagrees_with_the_hold(): void
    {
        // The deferred trigger required by LOGICAL_ERD.md §2.4: seat_count,
        // total_minor and the items must agree at commit.
        //
        // RefreshDatabase wraps each test in a transaction that is rolled back,
        // so a DEFERRED constraint trigger would never reach its commit point.
        // SET CONSTRAINTS ALL IMMEDIATE is what makes the backstop observable
        // here; without it this test would pass by never running the trigger.
        [$showtime, $seats] = $this->publishedShowtime();
        $hold = $this->acquire(User::factory()->create(), $showtime, $this->ids($seats->take(2)));

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->expectException(QueryException::class);

        DB::statement(
            'DELETE FROM hold_items
              WHERE hold_id = ?
                AND seat_id = (SELECT min(seat_id) FROM hold_items WHERE hold_id = ?)',
            [$hold->id, $hold->id]
        );
    }

    public function test_the_database_rejects_a_companion_item_without_its_accessible_seat(): void
    {
        // The same backstop for the pairing rule: MVP_POLICY_BASELINE.md §1 is
        // a data invariant, not only a form rule.
        [$showtime, $accessible, $companion] = $this->showtimeWithCompanionPair();
        $hold = $this->acquire(
            User::factory()->create(),
            $showtime,
            [$accessible->public_id, $companion->public_id],
            acknowledged: true
        );

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->expectException(QueryException::class);

        // Remove the accessible seat and keep the counts consistent, so the only
        // rule left to fail is the pairing one.
        DB::statement(
            'DELETE FROM hold_items WHERE hold_id = ? AND seat_id = ?',
            [$hold->id, $accessible->id]
        );
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @return array{Showtime, Seat, Seat}
     */
    private function showtimeWithCompanionPair(): array
    {
        $hall = Hall::factory()->create();
        $accessible = Seat::factory()->for($hall)->accessible()->create([
            'row_label' => 'F', 'seat_number' => '1', 'display_label' => 'F1', 'sort_order' => 0,
        ]);
        $companion = Seat::factory()->for($hall)->companion()->create([
            'row_label' => 'F', 'seat_number' => '2', 'display_label' => 'F2', 'sort_order' => 1,
        ]);
        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $companion->id,
        ]);
        // Force the adjacency trigger now, so the fixture proves the pair is
        // valid — then restore deferral, because a hold and its items are
        // written across statements and cannot survive an immediate check.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $showtime = Showtime::factory()->for($hall)->create(['price_minor' => self::PRICE]);
        app(PublishShowtime::class)->handle($showtime);

        return [$showtime->fresh(), $accessible, $companion];
    }

    private function inventory(Showtime $showtime, Seat $seat): object
    {
        $row = DB::selectOne(
            'SELECT * FROM showtime_seats WHERE showtime_id = ? AND seat_id = ?',
            [$showtime->id, $seat->id]
        );

        $this->assertNotNull($row);

        return $row;
    }
}
