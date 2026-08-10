<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;

/**
 * F1-02/F1-03 — smoke test for the PostgreSQL integration layer.
 *
 * Asserts the assumptions later phases depend on: a real server, the extensions
 * the logical ERD requires, an authoritative server clock, and UTC timestamptz
 * storage. First demonstrated red; see docs/evidence/F1-03/red.md.
 *
 * Trace: BR-02; RULE-004; NFR-001; ADR-002/004; LOGICAL_ERD.md.
 */
class PostgresEnvironmentTest extends IntegrationTestCase
{
    public function test_it_runs_against_the_dedicated_integration_database(): void
    {
        $this->assertSame('seatly_test', DB::connection()->getDatabaseName());
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_the_server_is_postgresql_16(): void
    {
        $version = DB::scalar('show server_version');

        $this->assertMatchesRegularExpression('/^16\./', $version, "Expected PostgreSQL 16.x, got [{$version}].");
    }

    public function test_the_extensions_required_by_the_logical_erd_are_installed(): void
    {
        $installed = DB::table('pg_extension')->pluck('extname')->all();

        // btree_gist backs the showtime overlap exclusion constraint;
        // pgcrypto generates public_id UUIDs. LOGICAL_ERD.md "Required extensions".
        $this->assertContains('btree_gist', $installed);
        $this->assertContains('pgcrypto', $installed);
    }

    public function test_the_server_clock_is_authoritative_and_utc(): void
    {
        // Expiry and cancellation boundaries are decided by the server, never the
        // application or the browser. RULE-021, ADR-004.
        // Assert the offset, not the zone name: PostgreSQL reports this session's
        // zone as 'Etc/UTC', which is the same instant as 'UTC'.
        $row = DB::selectOne('select transaction_timestamp() as ts, extract(timezone from now()) as offset_seconds');

        $this->assertNotNull($row->ts);
        $this->assertSame(0, (int) $row->offset_seconds);
    }

    public function test_timestamptz_round_trips_without_losing_the_instant(): void
    {
        $row = DB::selectOne(
            "select ('2026-08-10 12:00:00+08'::timestamptz at time zone 'UTC')::text as utc"
        );

        $this->assertSame('2026-08-10 04:00:00', $row->utc);
    }
}
