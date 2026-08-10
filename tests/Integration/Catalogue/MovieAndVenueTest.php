<?php

namespace Tests\Integration\Catalogue;

use App\Models\Movie;
use App\Models\Venue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\IntegrationTestCase;

/**
 * C2-01 — movies and venues.
 *
 * Trace: FR-037; NFR-010; LOGICAL_ERD.md §2.2.
 */
class MovieAndVenueTest extends IntegrationTestCase
{
    use RefreshDatabase;

    public function test_a_movie_is_created_with_an_opaque_public_id(): void
    {
        $movie = Movie::factory()->create(['title' => 'Sinar Terakhir']);

        $this->assertNotNull($movie->public_id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $movie->public_id
        );
        // The internal key must never be the externally exposed one.
        $this->assertNotSame((string) $movie->id, $movie->public_id);
    }

    public function test_movie_public_ids_are_unique(): void
    {
        $existing = Movie::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('movies')->insert([
            'public_id' => $existing->public_id,
            'title' => 'Duplicate',
            'runtime_minutes' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonPositiveRuntimes(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    #[DataProvider('nonPositiveRuntimes')]
    public function test_a_movie_runtime_must_be_positive(int $runtime): void
    {
        $this->expectException(QueryException::class);

        Movie::factory()->create(['runtime_minutes' => $runtime]);
    }

    public function test_a_movie_may_be_deactivated_rather_than_deleted(): void
    {
        // No soft deletion; catalogue records use is_active. LOGICAL_ERD.md §1.
        $movie = Movie::factory()->create(['is_active' => true]);

        $movie->update(['is_active' => false]);

        $this->assertFalse($movie->fresh()->is_active);
        $this->assertDatabaseCount('movies', 1);
    }

    public function test_a_venue_carries_the_timezone_that_governs_its_policy_boundaries(): void
    {
        // Persistence is UTC; the venue timezone controls display and policy
        // boundaries such as the cancellation cutoff. LOGICAL_ERD.md §1, PP-11.
        $venue = Venue::factory()->create();

        $this->assertSame('Asia/Kuala_Lumpur', $venue->timezone);
    }

    // Note: the ERD assigns IANA timezone validation to the application, not the
    // database — a CHECK constraint cannot consult pg_timezone_names because that
    // lookup is not immutable. It belongs with the venue admin form, so it is
    // tested there, not here.
}
