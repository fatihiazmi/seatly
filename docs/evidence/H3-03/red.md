# H3-03 — red

```
php artisan test --testsuite=Integration --filter=HoldIdempotencyTest
  15 tests, 5 passed, 6 failed, 4 errors
```

| Cause | Cases |
|---|---:|
| `relation "idempotency_keys" does not exist` | 4 |
| key ignored, so a replay ran the command again (`201` where `409`/replay expected) | 4 |
| key ignored, so a missing key was accepted (`201` where `400` expected) | 2 |

The five that passed are the ones asserting that *different* keys or *different*
principals produce separate holds — true today only because the key is not read
at all. They are re-proved once the middleware exists.
