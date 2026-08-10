# C2-01 red evidence

Captured 2026-08-10, before any migration, model, enum, or factory existed.

Command: `php artisan test --testsuite=Integration`

```text
tests: 37   passed: 6   failed: 2   errors: 29
```

The six passes are the pre-existing F1-03 environment cases. Every one of the 31
new cases failed, and all for the same reason — the classes under test did not
exist yet:

```text
Tests\Integration\Catalogue\MovieAndVenueTest::test_a_movie_is_created_with_an_opaque_public_id
  Class "App\Models\Movie" not found

Tests\Integration\Catalogue\HallTest::test_a_hall_belongs_to_a_venue_and_a_venue_has_many_halls
  Class "App\Models\Venue" not found

Tests\Integration\Catalogue\SeatTest::test_a_seat_belongs_to_a_hall_and_a_hall_has_many_seats
  Class "App\Models\Hall" not found

Tests\Integration\Catalogue\SeatCompanionLinkTest::test_an_accessible_seat_links_to_its_companion
  Class "App\Models\Hall" not found
```

Two cases initially failed for a different and unintended reason — PHPUnit 12
no longer reads `@dataProvider` doc-comment metadata:

```text
Too few arguments to function ...::test_a_movie_runtime_must_be_positive(),
0 passed and exactly 1 expected
```

Converted to `#[DataProvider]` attributes so those two also failed for the
intended reason before implementation began.
