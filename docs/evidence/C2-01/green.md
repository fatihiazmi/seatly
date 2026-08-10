# C2-01 green evidence

Captured 2026-08-10T06:35:28Z · PostgreSQL 16.14(Debian16.14-1.pgdg13+1)

## Integration suite, run twice to show the result repeats
```
{"tool":"phpunit","result":"passed","tests":40,"passed":40,"assertions":54,"duration_ms":1488}
{"tool":"phpunit","result":"passed","tests":40,"passed":40,"assertions":54,"duration_ms":1562}
```

## Whole chain — composer ci:check
```

{"tool":"pint","result":"passed"}{"tool":"phpstan","result":"passed","errors":0}
{"tool":"phpunit","result":"passed","tests":83,"passed":83,"assertions":196,"duration_ms":3682}
```

## Constraints present in the database

```
                     conname                     | contype 
-------------------------------------------------+---------
 halls_layout_version_positive                   | c
 halls_pkey                                      | p
 halls_public_id_unique                          | u
 halls_venue_id_code_unique                      | u
 halls_venue_id_foreign                          | f
 movies_pkey                                     | p
 movies_public_id_unique                         | u
 movies_runtime_minutes_positive                 | c
 seat_companion_links_accessible_seat_id_foreign | f
 seat_companion_links_companion_seat_id_foreign  | f
 seat_companion_links_companion_seat_id_unique   | u
 seat_companion_links_distinct_seats             | c
 seat_companion_links_pkey                       | p
 seats_hall_id_foreign                           | f
 seats_hall_id_row_label_seat_number_unique      | u
 seats_hall_id_sort_order_unique                 | u
 seats_pkey                                      | p
 seats_public_id_unique                          | u
 seats_seat_type_valid                           | c
 seats_sort_order_not_negative                   | c
(20 rows)

```

## The companion trigger is genuinely deferrable

```
                tgname                 | tgdeferrable | tginitdeferred 
---------------------------------------+--------------+----------------
 seat_companion_links_validate_trigger | t            | t
(1 row)

```

Deferred means the trigger fires at COMMIT. RefreshDatabase never commits, so
every companion test calls SET CONSTRAINTS ALL IMMEDIATE to force evaluation.
Without that call those assertions would pass without the trigger ever running.
