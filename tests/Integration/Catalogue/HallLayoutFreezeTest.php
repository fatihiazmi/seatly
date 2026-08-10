<?php

namespace Tests\Integration\Catalogue;

use App\Enums\SeatType;
use App\Models\Hall;
use App\Models\Seat;
use App\Models\Showtime;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Integration\IntegrationTestCase;

/**
 * C2-03 — structural integrity after publication.
 *
 * A hall's sellable seat set, seat identity, ordering and classification are
 * frozen while an upcoming published showtime references it. Descriptive
 * attributes stay editable, because holds and bookings snapshot the seat label
 * they sold.
 *
 * Enforced by a database trigger, so a direct SQL write cannot corrupt
 * published inventory either.
 *
 * Trace: FR-038/041; NFR-010; ADR-005/019; LOGICAL_ERD.md §2.2.
 */
class HallLayoutFreezeTest extends IntegrationTestCase
{
    use RefreshDatabase;

    /**
     * @return array{Hall, Seat}
     */
    private function frozenHall(): array
    {
        $hall = Hall::factory()->create();
        $seat = Seat::factory()->for($hall)->create(['row_label' => 'A', 'seat_number' => '1', 'sort_order' => 0]);

        Showtime::factory()->published()->for($hall)->create([
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ]);

        return [$hall->fresh(), $seat->fresh()];
    }

    // --- when the hall is not frozen -------------------------------------

    public function test_seats_may_be_added_to_a_hall_with_no_showtimes(): void
    {
        $hall = Hall::factory()->create();

        Seat::factory()->for($hall)->create();

        $this->assertCount(1, $hall->seats()->get());
    }

    public function test_a_draft_showtime_does_not_freeze_the_hall(): void
    {
        // Nothing is materialized until publication, so there is nothing to
        // corrupt. ADR-005.
        $hall = Hall::factory()->create();
        Showtime::factory()->for($hall)->create();

        Seat::factory()->for($hall)->create();

        $this->assertCount(1, $hall->seats()->get());
    }

    public function test_a_showtime_that_has_already_started_does_not_freeze_the_hall(): void
    {
        // Past bookings are protected by their snapshots, not by the freeze.
        // A hall frozen by every showtime it ever hosted would be frozen
        // permanently. ADR-019.
        $hall = Hall::factory()->create();
        Showtime::factory()->published()->for($hall)->create([
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHour(),
        ]);

        Seat::factory()->for($hall)->create();

        $this->assertCount(1, $hall->seats()->get());
    }

    public function test_a_cancelled_showtime_does_not_freeze_the_hall(): void
    {
        $hall = Hall::factory()->create();
        Showtime::factory()->cancelled()->for($hall)->create([
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ]);

        Seat::factory()->for($hall)->create();

        $this->assertCount(1, $hall->seats()->get());
    }

    public function test_the_freeze_applies_only_to_the_hall_that_is_published(): void
    {
        [$frozen] = $this->frozenHall();
        $sibling = Hall::factory()->create(['venue_id' => $frozen->venue_id]);

        Seat::factory()->for($sibling)->create();

        $this->assertCount(1, $sibling->seats()->get());
    }

    // --- frozen: the sellable seat set -----------------------------------

    public function test_a_seat_cannot_be_added_while_frozen(): void
    {
        [$hall] = $this->frozenHall();

        $this->expectException(QueryException::class);

        Seat::factory()->for($hall)->create(['row_label' => 'Z', 'seat_number' => '99', 'sort_order' => 500]);
    }

    public function test_a_seat_cannot_be_deleted_while_frozen(): void
    {
        [, $seat] = $this->frozenHall();

        $this->expectException(QueryException::class);

        $seat->delete();
    }

    public function test_a_seat_cannot_be_deactivated_while_frozen(): void
    {
        // Deactivation would remove a seat that already has an inventory row.
        [, $seat] = $this->frozenHall();

        $this->expectException(QueryException::class);

        $seat->update(['is_active' => false]);
    }

    public function test_a_seat_cannot_be_moved_to_another_hall_while_frozen(): void
    {
        [, $seat] = $this->frozenHall();
        $elsewhere = Hall::factory()->create();

        $this->expectException(QueryException::class);

        $seat->update(['hall_id' => $elsewhere->id]);
    }

    // --- frozen: identity, ordering, classification ----------------------

    public function test_a_seat_row_label_cannot_change_while_frozen(): void
    {
        // row_label carries the ADR-018 adjacency rule for existing pairs.
        [, $seat] = $this->frozenHall();

        $this->expectException(QueryException::class);

        $seat->update(['row_label' => 'B']);
    }

    public function test_a_seat_number_cannot_change_while_frozen(): void
    {
        [, $seat] = $this->frozenHall();

        $this->expectException(QueryException::class);

        $seat->update(['seat_number' => '7']);
    }

    public function test_seat_sort_order_cannot_change_while_frozen(): void
    {
        [, $seat] = $this->frozenHall();

        $this->expectException(QueryException::class);

        $seat->update(['sort_order' => 42]);
    }

    public function test_seat_type_cannot_change_while_frozen(): void
    {
        [, $seat] = $this->frozenHall();

        $this->expectException(QueryException::class);

        $seat->update(['seat_type' => SeatType::Accessible]);
    }

    public function test_a_hall_cannot_be_deactivated_while_frozen(): void
    {
        // It would leave an upcoming published showtime in a retired hall.
        [$hall] = $this->frozenHall();

        $this->expectException(QueryException::class);

        $hall->update(['is_active' => false]);
    }

    // --- frozen: what stays editable -------------------------------------

    public function test_a_seat_display_label_may_be_corrected_while_frozen(): void
    {
        // Holds and bookings snapshot the label they sold, so correcting a typo
        // cannot rewrite what a customer bought. ADR-019.
        [, $seat] = $this->frozenHall();

        $seat->update(['display_label' => 'A1 (aisle)']);

        $this->assertSame('A1 (aisle)', $seat->fresh()->display_label);
    }

    public function test_seat_geometry_may_be_adjusted_while_frozen(): void
    {
        [, $seat] = $this->frozenHall();

        $seat->update(['x_position' => 4, 'y_position' => 9]);

        $this->assertSame(4, $seat->fresh()->x_position);
    }

    public function test_a_hall_may_be_renamed_while_frozen(): void
    {
        [$hall] = $this->frozenHall();

        $hall->update(['name' => 'Hall 1 (Dolby)']);

        $this->assertSame('Hall 1 (Dolby)', $hall->fresh()->name);
    }

    // --- layout_version ---------------------------------------------------

    public function test_layout_version_increments_on_every_structural_change(): void
    {
        // ADR-019: layout_version answers "has this layout changed since?", so it
        // is monotonic rather than meaningful in absolute terms. Laying out the
        // initial seats counts — building a 60-seat hall leaves it at 61 — because
        // there is no notion of a layout being "finished" to start counting from.
        $hall = Hall::factory()->create();
        $this->assertSame(1, $hall->fresh()->layout_version, 'A hall with no seats starts at 1.');

        $seat = Seat::factory()->for($hall)->create(['sort_order' => 0]);
        $this->assertSame(2, $hall->fresh()->layout_version, 'Adding the first seat is a structural change.');

        Seat::factory()->for($hall)->create(['sort_order' => 1]);
        $this->assertSame(3, $hall->fresh()->layout_version);

        $seat->update(['sort_order' => 9]);
        $this->assertSame(4, $hall->fresh()->layout_version);

        $seat->delete();
        $this->assertSame(5, $hall->fresh()->layout_version);
    }

    public function test_layout_version_does_not_move_on_a_descriptive_change(): void
    {
        $hall = Hall::factory()->create();
        $seat = Seat::factory()->for($hall)->create();
        $before = $hall->fresh()->layout_version;

        $seat->update(['display_label' => 'Renamed']);

        $this->assertSame($before, $hall->fresh()->layout_version);
    }

    // --- the application-level guard -------------------------------------

    public function test_a_hall_reports_whether_its_layout_is_frozen(): void
    {
        [$frozen] = $this->frozenHall();
        $open = Hall::factory()->create();

        $this->assertTrue($frozen->layoutIsFrozen());
        $this->assertFalse($open->layoutIsFrozen());
    }
}
