# C2-03 green evidence

Captured 2026-08-10T07:46:02Z · PostgreSQL 16.14

## Integration suite, run twice
```
{"tool":"phpunit","result":"passed","tests":105,"passed":105,"assertions":162,"duration_ms":4210}
{"tool":"phpunit","result":"passed","tests":105,"passed":105,"assertions":162,"duration_ms":4115}
```

## Whole chain
```

{"tool":"pint","result":"passed"}{"tool":"phpstan","result":"passed","errors":0}
{"tool":"phpunit","result":"passed","tests":148,"passed":148,"assertions":304,"duration_ms":7697}
```

## Guards installed in the database
```
      table_name      |             trigger_name              
----------------------+---------------------------------------
 halls                | halls_structural_guard_trigger
 seat_companion_links | seat_companion_links_validate_trigger
 seats                | seats_bump_layout_version_trigger
 seats                | seats_structural_guard_trigger
(4 rows)

```

## Freeze state of the seeded halls

HALL-1 carries two upcoming published showtimes; HALL-2 carries none.
```
  code  | layout_version | seats | frozen 
--------+----------------+-------+--------
 HALL-1 |             61 |    60 | t
 HALL-2 |             61 |    60 | f
(2 rows)

```

layout_version 61 is 1 plus the 60 seats laid out. Building the initial
layout counts as 60 structural changes, because there is no notion of a
layout being "finished" to start counting from. The value is monotonic and
answers "has this changed since?"; its magnitude carries no meaning.

## The freeze refuses a direct SQL write, not just an Eloquent one
```
ERROR:  hall 1: seat 1 identity, ordering, classification and availability are frozen while an upcoming published showtime references this hall
CONTEXT:  PL/pgSQL function seats_structural_guard() line 38 at RAISE
```

The same statement against HALL-2, which is not frozen, succeeds:
```
UPDATE 1
  code  | layout_version 
--------+----------------
 HALL-2 |             62
(1 row)

```
