<?php

namespace Tests\Integration\Holds;

use App\Actions\Showtimes\PublishShowtime;
use App\Models\Hall;
use App\Models\Seat;
use App\Models\Showtime;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Integration\IntegrationTestCase;

/**
 * H3-03 — scoped idempotency and lost-response recovery.
 *
 * The problem this solves is not a duplicate button press. It is the request
 * that succeeded, committed, and whose response never arrived: the client has
 * no way to tell that from a request that never ran, and the only safe retry is
 * one that returns the original outcome instead of acquiring more seats.
 *
 * The key is **required** here. P0 invariant 4 — duplicate commands have one
 * logical effect — is stated unconditionally, and a guarantee a client can opt
 * out of by omitting a header is not a guarantee.
 *
 * Trace: FR-017/028/029; RULE-006; NFR-002/007; AC-009; EX-09/13;
 * DOMAIN_STATE_MODELS.md §13, §14 (unique scoped idempotency key), §15.
 */
class HoldIdempotencyTest extends IntegrationTestCase
{
    use RefreshDatabase;

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

        $showtime = Showtime::factory()->for($hall)->create(['price_minor' => 1850]);
        app(PublishShowtime::class)->handle($showtime);

        return [$showtime->fresh(), $created];
    }

    private function url(Showtime $showtime): string
    {
        return "/showtimes/{$showtime->public_id}/holds";
    }

    /**
     * @param  array<int, string>  $seatIds
     * @return array<string, mixed>
     */
    private function body(array $seatIds): array
    {
        return ['seat_ids' => $seatIds, 'accessibility_acknowledged' => false];
    }

    /**
     * @return array<string, string>
     */
    private function key(string $value): array
    {
        return ['Idempotency-Key' => $value];
    }

    /**
     * `response_snapshot` is a jsonb column, and jsonb does not preserve key
     * order. A replayed response is therefore semantically identical to the
     * original rather than byte-identical, which is what these assertions mean.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ordered(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $payload[$key] = $this->ordered($value);
            }
        }

        return $payload;
    }

    private function holdCount(): int
    {
        return (int) DB::scalar('SELECT count(*) FROM holds');
    }

    // --- the key is required ----------------------------------------------

    public function test_a_hold_request_without_an_idempotency_key_is_rejected(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();

        $response = $this->actingAs(User::factory()->create())
            ->postJson($this->url($showtime), $this->body([$seats->first()->public_id]));

        $response->assertStatus(400)->assertJsonPath('reason_code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->assertSame(0, $this->holdCount());
    }

    public function test_a_blank_idempotency_key_is_rejected(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();

        $this->actingAs(User::factory()->create())
            ->withHeaders($this->key('   '))
            ->postJson($this->url($showtime), $this->body([$seats->first()->public_id]))
            ->assertStatus(400);

        $this->assertSame(0, $this->holdCount());
    }

    // --- lost-response recovery (AC-009, EX-09/13) ------------------------

    public function test_the_same_key_and_body_returns_the_first_result_without_a_second_hold(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();
        $user = User::factory()->create();
        $body = $this->body([$seats->first()->public_id]);

        $first = $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $body)->assertCreated();

        $second = $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $body)->assertCreated();

        $this->assertSame($this->ordered($first->json()), $this->ordered($second->json()));
        $this->assertSame(1, $this->holdCount());
    }

    public function test_the_replayed_response_carries_the_original_hold_identity(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();
        $user = User::factory()->create();
        $body = $this->body([$seats->first()->public_id]);

        $first = $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $body)->assertCreated();

        $replay = $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $body)->assertCreated();

        $this->assertSame($first->json('hold.id'), $replay->json('hold.id'));
        $this->assertSame($first->json('hold.expires_at'), $replay->json('hold.expires_at'));
    }

    public function test_a_replay_does_not_touch_the_inventory_again(): void
    {
        // The seat is already HELD by the first request. A replay that re-ran
        // the transaction would bump the version, which is exactly what the
        // seat map's polling watches.
        [$showtime, $seats] = $this->publishedShowtime();
        $user = User::factory()->create();
        $body = $this->body([$seats->first()->public_id]);

        $this->actingAs($user)->withHeaders($this->key('k-1'))->postJson($this->url($showtime), $body);
        $versionAfterFirst = DB::scalar(
            'SELECT version FROM showtime_seats WHERE showtime_id = ? AND seat_id = ?',
            [$showtime->id, $seats->first()->id]
        );

        $this->actingAs($user)->withHeaders($this->key('k-1'))->postJson($this->url($showtime), $body);

        $this->assertSame($versionAfterFirst, DB::scalar(
            'SELECT version FROM showtime_seats WHERE showtime_id = ? AND seat_id = ?',
            [$showtime->id, $seats->first()->id]
        ));
    }

    // --- key reuse with a different request -------------------------------

    public function test_the_same_key_with_a_different_body_is_rejected(): void
    {
        // §15: IDEMPOTENCY_KEY_REUSED. Replaying the first response would be a
        // lie about a request that was never made, and running the second would
        // break the guarantee the key exists to provide.
        [$showtime, $seats] = $this->publishedShowtime();
        $user = User::factory()->create();

        $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $this->body([$seats->get(0)->public_id]))
            ->assertCreated();

        $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $this->body([$seats->get(1)->public_id]))
            ->assertConflict()
            ->assertJsonPath('reason_code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, $this->holdCount());
    }

    public function test_seat_order_alone_is_not_a_different_request(): void
    {
        // A client retrying the same selection must not be told its key was
        // reused because the array arrived in another order.
        [$showtime, $seats] = $this->publishedShowtime();
        $user = User::factory()->create();
        $ids = [$seats->get(0)->public_id, $seats->get(1)->public_id];

        $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $this->body($ids))->assertCreated();

        $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $this->body(array_reverse($ids)))
            ->assertCreated();

        $this->assertSame(1, $this->holdCount());
    }

    // --- scoping ----------------------------------------------------------

    public function test_two_customers_may_use_the_same_key_text(): void
    {
        // The key is scoped to the principal. Two clients that both send "1"
        // are not the same logical request.
        [$showtime, $seats] = $this->publishedShowtime();

        $this->actingAs(User::factory()->create())->withHeaders($this->key('1'))
            ->postJson($this->url($showtime), $this->body([$seats->get(0)->public_id]))
            ->assertCreated();

        $this->actingAs(User::factory()->create())->withHeaders($this->key('1'))
            ->postJson($this->url($showtime), $this->body([$seats->get(1)->public_id]))
            ->assertCreated();

        $this->assertSame(2, $this->holdCount());
    }

    public function test_a_new_key_is_a_new_request(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();
        $user = User::factory()->create();

        $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $this->body([$seats->get(0)->public_id]))
            ->assertCreated();

        $this->actingAs($user)->withHeaders($this->key('k-2'))
            ->postJson($this->url($showtime), $this->body([$seats->get(1)->public_id]))
            ->assertCreated();

        $this->assertSame(2, $this->holdCount());
    }

    // --- concurrent duplicate submission ----------------------------------

    public function test_a_duplicate_arriving_while_the_first_is_still_running_is_refused(): void
    {
        // Simulates the in-flight window: a claim that exists and has not
        // settled. The record is produced by a real request and then rewound,
        // rather than hand-built, so it carries the fingerprint the middleware
        // actually computes — otherwise this would test key reuse instead.
        [$showtime, $seats] = $this->publishedShowtime();
        $user = User::factory()->create();
        $body = $this->body([$seats->first()->public_id]);

        $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $body)->assertCreated();

        DB::table('idempotency_keys')->update([
            'status' => 'IN_PROGRESS',
            'completed_at' => null,
            'http_status' => null,
            'response_snapshot' => null,
        ]);

        $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $body)
            ->assertConflict()
            ->assertJsonPath('reason_code', 'IDEMPOTENCY_IN_PROGRESS');

        // The duplicate ran nothing: still the one hold the first request made.
        $this->assertSame(1, $this->holdCount());
    }

    // --- what the key does and does not consume ---------------------------

    public function test_a_conflict_is_recorded_and_replayed(): void
    {
        // A refusal is an outcome, not an absence of one. Retrying the same
        // request must not produce a different answer just because the seat
        // freed up in between — that would be a second logical request.
        [$showtime, $seats] = $this->publishedShowtime();
        $seat = $seats->first();

        $this->actingAs(User::factory()->create())->withHeaders($this->key('winner'))
            ->postJson($this->url($showtime), $this->body([$seat->public_id]))->assertCreated();

        $loser = User::factory()->create();
        $body = $this->body([$seat->public_id]);

        $first = $this->actingAs($loser)->withHeaders($this->key('k-loser'))
            ->postJson($this->url($showtime), $body)->assertConflict();

        $replay = $this->actingAs($loser)->withHeaders($this->key('k-loser'))
            ->postJson($this->url($showtime), $body)->assertConflict();

        $this->assertSame($this->ordered($first->json()), $this->ordered($replay->json()));
        $this->assertSame(1, $this->holdCount());
    }

    public function test_a_validation_failure_releases_the_key(): void
    {
        // The command never ran, so there is no outcome to protect. Burning the
        // key here would force a customer who mistyped to invent a new one.
        [$showtime, $seats] = $this->publishedShowtime();
        $user = User::factory()->create();

        $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $this->body(['not-a-uuid']))
            ->assertUnprocessable();

        $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $this->body([$seats->first()->public_id]))
            ->assertCreated();

        $this->assertSame(1, $this->holdCount());
        $this->assertSame(1, (int) DB::scalar('SELECT count(*) FROM idempotency_keys'));
    }

    // --- what is stored ---------------------------------------------------

    public function test_the_stored_record_holds_hashes_and_not_the_client_key(): void
    {
        // LOGICAL_ERD.md §2.9: hashes, not raw client keys, and a non-PII
        // principal namespace.
        [$showtime, $seats] = $this->publishedShowtime();
        $user = User::factory()->create();

        $this->actingAs($user)->withHeaders($this->key('secret-key-value'))
            ->postJson($this->url($showtime), $this->body([$seats->first()->public_id]))
            ->assertCreated();

        $row = DB::selectOne('SELECT * FROM idempotency_keys LIMIT 1');

        $this->assertNotNull($row);
        $this->assertSame('holds.acquire', $row->scope);
        $this->assertSame('user:'.$user->id, $row->principal_key);
        $this->assertSame(hash('sha256', 'secret-key-value'), $row->key_hash);
        $this->assertNotSame('secret-key-value', $row->key_hash);
        $this->assertSame('COMPLETED', $row->status);
        $this->assertSame(201, (int) $row->http_status);
        $this->assertNotNull($row->completed_at);
        $this->assertNotNull($row->response_snapshot);

        $encoded = json_encode((array) $row);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('secret-key-value', $encoded);
        $this->assertStringNotContainsString($user->email, $encoded);
    }

    public function test_the_record_expires_thirty_days_out(): void
    {
        [$showtime, $seats] = $this->publishedShowtime();

        $this->actingAs(User::factory()->create())->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $this->body([$seats->first()->public_id]))
            ->assertCreated();

        $days = DB::scalar(
            'SELECT round(extract(epoch FROM (expires_at - created_at)) / 86400) FROM idempotency_keys LIMIT 1'
        );

        $this->assertSame(30, (int) $days);
    }

    public function test_the_database_rejects_a_duplicate_scoped_key(): void
    {
        // §14 requires a unique scoped idempotency key as a backstop, not only
        // an application check.
        [$showtime, $seats] = $this->publishedShowtime();
        $user = User::factory()->create();

        $this->actingAs($user)->withHeaders($this->key('k-1'))
            ->postJson($this->url($showtime), $this->body([$seats->first()->public_id]))
            ->assertCreated();

        $this->expectException(QueryException::class);

        DB::table('idempotency_keys')->insert([
            'scope' => 'holds.acquire',
            'principal_key' => 'user:'.$user->id,
            'key_hash' => hash('sha256', 'k-1'),
            'request_fingerprint' => hash('sha256', 'other'),
            'status' => 'IN_PROGRESS',
            'created_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }
}
