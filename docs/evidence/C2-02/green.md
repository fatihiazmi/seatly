# C2-02 green evidence

Captured 2026-08-10T06:59:16Z · PostgreSQL 16.14

## Integration suite, run twice
```
{"tool":"phpunit","result":"passed","tests":80,"passed":80,"assertions":132,"duration_ms":3356}
{"tool":"phpunit","result":"passed","tests":80,"passed":80,"assertions":132,"duration_ms":4229}
```

## Whole chain — composer ci:check
```

{"tool":"pint","result":"passed"}{"tool":"phpstan","result":"passed","errors":0}
{"tool":"phpunit","result":"passed","tests":123,"passed":123,"assertions":274,"duration_ms":6239}
```

## Showtime constraints in the database
```
                conname                 | contype 
----------------------------------------+---------
 showtimes_currency_iso_format          | c
 showtimes_cutoff_not_negative          | c
 showtimes_ends_after_start             | c
 showtimes_price_not_negative           | c
 showtimes_status_timestamps_consistent | c
 showtimes_status_valid                 | c
 showtimes_version_positive             | c
 showtimes_hall_id_foreign              | f
 showtimes_movie_id_foreign             | f
 showtimes_pkey                         | p
 showtimes_public_id_unique             | u
 showtimes_no_overlap_per_hall          | x
 showtime_seats_linkage_matches_status  | c
 showtime_seats_price_not_negative      | c
 showtime_seats_status_valid            | c
 showtime_seats_version_positive        | c
 showtime_seats_seat_id_foreign         | f
 showtime_seats_showtime_id_foreign     | f
 showtime_seats_pkey                    | p
(19 rows)

```

## Seeded fixture — publish-time materialization rehearsed (sequencing step 16)
```
 id |       title       |  status   |       starts_at        | price_minor | inventory_rows 
----+-------------------+-----------+------------------------+-------------+----------------
  1 | Sinar Terakhir    | PUBLISHED | 2026-08-11 13:00:00+00 |        1850 |             60
  2 | The Quiet Harbour | PUBLISHED | 2026-08-11 15:48:00+00 |        1850 |             60
  3 | Kolam Dalam       | DRAFT     | 2026-08-11 18:14:00+00 |        1850 |              0
(3 rows)

```

Each published showtime carries exactly 60 rows, one per active seat in a
6x10 hall. The draft carries none — inventory exists only from publication.

## Coverage invariant, expressed as SQL rather than a count

Asserted per publication in `test_inventory_covers_every_active_seat_exactly_once`:
no active seat without an inventory row, and no inventory row for a seat outside
the active hall layout. Both must return zero.
```
 seats_missing_inventory | inventory_outside_layout 
-------------------------+--------------------------
                       0 |                        0
(1 row)

```
