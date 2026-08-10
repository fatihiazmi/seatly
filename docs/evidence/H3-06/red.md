# H3-06 — red

```
php artisan test --testsuite=Integration --filter=AllocationInvariantsTest
  13 tests, 2 passed, 11 errors
  Target class [App\Support\Invariants\AllocationInvariants] does not exist.
```

The two that passed are the constraint cases — a dangling hold pointer and an
invalid linkage are rejected by the schema, which was already true before the
invariant class existed. They belong to the migrations, not to this slice, and
they pass here for that reason rather than vacuously.

Each planted-violation case then went red a second time by construction: the
check it targets is written after the planting is proved to produce a state the
clean run does not contain.
