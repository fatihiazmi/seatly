# F1-03 green evidence

Captured 2026-08-10T05:18:47Z

| Component | Version |
|---|---|
| PHP | 8.5.3 |
| Composer | 2.9.5 |
| Laravel framework | v13.24.0 |
| PHPUnit | 12.5.33 |
| Inertia (server) | v3.3.1 |
| Node | v24.13.1 |
| npm | 11.8.0 |
| Vue | 3.5.41 |
| Vite | 8.2.1 |
| Playwright | 1.62.1 |
| PostgreSQL server | 16.14(Debian16.14-1.pgdg13+1) |
| psql client | 16.14 |

## Unit
```
{"tool":"phpunit","result":"passed","tests":3,"passed":3,"assertions":5,"duration_ms":347}
```

## Feature
```
{"tool":"phpunit","result":"passed","tests":40,"passed":40,"assertions":137,"duration_ms":2392}
```

## Integration (real PostgreSQL)
```
{"tool":"phpunit","result":"passed","tests":5,"passed":5,"assertions":8,"duration_ms":179}
```

## Browser (Playwright)
```
PASS (2) FAIL (0)

Time: 1910ms
```

## F1-02 guard — integration suite pointed at SQLite

Command: `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --testsuite=Integration`

All five cases fail with the same message rather than passing on a driver that
cannot demonstrate locking, isolation, the server clock, or exclusion constraints:

```
Seatly integration tests require PostgreSQL, but the default connection uses [sqlite].
SQLite and in-memory drivers cannot demonstrate row locking, transaction isolation,
the server clock, or exclusion constraints, so a pass here would be meaningless.
Start the database with `docker compose up -d` and check DB_CONNECTION.
```

## Note on output format

PHPUnit and Playwright output on this machine is summarised to JSON by the RTK
CLI wrapper, which intercepts every invocation path including `vendor/bin/phpunit`.
The payload carries test name, file, line, and expected-versus-actual, so nothing
is lost. Re-running without RTK installed produces the same results in the
tools' native format.
