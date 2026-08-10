# C2-04 red evidence

Captured 2026-08-10, before the route, controller and `GetSeatMap` action existed.

Command: `php artisan test --testsuite=Feature --filter=SeatMapTest`

```text
tests: 21   passed: 4   failed: 17
```

Every failure was the same: no route.

```text
Tests\Feature\SeatMapTest::test_the_seat_map_is_public
  Expected response status code [200] but received 404.
```

The four that passed were the negative cases — unknown showtime, draft showtime,
internal id — which return 404 whether the endpoint exists or not. They are not
evidence of anything on their own, which is why the positive cases carry the
slice.
