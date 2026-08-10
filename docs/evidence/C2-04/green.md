# C2-04 green evidence

Captured 2026-08-10T08:12:34Z · PostgreSQL 16.14

## Suite
```
{"tool":"phpunit","result":"passed","tests":21,"passed":21,"assertions":63,"duration_ms":1530}
```

Whole chain:
```

{"tool":"pint","result":"passed"}{"tool":"phpstan","result":"passed","errors":0}
{"tool":"phpunit","result":"passed","tests":173,"passed":173,"assertions":372,"duration_ms":8495}
```

## Completeness check — the real endpoint, real seeded data

Tests passing proves correctness, not completeness. Served with
`php artisan serve` and driven with curl against `seatly_dev`.

```text
GET /showtimes/891303c3-.../seat-map            -> 200
GET /showtimes/<draft public_id>/seat-map       -> 404
GET /showtimes/3f1d4d5e-0000-.../seat-map       -> 404   (unknown)
GET /showtimes/4/seat-map                       -> 404   (malformed)
```

The malformed case is why the route carries `whereUuid`. Without it the
value reached PostgreSQL, where comparing `4` to a `uuid` column raised a
cast error and the endpoint answered 500 instead of 404.

Response for the seeded 6x10 hall:

```json
{
  "showtime": {
    "id": "891303c3-4943-4504-9fba-a9f5a01387e1",
    "starts_at": "2026-08-11T13:00:00+00:00",
    "on_sale": true,
    "price_minor": 1850,
    "currency": "MYR",
    "movie": { "title": "Sinar Terakhir", "runtime_minutes": 118, "classification": "P13" },
    "hall": { "name": "Hall 1" },
    "venue": { "name": "Seatly Mid Valley", "timezone": "Asia/Kuala_Lumpur" }
  },
  "inventory_version": 60,
  "seats": [
    { "id": "8d2149b0-...", "label": "A1", "row": "A", "number": "1",
      "type": "STANDARD", "selectable": true, "linked_seat_id": null },
    { "id": "67cfad5d-...", "label": "F1", "row": "F", "number": "1",
      "type": "ACCESSIBLE", "selectable": true, "linked_seat_id": "5aa6339d-..." },
    { "id": "5aa6339d-...", "label": "F2", "row": "F", "number": "2",
      "type": "COMPANION", "selectable": true, "linked_seat_id": "67cfad5d-..." }
  ]
}
```

60 seats, both companion pairs resolving to each other by `public_id`.

## Availability reacts, and does not disclose why

Blocking A1 by direct SQL, then re-fetching:

```text
inventory_version: 60 -> 61
A1.selectable:    true -> false
body contains "MAINTENANCE": False
body contains "BLOCKED":     False
```

The reason is withheld on purpose. `HELD` versus `CONFIRMED` would tell an
unauthenticated stranger whether someone is midway through checkout, and
`FR-004` asks only for selectable versus unavailable.

## Two rules shared with the write path

1. **On sale is derived**, never stored (`RC-03`) — the same
   `status = PUBLISHED AND transaction_timestamp() < starts_at` test.
2. **An expired `HELD` row reads as selectable**, matching
   `LOGICAL_ERD.md` §5.1, which treats it as reclaimable before the cleanup
   worker runs. Reporting it unavailable would tell a customer no when a
   hold request would say yes.

The map is a snapshot and never authority. A seat shown selectable may be
taken before the customer acts — that is what `H3-02` revalidation is for.
A stale map may cause rejection, never false success.
