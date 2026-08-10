<?php

namespace Tests\Integration\Showtimes;

use App\Actions\Showtimes\PublishShowtime;
use App\Enums\SeatInventoryStatus;
use App\Enums\ShowtimeStatus;
use App\Exceptions\ShowtimeNotPublishable;
use App\Models\Hall;
use App\Models\Seat;
use App\Models\Showtime;
use App\Models\ShowtimeSeat;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Integration\IntegrationTestCase;

/**
 * C2-02 — publication materializes the authoritative seat inventory.
 *
 * ST-03 publishes a draft; INV-01 inserts exactly one AVAILABLE row per active
 * physical seat, carrying the price snapshot. Both happen in one transaction.
 *
 * Trace: FR-003/039/040; RULE-001/004; ADR-005; AC-015;
 * DOMAIN_STATE_MODELS.md §4.2 ST-03, §5.2 INV-01.
 */
class PublishShowtimeTest extends IntegrationTestCase
{
    use RefreshDatabase;

    private function publish(Showtime $showtime): Showtime
    {
        return app(PublishShowtime::class)->handle($showtime);
    }

    private function draftInHallWithSeats(int $seats = 5): Showtime
    {
        $hall = Hall::factory()->create();
        Seat::factory()->count($seats)->for($hall)->create();

        return Showtime::factory()->for($hall)->create();
    }

    public function test_publishing_creates_exactly_one_inventory_row_per_active_seat(): void
    {
        $showtime = $this->draftInHallWithSeats(12);

        $this->publish($showtime);

        $this->assertDatabaseCount('showtime_seats', 12);
        $this->assertSame(
            12,
            ShowtimeSeat::where('showtime_id', $showtime->id)->distinct('seat_id')->count('seat_id')
        );
    }

    public function test_inactive_seats_are_not_materialized(): void
    {
        // INV-01: "Active physical seat included in valid fixed hall layout".
        $hall = Hall::factory()->create();
        Seat::factory()->count(3)->for($hall)->create();
        Seat::factory()->count(2)->for($hall)->inactive()->create();
        $showtime = Showtime::factory()->for($hall)->create();

        $this->publish($showtime);

        $this->assertDatabaseCount('showtime_seats', 3);
    }

    public function test_only_seats_from_the_showtimes_own_hall_are_materialized(): void
    {
        // RULE-001: a seat is inventory in the context of one showtime.
        $showtime = $this->draftInHallWithSeats(4);
        Seat::factory()->count(7)->for(Hall::factory())->create();

        $this->publish($showtime);

        $this->assertDatabaseCount('showtime_seats', 4);
    }

    public function test_every_materialized_row_starts_available_with_no_owner(): void
    {
        $showtime = $this->draftInHallWithSeats(3);

        $this->publish($showtime);

        // get(), not each(): the composite primary key rules out chunked helpers.
        ShowtimeSeat::where('showtime_id', $showtime->id)->get()->each(function (ShowtimeSeat $row): void {
            $this->assertSame(SeatInventoryStatus::Available, $row->status);
            $this->assertNull($row->hold_id);
            $this->assertNull($row->booking_id);
            $this->assertNull($row->hold_expires_at);
            $this->assertNull($row->blocked_reason_code);
            $this->assertSame(1, $row->version);
        });
    }

    public function test_the_price_snapshot_is_copied_from_the_showtime(): void
    {
        // LOGICAL_ERD.md §2.3: price_minor and currency are copied when inventory
        // is materialized. They are the source for hold snapshots, never
        // recomputed.
        $hall = Hall::factory()->create();
        Seat::factory()->count(2)->for($hall)->create();
        $showtime = Showtime::factory()->for($hall)->create([
            'price_minor' => 2350,
            'currency' => 'MYR',
        ]);

        $this->publish($showtime);

        $rows = ShowtimeSeat::where('showtime_id', $showtime->id)->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn (ShowtimeSeat $r): bool => $r->price_minor === 2350));
        $this->assertTrue($rows->every(fn (ShowtimeSeat $r): bool => $r->currency === 'MYR'));
    }

    public function test_a_later_showtime_price_change_does_not_move_the_snapshot(): void
    {
        $showtime = $this->draftInHallWithSeats(2);

        $this->publish($showtime);
        $original = ShowtimeSeat::where('showtime_id', $showtime->id)->value('price_minor');

        DB::table('showtimes')->where('id', $showtime->id)->update(['price_minor' => 9999]);

        $this->assertSame(
            $original,
            ShowtimeSeat::where('showtime_id', $showtime->id)->value('price_minor')
        );
    }

    public function test_publishing_marks_the_showtime_published_with_a_server_timestamp(): void
    {
        $showtime = $this->draftInHallWithSeats();

        $published = $this->publish($showtime);

        $this->assertSame(ShowtimeStatus::Published, $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertNull($published->cancelled_at);
    }

    public function test_republishing_is_refused_and_does_not_duplicate_inventory(): void
    {
        $showtime = $this->draftInHallWithSeats(6);
        $this->publish($showtime);

        try {
            $this->publish($showtime->fresh());
            $this->fail('Publishing an already published showtime should be refused.');
        } catch (ShowtimeNotPublishable $e) {
            $this->assertSame('SHOWTIME_NOT_DRAFT', $e->reasonCode);
        }

        $this->assertDatabaseCount('showtime_seats', 6);
    }

    public function test_a_seat_appears_at_most_once_per_showtime(): void
    {
        // The (showtime_id, seat_id) primary key is the backstop under the
        // application guard.
        $showtime = $this->draftInHallWithSeats(1);
        $this->publish($showtime);

        $existing = ShowtimeSeat::where('showtime_id', $showtime->id)->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('showtime_seats')->insert([
            'showtime_id' => $existing->showtime_id,
            'seat_id' => $existing->seat_id,
            'status' => SeatInventoryStatus::Available->value,
            'price_minor' => 1000,
            'currency' => 'MYR',
            'version' => 1,
            'updated_at' => now(),
        ]);
    }

    public function test_publication_rolls_back_entirely_when_inventory_cannot_be_written(): void
    {
        // Pre-seed one inventory row so the bulk insert collides on the primary
        // key. Nothing may survive: no partial inventory, no status change.
        $showtime = $this->draftInHallWithSeats(5);
        $seat = $showtime->hall->seats()->firstOrFail();

        DB::table('showtime_seats')->insert([
            'showtime_id' => $showtime->id,
            'seat_id' => $seat->id,
            'status' => SeatInventoryStatus::Available->value,
            'price_minor' => 1000,
            'currency' => 'MYR',
            'version' => 1,
            'updated_at' => now(),
        ]);

        try {
            $this->publish($showtime);
            $this->fail('Publication should have failed on the duplicate inventory row.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(ShowtimeStatus::Draft, $showtime->fresh()->status);
        $this->assertNull($showtime->fresh()->published_at);
        $this->assertDatabaseCount('showtime_seats', 1);
    }

    public function test_a_showtime_in_a_hall_with_no_active_seats_cannot_be_published(): void
    {
        $hall = Hall::factory()->create();
        Seat::factory()->count(2)->for($hall)->inactive()->create();
        $showtime = Showtime::factory()->for($hall)->create();

        try {
            $this->publish($showtime);
            $this->fail('A showtime with no sellable seat should not publish.');
        } catch (ShowtimeNotPublishable $e) {
            $this->assertSame('HALL_HAS_NO_ACTIVE_SEATS', $e->reasonCode);
        }

        $this->assertSame(ShowtimeStatus::Draft, $showtime->fresh()->status);
        $this->assertDatabaseCount('showtime_seats', 0);
    }

    public function test_a_showtime_starting_in_the_past_cannot_be_published(): void
    {
        // ST-03 guard: future start.
        $hall = Hall::factory()->create();
        Seat::factory()->count(2)->for($hall)->create();
        $showtime = Showtime::factory()->for($hall)->create([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        try {
            $this->publish($showtime);
            $this->fail('A showtime that has already started should not publish.');
        } catch (ShowtimeNotPublishable $e) {
            $this->assertSame('SHOWTIME_ALREADY_STARTED', $e->reasonCode);
        }

        $this->assertDatabaseCount('showtime_seats', 0);
    }

    public function test_an_inactive_movie_blocks_publication(): void
    {
        $showtime = $this->draftInHallWithSeats();
        $showtime->movie->update(['is_active' => false]);

        try {
            $this->publish($showtime->fresh());
            $this->fail('An inactive movie should block publication.');
        } catch (ShowtimeNotPublishable $e) {
            $this->assertSame('MOVIE_NOT_ACTIVE', $e->reasonCode);
        }
    }

    public function test_an_inactive_hall_blocks_publication(): void
    {
        $showtime = $this->draftInHallWithSeats();
        $showtime->hall->update(['is_active' => false]);

        try {
            $this->publish($showtime->fresh());
            $this->fail('An inactive hall should block publication.');
        } catch (ShowtimeNotPublishable $e) {
            $this->assertSame('HALL_NOT_ACTIVE', $e->reasonCode);
        }
    }

    public function test_an_inactive_venue_blocks_publication(): void
    {
        $showtime = $this->draftInHallWithSeats();
        $showtime->hall->venue->update(['is_active' => false]);

        try {
            $this->publish($showtime->fresh());
            $this->fail('An inactive venue should block publication.');
        } catch (ShowtimeNotPublishable $e) {
            $this->assertSame('VENUE_NOT_ACTIVE', $e->reasonCode);
        }
    }

    public function test_a_cancelled_showtime_cannot_be_republished(): void
    {
        // Forbidden transition: CANCELLED -> PUBLISHED.
        $hall = Hall::factory()->create();
        Seat::factory()->count(2)->for($hall)->create();
        $showtime = Showtime::factory()->cancelled()->for($hall)->create();

        try {
            $this->publish($showtime);
            $this->fail('A cancelled showtime must never be republished.');
        } catch (ShowtimeNotPublishable $e) {
            $this->assertSame('SHOWTIME_NOT_DRAFT', $e->reasonCode);
        }

        $this->assertDatabaseCount('showtime_seats', 0);
    }

    public function test_publication_is_refused_when_it_would_overlap_a_published_showtime(): void
    {
        // The exclusion constraint is the backstop; publication should refuse
        // before reaching it. ST-03 guard: no conflicting hall schedule.
        $hall = Hall::factory()->create();
        Seat::factory()->count(2)->for($hall)->create();
        $start = now()->addDays(2)->startOfHour();

        Showtime::factory()->published()->for($hall)->create([
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(2),
        ]);

        $clashing = Showtime::factory()->for($hall)->create([
            'starts_at' => $start->copy()->addHour(),
            'ends_at' => $start->copy()->addHours(3),
        ]);

        try {
            $this->publish($clashing);
            $this->fail('An overlapping publication should be refused.');
        } catch (ShowtimeNotPublishable $e) {
            $this->assertSame('HALL_SCHEDULE_CONFLICT', $e->reasonCode);
        }

        $this->assertSame(ShowtimeStatus::Draft, $clashing->fresh()->status);
    }

    public function test_inventory_covers_every_active_seat_exactly_once(): void
    {
        // The coverage invariant C2-02 exists to establish, expressed as SQL
        // rather than as a count: no active seat missing, no extra row.
        $showtime = $this->draftInHallWithSeats(9);

        $this->publish($showtime);

        $missing = DB::selectOne(<<<'SQL'
            SELECT count(*) AS n
            FROM seats s
            WHERE s.hall_id = ?
              AND s.is_active
              AND NOT EXISTS (
                  SELECT 1 FROM showtime_seats ss
                  WHERE ss.showtime_id = ? AND ss.seat_id = s.id
              )
        SQL, [$showtime->hall_id, $showtime->id]);

        $extra = DB::selectOne(<<<'SQL'
            SELECT count(*) AS n
            FROM showtime_seats ss
            JOIN seats s ON s.id = ss.seat_id
            WHERE ss.showtime_id = ?
              AND (s.hall_id <> ? OR NOT s.is_active)
        SQL, [$showtime->id, $showtime->hall_id]);

        $this->assertSame(0, (int) $missing->n, 'An active seat has no inventory row.');
        $this->assertSame(0, (int) $extra->n, 'Inventory exists for a seat outside the active hall layout.');
    }
}
