<?php

namespace Tests\Integration\Catalogue;

use App\Enums\SeatType;
use App\Models\Hall;
use App\Models\Seat;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\IntegrationTestCase;

/**
 * C2-01 — seat identity, classification, and ordering within a hall.
 *
 * Trace: FR-038; PP-10; ADR-005/012; NFR-010; LOGICAL_ERD.md §2.2.
 */
class SeatTest extends IntegrationTestCase
{
    use RefreshDatabase;

    public function test_a_seat_belongs_to_a_hall_and_a_hall_has_many_seats(): void
    {
        $hall = Hall::factory()->create();
        $seats = Seat::factory()->count(4)->for($hall)->create();

        $this->assertCount(4, $hall->seats);
        $this->assertTrue($seats->first()->hall->is($hall));
    }

    public function test_seat_identity_is_unique_within_a_hall(): void
    {
        $hall = Hall::factory()->create();
        Seat::factory()->for($hall)->create(['row_label' => 'A', 'seat_number' => '1']);

        $this->expectException(QueryException::class);

        Seat::factory()->for($hall)->create(['row_label' => 'A', 'seat_number' => '1']);
    }

    public function test_the_same_seat_identity_may_exist_in_another_hall(): void
    {
        $first = Seat::factory()->for(Hall::factory())->create(['row_label' => 'A', 'seat_number' => '1']);
        $second = Seat::factory()->for(Hall::factory())->create(['row_label' => 'A', 'seat_number' => '1']);

        $this->assertNotSame($first->hall_id, $second->hall_id);
        $this->assertDatabaseCount('seats', 2);
    }

    public function test_sort_order_is_unique_within_a_hall(): void
    {
        // sort_order is the canonical display and locking order within a hall.
        // LOGICAL_ERD.md §2.2.
        $hall = Hall::factory()->create();
        Seat::factory()->for($hall)->create(['sort_order' => 10]);

        $this->expectException(QueryException::class);

        Seat::factory()->for($hall)->create(['sort_order' => 10]);
    }

    public function test_sort_order_may_not_be_negative(): void
    {
        $this->expectException(QueryException::class);

        Seat::factory()->create(['sort_order' => -1]);
    }

    /**
     * @return array<string, array{SeatType}>
     */
    public static function seatTypes(): array
    {
        return [
            'standard' => [SeatType::Standard],
            'accessible' => [SeatType::Accessible],
            'companion' => [SeatType::Companion],
        ];
    }

    #[DataProvider('seatTypes')]
    public function test_every_declared_seat_type_is_accepted(SeatType $type): void
    {
        $seat = Seat::factory()->create(['seat_type' => $type]);

        $this->assertSame($type, $seat->fresh()->seat_type);
    }

    public function test_an_unknown_seat_type_is_rejected_by_the_database(): void
    {
        // The CHECK constraint is the backstop; the enum is the application guard.
        $hall = Hall::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('seats')->insert([
            'public_id' => (string) Str::uuid(),
            'hall_id' => $hall->id,
            'row_label' => 'Z',
            'seat_number' => '99',
            'display_label' => 'Z99',
            'seat_type' => 'THRONE',
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_seats_are_ordered_by_sort_order_within_a_hall(): void
    {
        $hall = Hall::factory()->create();
        Seat::factory()->for($hall)->create(['sort_order' => 2, 'display_label' => 'A3']);
        Seat::factory()->for($hall)->create(['sort_order' => 0, 'display_label' => 'A1']);
        Seat::factory()->for($hall)->create(['sort_order' => 1, 'display_label' => 'A2']);

        $this->assertSame(
            ['A1', 'A2', 'A3'],
            $hall->seats()->orderBy('sort_order')->pluck('display_label')->all()
        );
    }

    public function test_a_seat_may_be_deactivated_rather_than_deleted(): void
    {
        $seat = Seat::factory()->create(['is_active' => true]);

        $seat->update(['is_active' => false]);

        $this->assertFalse($seat->fresh()->is_active);
        $this->assertDatabaseCount('seats', 1);
    }
}
