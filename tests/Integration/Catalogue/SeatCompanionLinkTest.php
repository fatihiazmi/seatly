<?php

namespace Tests\Integration\Catalogue;

use App\Enums\SeatType;
use App\Models\Hall;
use App\Models\Seat;
use App\Models\SeatCompanionLink;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Integration\IntegrationTestCase;

/**
 * C2-01 — companion pairing in the fixed hall layout.
 *
 * A pair contains exactly one accessible seat and zero or one companion seat.
 * A seat cannot belong to multiple pairs. PostgreSQL CHECK constraints cannot
 * inspect the referenced seat rows, so the cross-row rules live in a deferred
 * constraint trigger.
 *
 * These tests run inside the RefreshDatabase transaction, which never commits,
 * so each one forces the deferred trigger with SET CONSTRAINTS ALL IMMEDIATE.
 * Without that the assertions would pass without the trigger ever running.
 *
 * Trace: PP-10; ADR-012; MVP_POLICY_BASELINE.md §1; LOGICAL_ERD.md §2.2.
 */
class SeatCompanionLinkTest extends IntegrationTestCase
{
    use RefreshDatabase;

    /**
     * @return array{Hall, Seat, Seat}
     */
    private function hallWithPairableSeats(): array
    {
        $hall = Hall::factory()->create();

        // row_label is set explicitly: adjacency requires the same row, and the
        // factory's running sequence would otherwise decide it for us.
        return [
            $hall,
            Seat::factory()->for($hall)->accessible()->create(['row_label' => 'F', 'sort_order' => 0]),
            Seat::factory()->for($hall)->companion()->create(['row_label' => 'F', 'sort_order' => 1]),
        ];
    }

    private function forceDeferredConstraints(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_an_accessible_seat_links_to_its_companion(): void
    {
        [, $accessible, $companion] = $this->hallWithPairableSeats();

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $companion->id,
        ]);
        $this->forceDeferredConstraints();

        $this->assertTrue($accessible->fresh()->companionSeat->is($companion));
        $this->assertTrue($companion->fresh()->accessibleSeat->is($accessible));
    }

    public function test_an_accessible_seat_may_stand_alone(): void
    {
        // "An accessible seat may be booked without a companion."
        // MVP_POLICY_BASELINE.md §1.
        [, $accessible] = $this->hallWithPairableSeats();

        $this->assertNull($accessible->companionSeat);
        $this->assertDatabaseCount('seat_companion_links', 0);
    }

    public function test_a_seat_cannot_be_linked_to_itself(): void
    {
        [, $accessible] = $this->hallWithPairableSeats();

        $this->expectException(QueryException::class);

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $accessible->id,
        ]);
    }

    public function test_an_accessible_seat_cannot_belong_to_two_pairs(): void
    {
        [$hall, $accessible, $companion] = $this->hallWithPairableSeats();
        $secondCompanion = Seat::factory()->for($hall)->companion()->create(['sort_order' => 2]);

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $companion->id,
        ]);

        $this->expectException(QueryException::class);

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $secondCompanion->id,
        ]);
    }

    public function test_a_companion_seat_cannot_belong_to_two_pairs(): void
    {
        [$hall, $accessible, $companion] = $this->hallWithPairableSeats();
        $secondAccessible = Seat::factory()->for($hall)->accessible()->create(['sort_order' => 2]);

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $companion->id,
        ]);

        $this->expectException(QueryException::class);

        SeatCompanionLink::create([
            'accessible_seat_id' => $secondAccessible->id,
            'companion_seat_id' => $companion->id,
        ]);
    }

    public function test_a_pair_cannot_span_two_halls(): void
    {
        [, $accessible] = $this->hallWithPairableSeats();
        $foreignCompanion = Seat::factory()->for(Hall::factory())->companion()->create();

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $foreignCompanion->id,
        ]);

        $this->expectException(QueryException::class);

        $this->forceDeferredConstraints();
    }

    public function test_the_accessible_side_must_be_classified_accessible(): void
    {
        $hall = Hall::factory()->create();
        $standard = Seat::factory()->for($hall)->create(['seat_type' => SeatType::Standard, 'sort_order' => 0]);
        $companion = Seat::factory()->for($hall)->companion()->create(['sort_order' => 1]);

        SeatCompanionLink::create([
            'accessible_seat_id' => $standard->id,
            'companion_seat_id' => $companion->id,
        ]);

        $this->expectException(QueryException::class);

        $this->forceDeferredConstraints();
    }

    public function test_the_companion_side_must_be_classified_companion(): void
    {
        $hall = Hall::factory()->create();
        $accessible = Seat::factory()->for($hall)->accessible()->create(['sort_order' => 0]);
        $standard = Seat::factory()->for($hall)->create(['seat_type' => SeatType::Standard, 'sort_order' => 1]);

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $standard->id,
        ]);

        $this->expectException(QueryException::class);

        $this->forceDeferredConstraints();
    }

    public function test_both_seats_in_a_pair_must_be_active(): void
    {
        [, $accessible, $companion] = $this->hallWithPairableSeats();
        $companion->update(['is_active' => false]);

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $companion->id,
        ]);

        $this->expectException(QueryException::class);

        $this->forceDeferredConstraints();
    }

    public function test_a_pair_must_sit_in_the_same_row(): void
    {
        // ADR-018.
        $hall = Hall::factory()->create();
        $accessible = Seat::factory()->for($hall)->accessible()->create(['row_label' => 'F', 'sort_order' => 9]);
        $companion = Seat::factory()->for($hall)->companion()->create(['row_label' => 'G', 'sort_order' => 10]);

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $companion->id,
        ]);

        $this->expectException(QueryException::class);

        $this->forceDeferredConstraints();
    }

    public function test_a_pair_must_be_consecutive_in_sort_order(): void
    {
        // A seat sitting between the two is not a pair, however the operator
        // describes it. ADR-018.
        $hall = Hall::factory()->create();
        $accessible = Seat::factory()->for($hall)->accessible()->create(['row_label' => 'F', 'sort_order' => 0]);
        Seat::factory()->for($hall)->create(['row_label' => 'F', 'sort_order' => 1]);
        $companion = Seat::factory()->for($hall)->companion()->create(['row_label' => 'F', 'sort_order' => 2]);

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $companion->id,
        ]);

        $this->expectException(QueryException::class);

        $this->forceDeferredConstraints();
    }

    public function test_the_companion_may_sit_on_either_side_of_the_accessible_seat(): void
    {
        // Adjacency is symmetric: |sort_order difference| = 1, not "companion
        // must come second". ADR-018.
        $hall = Hall::factory()->create();
        $companion = Seat::factory()->for($hall)->companion()->create(['row_label' => 'F', 'sort_order' => 0]);
        $accessible = Seat::factory()->for($hall)->accessible()->create(['row_label' => 'F', 'sort_order' => 1]);

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $companion->id,
        ]);
        $this->forceDeferredConstraints();

        $this->assertTrue($accessible->fresh()->companionSeat->is($companion));
    }

    public function test_a_skipped_seat_number_does_not_break_a_pair(): void
    {
        // Rows that omit a number — 13 is commonly skipped — stay pairable,
        // because adjacency is defined on sort_order and not on seat_number.
        // ADR-018.
        $hall = Hall::factory()->create();
        $accessible = Seat::factory()->for($hall)->accessible()->create([
            'row_label' => 'F', 'seat_number' => '12', 'display_label' => 'F12', 'sort_order' => 11,
        ]);
        $companion = Seat::factory()->for($hall)->companion()->create([
            'row_label' => 'F', 'seat_number' => '14', 'display_label' => 'F14', 'sort_order' => 12,
        ]);

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $companion->id,
        ]);
        $this->forceDeferredConstraints();

        $this->assertTrue($accessible->fresh()->companionSeat->is($companion));
    }

    public function test_consecutive_sort_order_across_a_row_boundary_is_not_adjacency(): void
    {
        // The last seat of one row and the first of the next are consecutive in
        // sort_order but physically far apart. The same-row rule is what stops
        // this. ADR-018.
        $hall = Hall::factory()->create();
        $accessible = Seat::factory()->for($hall)->accessible()->create(['row_label' => 'A', 'sort_order' => 19]);
        $companion = Seat::factory()->for($hall)->companion()->create(['row_label' => 'B', 'sort_order' => 20]);

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $companion->id,
        ]);

        $this->expectException(QueryException::class);

        $this->forceDeferredConstraints();
    }

    public function test_a_linked_seat_cannot_be_deleted(): void
    {
        [, $accessible, $companion] = $this->hallWithPairableSeats();

        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $companion->id,
        ]);
        $this->forceDeferredConstraints();

        $this->expectException(QueryException::class);

        $companion->delete();
    }
}
