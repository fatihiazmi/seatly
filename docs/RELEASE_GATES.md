# Seatly Production Release Gates

**Version:** 0.1  
**Default release posture:** No-Go until all P0 gates have reproducible evidence

Seatly's quality target is not a test count or coverage percentage. Production promotion depends on proving invariants against production-like PostgreSQL and runtime behavior.

## P0 invariants

1. Zero confirmed double-booking.
2. A booking is confirmed only after authoritative PostgreSQL commit.
3. Multi-seat acquisition is all-or-nothing.
4. Duplicate commands and callbacks produce one logical effect.
5. Expiry, confirmation, and cancellation races converge deterministically.
6. A stale seat map may cause rejection but never false confirmation.
7. Paid-but-unbooked outcomes remain visible and reconcilable.
8. Customer and admin authorization fails closed.
9. Backup, migration, and restore preserve inventory and booking integrity.
10. The critical booking journey is keyboard and screen-reader usable.

## Immediate release vetoes

Any reproducible occurrence below results in No-Go:

- Confirmed double-booking.
- Partial confirmation of a multi-seat request.
- API reports success before commit.
- An ambiguous retry creates another hold, payment, booking, or cancellation.
- Duplicate callback causes another state transition or side effect.
- Paid booking becomes free inventory without reconciliation/refund state.
- A reconciliation case is closed, dropped, or made unreachable while money is still owed.
- Customer accesses another customer's hold, payment, booking, or PII.
- Unauthorized admin cancellation or missing audit record.
- Migration silently loses or remaps booking/inventory/payment data.
- Restore or reconciliation fails.
- Keyboard or screen-reader user cannot complete seat selection and checkout.
- Flaky correctness/security test that passes only after rerun.

## Required test layers

| Layer | Required coverage |
|---|---|
| Unit/property | Hold, payment, booking, cancellation state machines; money and expiry boundaries; replay invariance |
| Real PostgreSQL integration | Locks, constraints, transaction rollback, outbox atomicity, expiry/confirmation races |
| API/E2E | Browse, hold, pay, callback, confirm, view, cancel, and admin cancellation |
| Concurrency | Same seat, overlapping groups, duplicate command, expiry/payment, cancellation/confirmation |
| Security/privacy | IDOR, role escalation, CSRF where applicable, enumeration, PII/log/outbox leakage |
| Accessibility | Keyboard journey, screen-reader names/states, focus, timeout warning, non-colour status |
| Recovery/migration | Worker crashes, outbox replay, migration interruption, backup restore, reconciliation |
| Performance | Hot showtime, polling fan-out, callback burst, expiry backlog, database pool/locks |

## Highest-value adversarial scenarios

### Same-seat contention

- Coordinate at least 100 concurrent attempts for one showtime-seat.
- At most one active/confirmed winner.
- Losers receive a deterministic conflict, not a server error.
- Post-run invariant SQL reports zero duplicate owners.

### Overlapping groups

```text
Request A: A1 + A2
Request B: A2 + A3
Request C: A1 + A3
```

Each request is all-or-nothing. No losing request receives a partial hold.

### Hold expiry race

Test payment/confirmation immediately before, at, and after expiry using PostgreSQL as the authoritative clock. Delay or duplicate the expiry worker. The final outcome must never leave both free inventory and a valid confirmed booking.

### Payment chaos

Inject:

- duplicate success callbacks;
- success followed by failure;
- callback before client response;
- provider timeout after acceptance;
- invalid signature;
- changed amount/currency;
- late success after hold expiry;
- late success after the 24-hour reconciliation window has closed;
- a fourth checkout attempt on one hold;
- checkout started inside the 60-second minimum window;
- worker crash before/after local commit.

One callback identity produces one logical effect. Illegal transitions fail without corrupting booking or inventory.

### Showtime cancellation race

Race cancellation against new hold, payment initiation, callback, confirmation, expiry, and a stale client. No new confirmed booking may pass after cancellation obtains the showtime lock and commits.

Also assert: an operator identity is refused the command; a showtime that has already started is refused with `SHOWTIME_ALREADY_STARTED`; an unconfirmed impact preview is refused; and the committed transaction opens exactly one `SHOWTIME_CANCELLED_REFUND_DUE` case per confirmed booking, no more and no fewer.

### Authorization and privacy

Using Customer A, Customer B, unauthenticated, operator, and admin identities, replace every hold, payment, booking, showtime, and user identifier in each route. Opaque IDs are not treated as authorization. No response, timing, log, trace, QR, or outbox payload may leak sensitive ownership data.

### Worker and outbox failure

Crash the worker:

1. before transaction commit;
2. after business commit but before outbox processing;
3. after external/simulated side effect but before acknowledgement;
4. while processing poison work;
5. after a prolonged outage.

Backlog and dead work remain visible. Replay is idempotent and audited.

### Restore and reconciliation

Restore a backup into an isolated environment. Validate:

- no duplicate confirmed showtime-seat;
- every confirmed inventory row has a valid booking item;
- every held row has a valid unexpired hold or is recoverable;
- booking/payment totals agree;
- pending/delivered outbox state is understood;
- external-payment mismatch has a documented reconciliation path.

## Required evidence pack

- Build identifier and immutable artifact reference.
- Schema/migration checksum.
- Real-PostgreSQL integration report.
- Seeded concurrency report.
- Post-run invariant SQL output.
- Payment/outbox chaos report.
- Security and dependency scan.
- Manual accessibility evidence.
- Load report with latency, error rate, locks, pool use, and backlog age.
- Migration rehearsal report.
- Backup/restore timing and reconciliation output.
- Monitoring links or screenshots for:
  - hold conflict rate;
  - expiry lag;
  - duplicate callback count;
  - late-payment/reconciliation count;
  - outbox oldest pending age;
  - transaction deadlocks/retries;
  - inventory consistency violations.

## Release decision

Promotion requires:

- 100% P0 scenario pass on the release candidate;
- zero unexplained invariant mismatch;
- zero open Critical/High correctness or security defect;
- no critical accessibility blocker;
- successful migration and restore rehearsal;
- agreed performance and recovery targets met;
- operational owner accepts documented residual risks.
