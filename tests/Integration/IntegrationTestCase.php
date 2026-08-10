<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base case for every test that needs real PostgreSQL semantics: transactions,
 * row locks, constraints, the server clock, the queue, and the outbox.
 *
 * F1-02 requires this layer to fail clearly rather than silently pass when it is
 * pointed at SQLite, because SQLite would report success without ever exercising
 * the behaviour the P0 invariants depend on.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $driver = DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            $this->fail(
                "Seatly integration tests require PostgreSQL, but the default connection uses [{$driver}]. "
                .'SQLite and in-memory drivers cannot demonstrate row locking, transaction isolation, '
                .'the server clock, or exclusion constraints, so a pass here would be meaningless. '
                .'Start the database with `docker compose up -d` and check DB_CONNECTION.'
            );
        }
    }
}
