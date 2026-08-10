# H3-07 — gate evidence

Everything `H3-07` asks to capture, and where it is.

| Required | Where |
|---|---|
| build identifier | `environment.build` in `../H3-04/concurrency-gate.json` |
| schema checksum | `environment.schema_checksum` — md5 over `information_schema.columns` plus every `pg_constraint` definition |
| PostgreSQL version | `environment.postgres_version` |
| seed and request distribution | `parameters.seed`; per-run `seed`, `scenario`, `attempts` |
| response classifications | per-run `outcomes` — held / conflict / error, with reason counts |
| latency | per-run `latency_ms` — min, p95, max |
| deadlock / retry information | per-run `deadlocks_detected`, read from `pg_stat_database` before and after each burst |
| invariant SQL and output | per-run `invariants`, name by name, with samples |
| repeated-run results | six runs — three per scenario — each judged independently |

The machine-readable report is
[`../H3-04/concurrency-gate.json`](../H3-04/concurrency-gate.json). The written
analysis, including why the numbers demonstrate contention rather than a queue
and the open p95 finding, is in [`../H3-04/green.md`](../H3-04/green.md).

## Reproducing

```bash
docker compose up -d
php artisan migrate:fresh --force
php artisan seatly:concurrency-gate --attempts=100 --runs=3 --seed=20260810 \
    --output=docs/evidence/H3-04
```

The command exits non-zero on any failed expectation or invariant violation, so
it is usable as a gate rather than as a report someone has to read. It refuses to
run at all without `ext-pcntl` or against a non-PostgreSQL connection: parallel
attempts cannot be faked in one process, and nothing but PostgreSQL demonstrates
the locking being measured.
