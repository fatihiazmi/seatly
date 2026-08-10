# D21 / ADR-018 — companion-seat adjacency

Captured 2026-08-10T07:10:36Z

## Red — the three adjacency cases before the trigger enforced anything

Command: `php artisan test --testsuite=Integration --filter=SeatCompanionLink`

```text
tests: 15   passed: 12   failed: 3

test_a_pair_must_sit_in_the_same_row
  Failed asserting that exception of type "Illuminate\Database\QueryException" is thrown.
test_a_pair_must_be_consecutive_in_sort_order
  Failed asserting that exception of type "Illuminate\Database\QueryException" is thrown.
test_consecutive_sort_order_across_a_row_boundary_is_not_adjacency
  Failed asserting that exception of type "Illuminate\Database\QueryException" is thrown.
```

The two positive cases added at the same time — companion on either side, and
a pair spanning a skipped seat number — passed before the change and after it.
They exist to pin down what the rule must NOT reject.

## Green

```
{"tool":"phpunit","result":"passed","tests":15,"passed":15,"assertions":17,"duration_ms":979}
```

Whole chain:
```

{"tool":"pint","result":"passed"}{"tool":"phpstan","result":"passed","errors":0}
{"tool":"phpunit","result":"passed","tests":128,"passed":128,"assertions":279,"duration_ms":6412}
```

## Migration reverses cleanly

```
 2026_08_10_140000_enforce_companion_seat_adjacency .. 11.63ms DONE
 2026_08_10_140000_enforce_companion_seat_adjacency .. 9.27ms DONE
```

## The seeded fixture satisfies the rule

```
  hall  | accessible | a_row | a_sort | companion | c_row | c_sort | distance 
--------+------------+-------+--------+-----------+-------+--------+----------
 HALL-1 | F1         | F     |     50 | F2        | F     |     51 |        1
 HALL-1 | F9         | F     |     58 | F10       | F     |     59 |        1
 HALL-2 | F1         | F     |     50 | F2        | F     |     51 |        1
 HALL-2 | F9         | F     |     58 | F10       | F     |     59 |        1
(4 rows)

```

## Known limitation

Two seats either side of an aisle are consecutive in `sort_order`, so such a
pair is accepted. No column records aisles: `x_position` and `y_position` are
nullable and unpopulated. This is a false accept, visible to any human
reviewing a layout, and it does not affect allocation correctness — pairing
stays relational and all-or-none. See `ADR-018` for the revisit trigger.
