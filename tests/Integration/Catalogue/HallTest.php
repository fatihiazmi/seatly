<?php

namespace Tests\Integration\Catalogue;

use App\Models\Hall;
use App\Models\Seat;
use App\Models\Venue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\IntegrationTestCase;

/**
 * C2-01 — halls and their relationship to venues and seats.
 *
 * Trace: FR-038; NFR-010; ADR-005; LOGICAL_ERD.md §2.2.
 */
class HallTest extends IntegrationTestCase
{
    use RefreshDatabase;

    public function test_a_hall_belongs_to_a_venue_and_a_venue_has_many_halls(): void
    {
        $venue = Venue::factory()->create();
        $halls = Hall::factory()->count(3)->for($venue)->create();

        $this->assertTrue($venue->halls->pluck('id')->contains($halls->first()->id));
        $this->assertCount(3, $venue->halls);
        $this->assertTrue($halls->first()->venue->is($venue));
    }

    public function test_a_hall_code_is_unique_within_its_venue(): void
    {
        $venue = Venue::factory()->create();
        Hall::factory()->for($venue)->create(['code' => 'HALL-1']);

        $this->expectException(QueryException::class);

        Hall::factory()->for($venue)->create(['code' => 'HALL-1']);
    }

    public function test_the_same_hall_code_may_be_reused_in_a_different_venue(): void
    {
        $first = Venue::factory()->create();
        $second = Venue::factory()->create();

        Hall::factory()->for($first)->create(['code' => 'HALL-1']);
        $other = Hall::factory()->for($second)->create(['code' => 'HALL-1']);

        $this->assertSame('HALL-1', $other->code);
        $this->assertDatabaseCount('halls', 2);
    }

    public function test_a_hall_layout_version_must_be_positive(): void
    {
        $this->expectException(QueryException::class);

        Hall::factory()->create(['layout_version' => 0]);
    }

    public function test_a_hall_starts_at_layout_version_one(): void
    {
        $hall = Hall::factory()->create();

        $this->assertSame(1, $hall->layout_version);
    }

    public function test_a_hall_cannot_reference_a_venue_that_does_not_exist(): void
    {
        $this->expectException(QueryException::class);

        DB::table('halls')->insert([
            'public_id' => (string) Str::uuid(),
            'venue_id' => 999_999,
            'code' => 'GHOST',
            'name' => 'Ghost Hall',
            'layout_version' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_venue_with_halls_cannot_be_deleted(): void
    {
        // Referenced halls are retained; deactivation is preferred over deletion.
        // LOGICAL_ERD.md §1, NFR-010.
        $venue = Venue::factory()->create();
        Hall::factory()->for($venue)->create();

        $this->expectException(QueryException::class);

        $venue->delete();
    }

    public function test_a_hall_with_seats_cannot_be_deleted(): void
    {
        $hall = Hall::factory()->create();
        Seat::factory()->for($hall)->create();

        $this->expectException(QueryException::class);

        $hall->delete();
    }
}
