<?php

namespace Tests\Feature;

use App\Actions\Showtimes\PublishShowtime;
use App\Enums\SeatInventoryStatus;
use App\Enums\SeatType;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\Seat;
use App\Models\SeatCompanionLink;
use App\Models\Showtime;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C2-04 — the minimal authoritative seat map.
 *
 * Stable seat identity and authoritative availability, nothing else. Geometry,
 * animation and the accessible seat grid are `L4-03`.
 *
 * Trace: FR-003/004/005; RULE-001/004; ADR-005/007/012/014; AC-001/002.
 */
class SeatMapTest extends TestCase
{
    use RefreshDatabase;

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

        $showtime = Showtime::factory()->for($hall)->create(['price_minor' => 1850]);
        app(PublishShowtime::class)->handle($showtime);

        return [$showtime->fresh(), $created];
    }

    private function url(Showtime $showtime): string
    {
        return "/showtimes/{$showtime->public_id}/seat-map";
    }

    // --- access -----------------------------------------------------------

    public function test_the_seat_map_is_public(): void
    {
        // ADR-014: browsing is public; authentication begins at the hold request.
        [$showtime] = $this->publishedShowtime();

        $this->getJson($this->url($showtime))->assertOk();
    }

    public function test_an_authenticated_customer_sees_the_same_map(): void
    {
        [$showtime] = $this->publishedShowtime();

        $this->actingAs(User::factory()->create())
            ->getJson($this->url($showtime))
            ->assertOk();
    }

    public function test_an_unknown_showtime_is_not_found(): void
    {
        $this->getJson('/showtimes/3f1d4d5e-0000-4000-8000-000000000000/seat-map')
            ->assertNotFound();
    }

    public function test_a_draft_showtime_has_no_public_seat_map(): void
    {
        // Nothing is materialized before publication, so there is no map to show
        // and no reason to disclose an unpublished showtime. ADR-005.
        $showtime = Showtime::factory()->create();

        $this->getJson($this->url($showtime))->assertNotFound();
    }

    public function test_the_internal_showtime_id_does_not_resolve(): void
    {
        [$showtime] = $this->publishedShowtime();

        $this->getJson("/showtimes/{$showtime->id}/seat-map")->assertNotFound();
    }

    // --- identity ---------------------------------------------------------

    public function test_it_returns_stable_seat_identity(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(2);
        $first = $seats->firstWhere('sort_order', 0);

        $response = $this->getJson($this->url($showtime))->assertOk();

        $response->assertJsonPath('seats.0.id', $first->public_id);
        $response->assertJsonPath('seats.0.label', $first->display_label);
        $response->assertJsonPath('seats.0.row', 'A');
        $response->assertJsonPath('seats.0.number', '1');
        $response->assertJsonPath('seats.0.type', SeatType::Standard->value);
    }

    public function test_it_never_exposes_internal_identifiers(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(3);

        $body = $this->getJson($this->url($showtime))->assertOk()->json();

        // Assert on keys and values, not on substrings: "linked_seat_id" is a
        // legitimate public field that happens to contain "seat_id".
        foreach (['seat_id', 'showtime_id', 'hold_id', 'booking_id', 'hall_id', 'version'] as $internal) {
            $this->assertArrayNotHasKey($internal, $body['seats'][0]);
        }

        // No internal bigint key travels, as an identifier or as a value.
        $exposed = array_column($body['seats'], 'id');
        foreach ($seats as $seat) {
            $this->assertNotContains($seat->id, $exposed);
            $this->assertNotContains((string) $seat->id, $exposed);
            $this->assertContains($seat->public_id, $exposed);
        }
        $this->assertNotSame((string) $showtime->id, $body['showtime']['id']);
    }

    public function test_seats_are_returned_in_canonical_order(): void
    {
        [$showtime] = $this->publishedShowtime(4);

        $labels = $this->getJson($this->url($showtime))->assertOk()->json('seats.*.label');

        $this->assertSame(['A1', 'A2', 'A3', 'A4'], $labels);
    }

    public function test_a_companion_pair_is_visible_on_the_map(): void
    {
        // PP-10: the UI must identify pairing, and a companion seat is only
        // selectable with its linked accessible seat.
        $hall = Hall::factory()->create();
        $accessible = Seat::factory()->for($hall)->accessible()->create(['row_label' => 'F', 'sort_order' => 0]);
        $companion = Seat::factory()->for($hall)->companion()->create(['row_label' => 'F', 'sort_order' => 1]);
        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $companion->id,
        ]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $showtime = Showtime::factory()->for($hall)->create();
        app(PublishShowtime::class)->handle($showtime);

        $seats = collect($this->getJson($this->url($showtime))->assertOk()->json('seats'))->keyBy('id');

        $this->assertSame($companion->public_id, $seats[$accessible->public_id]['linked_seat_id']);
        $this->assertSame($accessible->public_id, $seats[$companion->public_id]['linked_seat_id']);
    }

    // --- authoritative availability ---------------------------------------

    public function test_every_seat_is_selectable_on_a_freshly_published_showtime(): void
    {
        [$showtime] = $this->publishedShowtime(4);

        $selectable = $this->getJson($this->url($showtime))->assertOk()->json('seats.*.selectable');

        $this->assertSame([true, true, true, true], $selectable);
    }

    public function test_a_held_seat_is_not_selectable(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(2);
        $this->markInventory($showtime, $seats->first(), SeatInventoryStatus::Held, [
            'hold_id' => 1,
            'hold_expires_at' => now()->addMinutes(4),
        ]);

        $seat = $this->seatFromMap($showtime, $seats->first()->public_id);

        $this->assertFalse($seat['selectable']);
    }

    public function test_a_confirmed_seat_is_not_selectable(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(2);
        $this->markInventory($showtime, $seats->first(), SeatInventoryStatus::Confirmed, ['booking_id' => 1]);

        $this->assertFalse($this->seatFromMap($showtime, $seats->first()->public_id)['selectable']);
    }

    public function test_a_blocked_seat_is_not_selectable(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(2);
        $this->markInventory($showtime, $seats->first(), SeatInventoryStatus::Blocked, [
            'blocked_reason_code' => 'MAINTENANCE',
        ]);

        $this->assertFalse($this->seatFromMap($showtime, $seats->first()->public_id)['selectable']);
    }

    public function test_a_seat_whose_hold_has_expired_is_selectable_again(): void
    {
        // LOGICAL_ERD.md §5.1 treats an expired HELD row as reclaimable before
        // the cleanup worker touches it. The map applies the same rule, so it
        // cannot report a seat unavailable that a hold request would grant.
        [$showtime, $seats] = $this->publishedShowtime(2);
        $this->markInventory($showtime, $seats->first(), SeatInventoryStatus::Held, [
            'hold_id' => 1,
            'hold_expires_at' => now()->subSecond(),
        ]);

        $this->assertTrue($this->seatFromMap($showtime, $seats->first()->public_id)['selectable']);
    }

    public function test_the_map_does_not_disclose_why_a_seat_is_unavailable(): void
    {
        // "Held" versus "confirmed" tells a stranger whether someone is midway
        // through checkout. FR-004 only requires selectable versus unavailable.
        [$showtime, $seats] = $this->publishedShowtime(2);
        $this->markInventory($showtime, $seats->first(), SeatInventoryStatus::Held, [
            'hold_id' => 1,
            'hold_expires_at' => now()->addMinutes(4),
        ]);

        $encoded = json_encode($this->getJson($this->url($showtime))->json());

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('HELD', $encoded);
        $this->assertStringNotContainsString('CONFIRMED', $encoded);
        $this->assertStringNotContainsString('MAINTENANCE', $encoded);
    }

    // --- showtime status --------------------------------------------------

    public function test_a_published_upcoming_showtime_is_on_sale(): void
    {
        [$showtime] = $this->publishedShowtime();

        $this->getJson($this->url($showtime))->assertOk()->assertJsonPath('showtime.on_sale', true);
    }

    public function test_a_showtime_that_has_started_is_not_on_sale(): void
    {
        // FR-003: present the status and prevent booking initiation. The map is
        // still served, so a customer arriving on a stale link sees why.
        [$showtime] = $this->publishedShowtime();
        DB::table('showtimes')->where('id', $showtime->id)->update([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $response = $this->getJson($this->url($showtime))->assertOk();

        $response->assertJsonPath('showtime.on_sale', false);
        $this->assertSame([false, false, false, false], $response->json('seats.*.selectable'));
    }

    public function test_a_cancelled_showtime_is_not_on_sale(): void
    {
        [$showtime] = $this->publishedShowtime();
        DB::table('showtimes')->where('id', $showtime->id)->update([
            'status' => 'CANCELLED',
            'cancelled_at' => now(),
        ]);

        $this->getJson($this->url($showtime))->assertOk()->assertJsonPath('showtime.on_sale', false);
    }

    public function test_it_carries_the_showtime_facts_a_customer_needs(): void
    {
        [$showtime] = $this->publishedShowtime();
        $movie = Movie::find($showtime->movie_id);

        $response = $this->getJson($this->url($showtime))->assertOk();

        $response->assertJsonPath('showtime.id', $showtime->public_id);
        $response->assertJsonPath('showtime.price_minor', 1850);
        $response->assertJsonPath('showtime.currency', 'MYR');
        $response->assertJsonPath('showtime.movie.title', $movie?->title);
        $response->assertJsonPath('showtime.venue.timezone', 'Asia/Kuala_Lumpur');
    }

    // --- polling version --------------------------------------------------

    public function test_the_map_carries_an_inventory_version(): void
    {
        // ADR-007 and DOMAIN_STATE_MODELS.md §13: a client ignores a poll whose
        // inventory version is lower than one it has already seen. L4-04 builds
        // the polling itself; the endpoint only has to expose the number.
        [$showtime] = $this->publishedShowtime(4);

        $this->getJson($this->url($showtime))->assertOk()->assertJsonPath('inventory_version', 4);
    }

    public function test_the_inventory_version_rises_when_a_seat_changes(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(4);
        $before = $this->getJson($this->url($showtime))->json('inventory_version');

        $this->markInventory($showtime, $seats->first(), SeatInventoryStatus::Blocked, [
            'blocked_reason_code' => 'MAINTENANCE',
            'version' => 2,
        ]);

        $this->assertGreaterThan($before, $this->getJson($this->url($showtime))->json('inventory_version'));
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function markInventory(Showtime $showtime, Seat $seat, SeatInventoryStatus $status, array $attributes = []): void
    {
        DB::table('showtime_seats')
            ->where('showtime_id', $showtime->id)
            ->where('seat_id', $seat->id)
            ->update(['status' => $status->value] + $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function seatFromMap(Showtime $showtime, string $seatPublicId): array
    {
        $seats = $this->getJson($this->url($showtime))->assertOk()->json('seats');

        return collect($seats)->firstWhere('id', $seatPublicId);
    }
}
