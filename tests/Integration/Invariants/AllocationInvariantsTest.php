<?php

namespace Tests\Integration\Invariants;

use App\Actions\Holds\AcquireSeatHold;
use App\Actions\Showtimes\PublishShowtime;
use App\Models\Hall;
use App\Models\Hold;
use App\Models\Seat;
use App\Models\SeatCompanionLink;
use App\Models\Showtime;
use App\Models\User;
use App\Support\Invariants\AllocationInvariants;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\IntegrationTestCase;

/**
 * H3-06 — the post-run invariant SQL.
 *
 * A concurrency harness that only reads its own responses is grading its own
 * homework: it can report "one winner" while the database holds two. These
 * queries are the independent check, run against committed state afterwards.
 *
 * Every check is proved twice here — clean data returns nothing, and a planted
 * violation is found. A check that has never gone red proves nothing.
 *
 * Two are deliberately unprovable by planting, because a constraint makes the
 * bad state unreachable. Those are asserted clean and named as such, so the
 * report never implies a coverage it does not have.
 */
class AllocationInvariantsTest extends IntegrationTestCase
{
    use RefreshDatabase;

    private const PRICE = 1850;

    /**
     * @return array{Showtime, Collection<int, Seat>}
     */
    private function publishedShowtime(int $seats = 4): array
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
     * @param  array<int, string>  $seatIds
     */
    private function acquire(Showtime $showtime, array $seatIds, bool $acknowledged = false): Hold
    {
        return app(AcquireSeatHold::class)->handle(
            User::factory()->create(),
            $showtime,
            $seatIds,
            $acknowledged
        );
    }

    /**
     * @return array<string, int>
     */
    private function violations(): array
    {
        $counts = [];

        foreach (app(AllocationInvariants::class)->run() as $result) {
            $counts[$result['name']] = $result['violations'];
        }

        return $counts;
    }

    private function assertClean(): void
    {
        foreach ($this->violations() as $name => $count) {
            $this->assertSame(0, $count, "invariant {$name} reported {$count} violation(s)");
        }
    }

    private function assertViolation(string $name): void
    {
        $counts = $this->violations();

        $this->assertArrayHasKey($name, $counts);
        $this->assertGreaterThan(0, $counts[$name], "expected invariant {$name} to fire");
    }

    // --- clean state -------------------------------------------------------

    public function test_an_empty_database_is_clean(): void
    {
        $this->assertClean();
    }

    public function test_a_published_showtime_with_no_holds_is_clean(): void
    {
        $this->publishedShowtime();

        $this->assertClean();
    }

    public function test_ordinary_holds_are_clean(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(4);
        $this->acquire($showtime, [$seats->get(0)->public_id, $seats->get(1)->public_id]);
        $this->acquire($showtime, [$seats->get(2)->public_id]);

        $this->assertClean();
    }

    public function test_a_companion_pair_held_together_is_clean(): void
    {
        [$showtime, $accessible, $companion] = $this->showtimeWithCompanionPair();
        $this->acquire($showtime, [$accessible->public_id, $companion->public_id], acknowledged: true);

        $this->assertClean();
    }

    // --- planted violations ------------------------------------------------

    public function test_it_finds_two_active_holds_claiming_one_seat(): void
    {
        // The violation the whole gate exists to rule out.
        [$showtime, $seats] = $this->publishedShowtime();
        $seat = $seats->first();
        $this->acquire($showtime, [$seat->public_id]);

        $this->plantSecondHoldOn($showtime, $seat);

        $this->assertViolation('duplicate_active_seat_ownership');
    }

    public function test_it_finds_a_hold_that_does_not_own_all_of_its_seats(): void
    {
        // A partial hold: two snapshot lines, one seat actually held. Nothing in
        // the schema forbids it once the rows exist, which is why it is checked.
        [$showtime, $seats] = $this->publishedShowtime();
        $hold = $this->acquire($showtime, [$seats->get(0)->public_id, $seats->get(1)->public_id]);

        $this->releaseInventoryRow($showtime, $seats->get(1)->id);

        $this->assertViolation('partial_hold_ownership');
        $this->assertViolation('hold_coverage_mismatch');
    }

    public function test_it_finds_an_inventory_expiry_that_disagrees_with_its_hold(): void
    {
        // The countdown and the reclaim instant must be one value. If they drift,
        // a seat is either freed early or held past its deadline.
        [$showtime, $seats] = $this->publishedShowtime();
        $hold = $this->acquire($showtime, [$seats->first()->public_id]);

        DB::statement(
            "UPDATE showtime_seats SET hold_expires_at = hold_expires_at + interval '1 minute' WHERE hold_id = ?",
            [$hold->getKey()]
        );

        $this->assertViolation('hold_expiry_mismatch');
    }

    public function test_it_finds_a_hold_whose_total_disagrees_with_its_items(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();
        $hold = $this->acquire($showtime, [$seats->first()->public_id]);

        DB::statement('UPDATE holds SET total_minor = total_minor + 1 WHERE id = ?', [$hold->getKey()]);

        $this->assertViolation('hold_totals_disagree');
    }

    public function test_it_finds_a_held_companion_seat_without_its_accessible_seat(): void
    {
        [$showtime, $accessible, $companion] = $this->showtimeWithCompanionPair();
        $hold = $this->acquire($showtime, [$accessible->public_id, $companion->public_id], acknowledged: true);

        DB::statement(
            'DELETE FROM hold_items WHERE hold_id = ? AND seat_id = ?',
            [$hold->getKey(), $accessible->id]
        );

        $this->assertViolation('orphan_companion_hold');
    }

    // --- checks a constraint makes unreachable ----------------------------

    public function test_a_dangling_hold_pointer_cannot_be_created(): void
    {
        // The composite FK added by H3-02 is the enforcement; the invariant is
        // defence in depth for a restored or hand-edited database.
        [$showtime, $seats] = $this->publishedShowtime();
        $this->acquire($showtime, [$seats->first()->public_id]);

        $this->expectException(QueryException::class);

        DB::statement('UPDATE showtime_seats SET hold_id = 999999 WHERE hold_id IS NOT NULL');
    }

    public function test_an_invalid_linkage_cannot_be_created(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();
        $this->acquire($showtime, [$seats->first()->public_id]);

        $this->expectException(QueryException::class);

        // HELD without an expiry is one of the combinations the CHECK forbids.
        DB::statement('UPDATE showtime_seats SET hold_expires_at = NULL WHERE hold_id IS NOT NULL');
    }

    // --- reporting ---------------------------------------------------------

    public function test_every_check_reports_its_name_and_a_description(): void
    {
        foreach (app(AllocationInvariants::class)->run() as $result) {
            $this->assertNotSame('', $result['name']);
            $this->assertNotSame('', $result['description']);
            $this->assertIsInt($result['violations']);
        }
    }

    public function test_deferred_checks_are_named_rather_than_omitted(): void
    {
        // H3-06 requires a duplicate-confirmed-ownership check. `bookings`
        // arrives in Phase 5, so the check must announce itself as deferred
        // rather than quietly not exist.
        $deferred = array_column(
            array_filter(app(AllocationInvariants::class)->run(), fn (array $r): bool => $r['deferred']),
            'name'
        );

        $this->assertContains('duplicate_confirmed_seat_ownership', $deferred);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * A second ACTIVE hold snapshotting a seat the first one already owns.
     *
     * Written by hand because the action cannot produce it — that is the point.
     */
    private function plantSecondHoldOn(Showtime $showtime, Seat $seat): void
    {
        $id = DB::table('holds')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'user_id' => User::factory()->create()->id,
            'showtime_id' => $showtime->id,
            'status' => 'ACTIVE',
            'seat_count' => 1,
            'total_minor' => self::PRICE,
            'currency' => 'MYR',
            'created_at' => DB::raw('transaction_timestamp()'),
            'expires_at' => DB::raw("transaction_timestamp() + interval '5 minutes'"),
        ]);

        DB::table('hold_items')->insert([
            'hold_id' => $id,
            'showtime_id' => $showtime->id,
            'seat_id' => $seat->id,
            'seat_label_snapshot' => $seat->display_label,
            'row_label_snapshot' => $seat->row_label,
            'seat_number_snapshot' => $seat->seat_number,
            'seat_type_snapshot' => $seat->seat_type->value,
            'price_minor' => self::PRICE,
            'currency' => 'MYR',
        ]);
    }

    private function releaseInventoryRow(Showtime $showtime, int $seatId): void
    {
        DB::table('showtime_seats')
            ->where('showtime_id', $showtime->id)
            ->where('seat_id', $seatId)
            ->update([
                'status' => 'AVAILABLE',
                'hold_id' => null,
                'hold_expires_at' => null,
            ]);
    }

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
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $showtime = Showtime::factory()->for($hall)->create(['price_minor' => self::PRICE]);
        app(PublishShowtime::class)->handle($showtime);

        return [$showtime->fresh(), $accessible, $companion];
    }
}
