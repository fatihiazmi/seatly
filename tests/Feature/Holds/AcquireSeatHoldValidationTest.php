<?php

namespace Tests\Feature\Holds;

use App\Actions\Showtimes\PublishShowtime;
use App\Enums\SeatInventoryStatus;
use App\Models\Hall;
use App\Models\Seat;
use App\Models\SeatCompanionLink;
use App\Models\Showtime;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * H3-01 — everything a hold request can be rejected for before a transaction
 * opens.
 *
 * This slice is the cheap gate. It rejects what is decidable from the request
 * and the current map, so `H3-02` only has to defend against what genuinely
 * requires locks. Nothing here is authoritative: a request that passes every
 * check below may still lose a seat between validation and the locked re-read,
 * which is exactly why `H3-02` revalidates rather than trusts this.
 *
 * Status codes, chosen here and recorded because the requirements do not name
 * them:
 *
 * - **422** — the customer can fix it by editing the request: seat count,
 *   duplicates, unknown seats, seats outside this showtime, a missing accessible
 *   declaration, a companion seat without its accessible seat.
 * - **409** — the customer cannot: the showtime is not on sale. `H3-02` returns
 *   the same status for a seat lost under lock, so a losing request in the
 *   `H3-04` harness is one deterministic class, not two.
 * - **404** — the showtime is not publicly resolvable at all (draft, unknown).
 *
 * A request that survives every check here reaches `AcquireSeatHold` and answers
 * 201. These cases assert that boundary, not the acquisition itself — the
 * transaction is proved in `Tests\Integration\Holds\AcquireSeatHoldTest`.
 *
 * Trace: FR-001/003/006-010; RULE-003/010-013; PP-01-04/10; AC-002/004.
 */
class AcquireSeatHoldValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // H3-03 makes the key mandatory on this endpoint. Every case below is
        // about validation, and each makes exactly one request, so one fresh
        // key per test keeps idempotency out of the way of what is being tested.
        $this->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    /**
     * @return array{Showtime, Collection<int, Seat>}
     */
    private function publishedShowtime(int $seats = 10): array
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

    /**
     * A hall with one linked accessible/companion pair, adjacent per ADR-018.
     *
     * @return array{Showtime, Seat, Seat}
     */
    private function showtimeWithCompanionPair(): array
    {
        $hall = Hall::factory()->create();
        $standard = Seat::factory()->for($hall)->create([
            'row_label' => 'F', 'seat_number' => '1', 'display_label' => 'F1', 'sort_order' => 0,
        ]);
        $accessible = Seat::factory()->for($hall)->accessible()->create([
            'row_label' => 'F', 'seat_number' => '2', 'display_label' => 'F2', 'sort_order' => 1,
        ]);
        $companion = Seat::factory()->for($hall)->companion()->create([
            'row_label' => 'F', 'seat_number' => '3', 'display_label' => 'F3', 'sort_order' => 2,
        ]);
        SeatCompanionLink::create([
            'accessible_seat_id' => $accessible->id,
            'companion_seat_id' => $companion->id,
        ]);
        // Fire the adjacency trigger now to prove the fixture is valid, then
        // restore deferral: a hold and its items are written across statements
        // and their integrity trigger is only meaningful at commit.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $showtime = Showtime::factory()->for($hall)->create();
        app(PublishShowtime::class)->handle($showtime);

        // $standard exists so a pair test can add an unrelated seat.
        unset($standard);

        return [$showtime->fresh(), $accessible, $companion];
    }

    private function url(Showtime $showtime): string
    {
        return "/showtimes/{$showtime->public_id}/holds";
    }

    /**
     * @param  array<int, string>  $seatIds
     * @return array<string, mixed>
     */
    private function payload(array $seatIds, bool $acknowledged = false): array
    {
        return [
            'seat_ids' => $seatIds,
            'accessibility_acknowledged' => $acknowledged,
        ];
    }

    // --- authentication ---------------------------------------------------

    public function test_a_hold_requires_authentication(): void
    {
        // ADR-014/PP-01: browsing is public, authentication begins here.
        [$showtime, $seats] = $this->publishedShowtime(2);

        $this->postJson($this->url($showtime), $this->payload([$seats->first()->public_id]))
            ->assertUnauthorized();
    }

    public function test_an_unauthenticated_request_changes_no_inventory(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(2);

        $this->postJson($this->url($showtime), $this->payload([$seats->first()->public_id]));

        $this->assertInventoryUntouched($showtime);
    }

    // --- showtime resolution ----------------------------------------------

    public function test_an_unknown_showtime_is_not_found(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/showtimes/3f1d4d5e-0000-4000-8000-000000000000/holds', $this->payload([]))
            ->assertNotFound();
    }

    public function test_a_draft_showtime_is_not_found(): void
    {
        // A draft has no materialized inventory and is not public. ADR-005.
        $showtime = Showtime::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload([]))
            ->assertNotFound();
    }

    public function test_the_internal_showtime_id_does_not_resolve(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(2);

        $this->actingAs(User::factory()->create())
            ->postJson("/showtimes/{$showtime->id}/holds", $this->payload([$seats->first()->public_id]))
            ->assertNotFound();
    }

    // --- seat count (RULE-011, PP-02, AC-004) ------------------------------

    public function test_at_least_one_seat_is_required(): void
    {
        [$showtime] = $this->publishedShowtime(2);

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload([]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seat_ids');
    }

    public function test_seat_ids_must_be_present(): void
    {
        [$showtime] = $this->publishedShowtime(2);

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), ['accessibility_acknowledged' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seat_ids');
    }

    public function test_eight_seats_are_allowed(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(10);

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload(
                $seats->take(8)->pluck('public_id')->all()
            ))
            ->assertCreated();
    }

    public function test_nine_seats_are_rejected(): void
    {
        // RULE-011/PP-02: eight is the approved maximum, and holds.seat_count
        // carries the same bound as a CHECK constraint.
        [$showtime, $seats] = $this->publishedShowtime(10);

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload(
                $seats->take(9)->pluck('public_id')->all()
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seat_ids');
    }

    public function test_the_seat_limit_message_is_written_for_a_customer(): void
    {
        // AC-004 asks for "an accessible validation message". Laravel's default
        // is "The seat_ids field must not have more than 8 items", which names
        // the payload key and counts items rather than seats.
        [$showtime, $seats] = $this->publishedShowtime(10);

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload(
                $seats->take(9)->pluck('public_id')->all()
            ))
            ->assertUnprocessable()
            ->assertJsonPath('errors.seat_ids.0', 'You can hold up to 8 seats in one booking.');
    }

    public function test_duplicate_seat_ids_are_rejected(): void
    {
        // DOMAIN_STATE_MODELS.md §12.2 opens with "reject duplicate seat IDs".
        // Nine copies of one seat must not pass as one seat either.
        [$showtime, $seats] = $this->publishedShowtime(2);
        $id = $seats->first()->public_id;

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload([$id, $id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seat_ids.1');
    }

    // --- seat identity ----------------------------------------------------

    public function test_an_unknown_seat_is_rejected(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(2);

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload([
                $seats->first()->public_id,
                '3f1d4d5e-0000-4000-8000-000000000000',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seat_ids.1');
    }

    public function test_a_seat_from_another_showtime_is_rejected(): void
    {
        // HLD-01 and hold_items both require every seat to belong to the one
        // showtime being held.
        [$showtime, $seats] = $this->publishedShowtime(2);
        [, $otherSeats] = $this->publishedShowtime(2);

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload([
                $seats->first()->public_id,
                $otherSeats->first()->public_id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seat_ids.1');
    }

    public function test_a_malformed_seat_id_is_rejected_without_reaching_postgres(): void
    {
        // C2-04 shipped a 500 because a malformed identifier reached a uuid
        // comparison. The same mistake must not return through the request body.
        [$showtime] = $this->publishedShowtime(2);

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload(['not-a-uuid']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seat_ids.0');
    }

    // --- showtime state (FR-003) ------------------------------------------

    public function test_a_showtime_that_has_started_cannot_be_held(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(2);
        DB::table('showtimes')->where('id', $showtime->id)->update([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload([$seats->first()->public_id]))
            ->assertConflict();
    }

    public function test_a_cancelled_showtime_cannot_be_held(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(2);
        DB::table('showtimes')->where('id', $showtime->id)->update([
            'status' => 'CANCELLED',
            'cancelled_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload([$seats->first()->public_id]))
            ->assertConflict();
    }

    // --- accessible seating (PP-10) ---------------------------------------

    public function test_an_accessible_seat_requires_the_declaration(): void
    {
        // MVP_POLICY_BASELINE.md §1: an unticked self-declaration, and nothing
        // beyond the acknowledgement is asked for or stored.
        [$showtime, $accessible] = $this->showtimeWithCompanionPair();

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload([$accessible->public_id], acknowledged: false))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accessibility_acknowledged');
    }

    public function test_an_accessible_seat_passes_with_the_declaration(): void
    {
        [$showtime, $accessible] = $this->showtimeWithCompanionPair();

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload([$accessible->public_id], acknowledged: true))
            ->assertCreated();
    }

    public function test_an_accessible_seat_may_be_held_without_its_companion(): void
    {
        // MVP_POLICY_BASELINE.md §1: "An accessible seat may be booked without
        // a companion."
        [$showtime, $accessible] = $this->showtimeWithCompanionPair();

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload([$accessible->public_id], acknowledged: true))
            ->assertCreated();
    }

    public function test_the_declaration_is_not_required_for_standard_seats(): void
    {
        [$showtime, $seats] = $this->publishedShowtime(2);

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload([$seats->first()->public_id], acknowledged: false))
            ->assertCreated();
    }

    // --- companion pairing ------------------------------------------------

    public function test_a_companion_seat_alone_is_rejected(): void
    {
        // MVP_POLICY_BASELINE.md §1: a companion seat may be held only with its
        // linked accessible seat in the same booking and showtime.
        [$showtime, , $companion] = $this->showtimeWithCompanionPair();

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload([$companion->public_id], acknowledged: true))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seat_ids');
    }

    public function test_a_companion_seat_passes_alongside_its_accessible_seat(): void
    {
        [$showtime, $accessible, $companion] = $this->showtimeWithCompanionPair();

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload(
                [$accessible->public_id, $companion->public_id],
                acknowledged: true
            ))
            ->assertCreated();
    }

    // --- no partial effect (RULE-003, AC-002) -----------------------------

    public function test_a_rejected_request_changes_no_inventory(): void
    {
        // AC-002 in its cheapest form: a request rejected before the transaction
        // must leave every seat exactly as it found it, including the seats that
        // were individually valid.
        [$showtime, $seats] = $this->publishedShowtime(10);

        $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->payload(
                [...$seats->take(7)->pluck('public_id')->all(), 'not-a-uuid']
            ))
            ->assertUnprocessable();

        $this->assertInventoryUntouched($showtime);
    }

    private function assertInventoryUntouched(Showtime $showtime): void
    {
        $rows = DB::table('showtime_seats')->where('showtime_id', $showtime->id)->get();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(SeatInventoryStatus::Available->value, $row->status);
            $this->assertNull($row->hold_id);
            $this->assertSame(1, (int) $row->version);
        }
    }
}
