# H3-06 — post-run invariant SQL

```bash
php artisan seatly:invariants          # human table, exit 1 on any violation
php artisan seatly:invariants --json   # machine-readable
```

```
php artisan test --testsuite=Integration --filter=AllocationInvariantsTest
  13 tests, 39 assertions
```

Ten checks plus one declared deferred:

| Check | Covers |
|---|---|
| `duplicate_active_seat_ownership` | Two live holds claiming one seat — the P0 invariant |
| `partial_hold_ownership` | A live hold with a snapshot line it does not own |
| `hold_coverage_mismatch` | A live hold owning a different number of seats than it snapshotted |
| `inventory_hold_pointer_dangling` | Inventory pointing at a hold that does not exist |
| `hold_expiry_mismatch` | Inventory expiry disagreeing with its hold |
| `hold_totals_disagree` | `seat_count` / `total_minor` not matching the items |
| `orphan_companion_hold` | A held companion seat without its accessible seat |
| `undeclared_accessible_hold` | A held accessible seat without the declaration |
| `inventory_linkage_invalid` | Owner columns not matching the status |
| `hold_expiry_not_five_minutes` | A hold whose lifetime is not the approved five minutes |
| `duplicate_confirmed_seat_ownership` | **DEFERRED** — needs `bookings`, Phase 5 |

## Every check has been red

A check that has never fired proves nothing, so each is tested twice: clean data
returns nothing, and a planted violation is found. The planting is done by hand
in SQL, because the action cannot produce these states — which is the point.

Two are unprovable by planting because a constraint makes the bad state
unreachable: a dangling hold pointer is refused by the composite foreign key, and
an invalid linkage by the state `CHECK`. Those are tested by asserting the
constraint rejects the write, and kept in the check list as defence in depth for
a restored or hand-repaired database, where neither constraint has been applied
to the data already in it.

## The deferred check is named, not omitted

`H3-06` requires a duplicate-confirmed-ownership check. `bookings` does not exist
until Phase 5. The check reports itself as `DEFERRED` in every run rather than
silently not existing, so the gate report can never imply a coverage it does not
have — and `AllocationInvariantsTest::test_deferred_checks_are_named_rather_than_omitted`
fails if someone quietly drops it.
