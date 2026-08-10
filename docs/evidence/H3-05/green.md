# H3-05 — overlapping multi-seat groups

Run by the same command as `H3-04`; the report and analysis live in
[`../H3-04/green.md`](../H3-04/green.md).

```bash
php artisan seatly:concurrency-gate --scenario=overlapping --attempts=100 --runs=3
```

100 attempts spread across three overlapping pairs, three seeded runs:

```text
A: A1 + A2      B: A2 + A3      C: A1 + A3
```

Any two groups share a seat, so at most one attempt can win. All three runs:
1 held, 99 `SEAT_TAKEN`, 0 errors, **2 seats `HELD`** — the winner's whole set and
nothing else. A losing request that retained one of its two seats would leave a
different count and fail the run.

Single-threaded all-or-none is proved separately in
`Tests\Integration\Holds\AcquireSeatHoldTest::test_a_refused_multi_seat_request_holds_nothing`,
which asserts the untouched seats keep `version = 1` — a rollback, not a
compensating write.
