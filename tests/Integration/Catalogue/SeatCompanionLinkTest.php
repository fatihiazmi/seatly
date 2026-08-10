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

        return [
            $hall,
            Seat::factory()->for($hall)->accessible()->create(['sort_order' => 0]),
            Seat::factory()->for($hall)->companion()->create(['sort_order' => 1]),
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
