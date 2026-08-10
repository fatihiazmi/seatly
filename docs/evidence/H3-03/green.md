# H3-03 — green

```
php artisan test --testsuite=Integration --filter=HoldIdempotencyTest
  15 tests, 44 assertions
```

The HTTP evidence lives in `docs/evidence/H3-02/green.md`, since the same nine
curl requests exercise both slices. The rows that matter here:

| Request | HTTP | Result |
|---|---:|---|
| no idempotency key | 400 | `IDEMPOTENCY_KEY_REQUIRED` |
| hold 2 seats, key A | 201 | `hold=99b41aab seats=2 total=3700` |
| replay key A, same body | 201 | **same hold, same expiry** |
| replay key A, seats reversed | 201 | **same hold** — order is not identity |
| key A, different seats | 409 | `IDEMPOTENCY_KEY_REUSED` |
| key B, seat already held | 409 | `SEAT_TAKEN` |
| replay key B | 409 | **same refusal replayed** |

## Decisions taken here

**The key is required.** Nothing in the requirements says so explicitly, but P0
invariant 4 — duplicate commands have one logical effect — is stated
unconditionally in `CLAUDE.md`, and a guarantee a client can opt out of by
omitting a header is not a guarantee. A missing key is `400`, not a silent
downgrade to unprotected.

**A refusal is an outcome and is replayed.** Retrying a lost `409` must not
return `201` because the seat happened to free up in between; that would be a
second logical request wearing the first one's key.

**A validation failure releases the key.** `400`, `404` and `422` mean the
command never ran, so there is no outcome to protect. Burning the key would
force a customer who mistyped a seat to invent a new one before correcting
themselves. Only `201` and `409` settle a record.

**Order is not identity.** The fingerprint canonicalises the body — lists sorted,
object keys ordered — so a retry of the same seat selection is not rejected as
key reuse because the array arrived differently. This assumes no list in a
Seatly command carries meaning in its order, which holds for every command it
accepts: a seat selection is a set.

## Two things worth knowing

**A replay is semantically identical, not byte-identical.** `response_snapshot`
is `jsonb`, and jsonb does not preserve key order. The tests compare
key-order-independently and say so, rather than storing `json` to make a
stricter assertion pass.

**A killed process can strand a key.** The claim is committed before the command
runs, which is what lets a concurrent duplicate see it. A process killed between
claim and settle leaves the record `IN_PROGRESS` until the 30-day retention
sweep. Recorded, not hidden — the Phase 4 expiry worker is the natural owner of
reclaiming stale claims.
