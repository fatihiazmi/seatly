<?php

namespace Tests\Integration\Showtimes;

use App\Enums\ShowtimeStatus;
use App\Models\Hall;
use App\Models\Showtime;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\IntegrationTestCase;

/**
 * C2-02 — the showtime record itself: its constraints, its states, and the
 * derived "on sale" rule.
 *
 * Trace: FR-003/039/040; RULE-001/004; ADR-005; LOGICAL_ERD.md §2.3;
 * DOMAIN_STATE_MODELS.md §4.
 */
class ShowtimeTest extends IntegrationTestCase
{
    use RefreshDatabase;

    public function test_a_showtime_starts_as_a_draft(): void
    {
        $showtime = Showtime::factory()->create();

        $this->assertSame(ShowtimeStatus::Draft, $showtime->status);
        $this->assertNull($showtime->published_at);
        $this->assertNull($showtime->cancelled_at);
    }

    public function test_a_showtime_must_end_after_it_starts(): void
    {
        $this->expectException(QueryException::class);

        Showtime::factory()->create([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->subMinute(),
        ]);
    }

    public function test_a_showtime_price_may_not_be_negative(): void
    {
        $this->expectException(QueryException::class);

        Showtime::factory()->create(['price_minor' => -1]);
    }

    public function test_a_showtime_price_of_zero_is_allowed(): void
    {
        // A free preview screening is a business decision, not a schema error.
        $showtime = Showtime::factory()->create(['price_minor' => 0]);

        $this->assertSame(0, $showtime->price_minor);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCurrencies(): array
    {
        return [
            'lowercase' => ['myr'],
            'too short' => ['MY'],
            'numeric' => ['123'],
        ];
    }

    #[DataProvider('malformedCurrencies')]
    public function test_currency_must_be_uppercase_iso_format(string $currency): void
    {
        $this->expectException(QueryException::class);

        Showtime::factory()->create(['currency' => $currency]);
    }

    public function test_money_is_stored_as_integer_minor_units_with_a_currency(): void
    {
        // PP-11: MYR, money in integer sen.
        $showtime = Showtime::factory()->create(['price_minor' => 1850, 'currency' => 'MYR']);

        $this->assertSame(1850, $showtime->fresh()->price_minor);
        $this->assertSame('MYR', $showtime->fresh()->currency);
    }

    public function test_the_cancellation_cutoff_defaults_to_two_hours(): void
    {
        // ADR-016: the cutoff lives on the showtime and is later snapshotted onto
        // the booking, so a policy change cannot re-term a booking already sold.
        $showtime = Showtime::factory()->create();

        $this->assertSame(120, $showtime->cancellation_cutoff_minutes);
    }

    public function test_the_cancellation_cutoff_may_not_be_negative(): void
    {
        $this->expectException(QueryException::class);

        Showtime::factory()->create(['cancellation_cutoff_minutes' => -1]);
    }

    public function test_version_must_be_positive(): void
    {
        $this->expectException(QueryException::class);

        Showtime::factory()->create(['version' => 0]);
    }

    public function test_a_draft_may_not_carry_a_published_timestamp(): void
    {
        $this->expectException(QueryException::class);

        Showtime::factory()->create([
            'status' => ShowtimeStatus::Draft,
            'published_at' => now(),
        ]);
    }

    public function test_a_published_showtime_must_carry_a_published_timestamp(): void
    {
        $this->expectException(QueryException::class);

        Showtime::factory()->create([
            'status' => ShowtimeStatus::Published,
            'published_at' => null,
        ]);
    }

    public function test_a_cancelled_showtime_must_carry_a_cancelled_timestamp(): void
    {
        $this->expectException(QueryException::class);

        Showtime::factory()->create([
            'status' => ShowtimeStatus::Cancelled,
            'cancelled_at' => null,
        ]);
    }

    public function test_an_unknown_status_is_rejected_by_the_database(): void
    {
        $showtime = Showtime::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('showtimes')->where('id', $showtime->id)->update(['status' => 'ENDED']);
    }

    public function test_two_published_showtimes_may_not_overlap_in_the_same_hall(): void
    {
        // Exclusion constraint over (hall_id, tstzrange(starts_at, ends_at)).
        // LOGICAL_ERD.md §2.3.
        $hall = Hall::factory()->create();
        $start = Carbon::parse('2026-09-01 12:00:00', 'UTC');

        Showtime::factory()->published()->for($hall)->create([
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(2),
        ]);

        $this->expectException(QueryException::class);

        Showtime::factory()->published()->for($hall)->create([
            'starts_at' => $start->copy()->addHour(),
            'ends_at' => $start->copy()->addHours(3),
        ]);
    }

    public function test_published_showtimes_may_touch_end_to_start_in_the_same_hall(): void
    {
        // The range is half-open '[)', so one ending exactly as the next begins
        // is not an overlap.
        $hall = Hall::factory()->create();
        $start = Carbon::parse('2026-09-01 12:00:00', 'UTC');

        Showtime::factory()->published()->for($hall)->create([
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(2),
        ]);
        Showtime::factory()->published()->for($hall)->create([
            'starts_at' => $start->copy()->addHours(2),
            'ends_at' => $start->copy()->addHours(4),
        ]);

        $this->assertDatabaseCount('showtimes', 2);
    }

    public function test_overlapping_showtimes_are_allowed_while_both_are_drafts(): void
    {
        // The exclusion constraint is partial: WHERE status = 'PUBLISHED'.
        // Planning two candidate slots is legitimate; publishing both is not.
        $hall = Hall::factory()->create();
        $start = Carbon::parse('2026-09-01 12:00:00', 'UTC');

        Showtime::factory()->for($hall)->create([
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(2),
        ]);
        Showtime::factory()->for($hall)->create([
            'starts_at' => $start->copy()->addHour(),
            'ends_at' => $start->copy()->addHours(3),
        ]);

        $this->assertDatabaseCount('showtimes', 2);
    }

    public function test_overlapping_published_showtimes_are_allowed_in_different_halls(): void
    {
        $start = Carbon::parse('2026-09-01 12:00:00', 'UTC');

        Showtime::factory()->published()->create([
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(2),
        ]);
        Showtime::factory()->published()->create([
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(2),
        ]);

        $this->assertDatabaseCount('showtimes', 2);
    }

    public function test_a_showtime_belongs_to_a_movie_and_a_hall(): void
    {
        $showtime = Showtime::factory()->create();

        $this->assertNotNull($showtime->movie);
        $this->assertNotNull($showtime->hall);
        $this->assertNotNull($showtime->hall->venue);
    }

    public function test_on_sale_is_derived_from_status_and_the_server_clock(): void
    {
        // There is no stored ENDED state (RC-03). "On sale" is
        // status = PUBLISHED AND transaction_timestamp() < starts_at, so a
        // finished showtime cannot read as bookable because a job failed to run.
        $future = Showtime::factory()->published()->create([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);
        $past = Showtime::factory()->published()->create([
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHour(),
        ]);
        $draft = Showtime::factory()->create([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);

        $onSale = Showtime::onSale()->pluck('id');

        $this->assertTrue($onSale->contains($future->id));
        $this->assertFalse($onSale->contains($past->id));
        $this->assertFalse($onSale->contains($draft->id));
    }

    public function test_a_cancelled_showtime_is_never_on_sale(): void
    {
        $cancelled = Showtime::factory()->cancelled()->create([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);

        $this->assertFalse(Showtime::onSale()->pluck('id')->contains($cancelled->id));
    }
}
