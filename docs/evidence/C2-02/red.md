# C2-02 red evidence

Captured 2026-08-10, before `showtimes`, `showtime_seats`, the two enums, the
exception, the models, the factory, or the publish action existed.

Command: `php artisan test --testsuite=Integration --filter=Showtimes`

```text
tests: 40   passed: 0   failed: 40
```

Every one of the 40 new cases failed, all on missing classes:

```text
Tests\Integration\Showtimes\ShowtimeTest::test_a_showtime_must_end_after_it_starts
  Failed asserting that exception of type "Error" matches expected exception
  "Illuminate\Database\QueryException".
  Message was: "Class "App\Models\Showtime" not found"

Tests\Integration\Showtimes\PublishShowtimeTest::*
  Class "App\Models\Showtime" not found
  Class "App\Actions\Showtimes\PublishShowtime" not found
  Class "App\Models\ShowtimeSeat" not found
```
