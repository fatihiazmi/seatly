# H3-02 / H3-03 — green (HTTP path)

Real server, real PostgreSQL, real Fortify session. Not the test client.

| Request | HTTP | Result |
|---|---:|---|
| no idempotency key                 | 400 | IDEMPOTENCY_KEY_REQUIRED — Send an Idempotency-Key header so this r |
| hold 2 seats, key A                | 201 | hold=99b41aab seats=2 total=3700 exp=09:23:33 |
| replay key A, same body            | 201 | hold=99b41aab seats=2 total=3700 exp=09:23:33 |
| replay key A, seats reversed       | 201 | hold=99b41aab seats=2 total=3700 exp=09:23:33 |
| key A, different seats             | 409 | IDEMPOTENCY_KEY_REUSED — That idempotency key was already used fo |
| key B, seat already held           | 409 | SEAT_TAKEN — Some of those seats have just been taken |
| replay key B                       | 409 | SEAT_TAKEN — Some of those seats have just been taken |
| key C, accessible+companion        | 201 | hold=013a25dd seats=2 total=3700 exp=09:23:33 |
| key D, companion alone             | 422 | seat_ids — Seat F2 can only be held together with i |

Committed state after those nine requests, read from `psql`, not from the API:

```
holds=2  hold_items=4  held_seats=4  outbox=2  audit=2  idempotency=3
```

Two holds, four snapshot lines, four `HELD` inventory rows — they agree. Three
idempotency records for four distinct keys: `D` was released because its request
was rejected before the command ran. `php artisan seatly:invariants` reports
clean against this data.

## Suite

```
php artisan test --testsuite=Integration --filter=AcquireSeatHoldTest
  24 tests, 82 assertions

composer ci:check
  pint    passed
  phpstan passed, 0 errors
  phpunit 248 tests, 688 assertions   (was 196 / 451 at H3-01)
```

## Three defects the constraints found, not me

**`holds_expiry_is_five_minutes` rejected my own test.** The expired-hold case
moved `expires_at` backwards on its own. The CHECK is how "a hold is never
extended" is expressed in the schema, and it does not care that the movement was
in the safe direction. Test corrected to move `created_at` with it.

**One trigger function cannot serve two tables.** `seatly_check_hold_integrity()`
read `NEW.hold_id`, which does not exist on `holds` — plpgsql resolves NEW
against the real table, so it failed at runtime on whichever table lacked the
column. Split into two thin trigger wrappers over one shared function.

**`RefreshDatabase` hides deferred constraint triggers.** Each test runs inside a
transaction that is rolled back, so a `DEFERRABLE INITIALLY DEFERRED` trigger
never reaches its commit point. The backstop tests were passing without ever
running the trigger. They now issue `SET CONSTRAINTS ALL IMMEDIATE` first, and
the companion-pair fixture restores `DEFERRED` afterwards so hold creation still
works.

## One test fixture the new foreign key invalidated

`SeatMapTest` marked seats `HELD` with `hold_id => 1`, a hold that never existed.
The composite FK `(hold_id, showtime_id) → holds` added here made that
impossible, which is the FK doing its job. Those three cases now acquire a real
hold through the action.
