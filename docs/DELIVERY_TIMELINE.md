# Seatly Delivery Timeline

**Version:** 0.2  
**Date:** 2026-08-10  
**Baseline:** 16-week learning-first plan  
**Change note:** v0.2 applies `RC-09` and `RC-10` from
[`BASELINE_RECONCILIATION.md`](BASELINE_RECONCILIATION.md).  
**Assumption:** Solo, part-time, approximately 10–15 hours per week

A full-time builder with established Laravel foundations may compress the plan to approximately 10–12 weeks. The sequence and exit gates must remain intact even if calendar duration changes.

## Delivery principles

1. Deliver vertical, demoable slices rather than separate backend/frontend phases.
2. Prove concurrency correctness before UI polish.
3. Treat policies and state machines as inputs to code, not assumptions discovered during coding.
4. Test transaction behaviour against real PostgreSQL.
5. A phase exits only when its evidence gate passes.
6. Production hardening and portfolio evidence are part of the project, not optional cleanup.

## Milestone overview

| Milestone | Target | BRD gate | Exit evidence |
|---|---:|---|---|
| Product rules frozen | Week 1 | G0 | Approved brief, state models, initial ERD, reconciled baseline |
| Foundation ready | Week 3 | G1 | Auth, policies, CI, logging baseline |
| Catalogue operational | Week 5 | G2 | Published showtime and materialized inventory |
| Concurrency proven | Week 7 | G3 stop-gate | Real-PostgreSQL race-test report |
| Hold journey complete | Week 9 | G3 exit | Expiry, stale-map, and accessibility evidence |
| Booking complete | Week 11 | G4 | Idempotency and payment-chaos evidence |
| Operations complete | Week 13 | G4 exit | Cancellation, audit, and authorization evidence |
| Production hardened | Week 15 | G5 | Security, load, migration, and restore evidence |
| Portfolio released | Week 16 | G6 | Deployment, documentation, runbook, and demo |

Gate letters map to `BRD.md` §15, which was restated to these weeks (`RC-10`).

---

## Sprint 0 — Week 1

### Goal

Freeze product rules and establish the engineering contract before implementation.

### Deliverables

- Approve project brief and MVP cut-line.
- Confirm login-only checkout for MVP.
- Confirm five-minute hold without extension.
- Confirm maximum seats per booking.
- Confirm cancellation cutoff and inventory-release rule.
- Confirm late-payment and showtime-cancellation policy.
- Define seat, hold, payment, booking, and showtime states.
- Produce initial ERD.
- Record architecture decisions.
- Reconcile the baseline and close contradictions before any code (`S0-01`).

Repository and Laravel initialization are **not** Sprint 0 deliverables. They
belong to Sprint 1 / backlog Phase 1, gated on the Sprint 0 review — as stated in
`START_HERE.md` §4, `CLAUDE.md`, and `PACKAGE_MANIFEST.md` (`RC-09`).

### Learning focus

- Domain terminology.
- Laravel project lifecycle.
- Migration strategy.
- Architecture Decision Records.

### Exit gate

No unresolved ambiguity remains in the critical hold, payment, confirmation, and cancellation policies.

---

## Sprint 1 — Weeks 2–3

### Goal

Build the Laravel foundation and identity boundaries.

### Deliverables

- Initialize the Git repository and the Laravel application; pin and record
  Laravel, PHP, Node, PostgreSQL, Pest/PHPUnit, and Playwright versions (`RC-09`).
- Configure PostgreSQL-only development and integration databases.
- Laravel authentication.
- Customer and admin roles.
- Policies for customer-owned and admin resources.
- Vue 3 and Inertia foundation.
- Application layout and navigation.
- Form Requests and error conventions.
- Enums and custom exception conventions.
- Structured logging with request/correlation ID.
- Database migration conventions.
- Factories, seeders, and test bootstrap.
- CI checks for style, static analysis where adopted, unit tests, and migrations.

### Learning focus

- Service container and dependency injection.
- Middleware.
- Form Requests.
- Policies and authorization.
- Inertia request lifecycle.
- Factories and seeders.

### Exit gate

Customer/admin authorization matrix passes automated negative tests.

---

## Sprint 2 — Weeks 4–5

### Goal

Deliver cinema catalogue, showtime publication, and inventory materialization.

### Deliverables

- Movies.
- Venues.
- Halls.
- Fixed seat layouts.
- Draft and published showtimes.
- One price per showtime.
- Admin CRUD required to operate the demo.
- Public movie and showtime browsing.
- On publication, create one `showtime_seat` row per active hall seat.
- Preserve seat-label and price data needed for historical snapshots.
- Reject structural hall edits that would corrupt published inventory.

### Learning focus

- Eloquent relationships.
- Query scopes.
- Database constraints.
- Action classes.
- Transactional publication.
- Avoiding fat controllers and fat models.

### Exit gate

An operator can publish a showtime and a customer can browse to its read-only seat map without direct database intervention.

---

## Sprint 3 — Weeks 6–7

### Goal

Prove the core seat-allocation invariant on real PostgreSQL.

### Deliverables

- Authoritative seat-map endpoint.
- `AcquireSeatHold` action.
- Request validation and duplicate-seat rejection.
- Stable deterministic seat ordering.
- `DB::transaction()` boundary.
- Ordered `lockForUpdate()` inventory query.
- All-or-nothing multi-seat validation.
- Scoped idempotency-key record and response replay.
- Stable conflict response for unavailable seats.
- Real-PostgreSQL integration and concurrency harness.

### Required scenarios

```text
100 concurrent requests
→ same showtime
→ same seat
→ at most one successful hold
```

```text
Request A: A1 + A2
Request B: A2 + A3
→ no partial hold for either losing request
```

### Learning focus

- Pessimistic locking.
- Isolation and deadlocks.
- Lazy query execution.
- Idempotency.
- Database constraints as final backstop.

### Exit gate

Zero duplicate active seat ownership and zero partial multi-seat holds across repeated seeded concurrency runs.

**Stop rule:** Do not proceed to payment or visual polish if this gate fails.

---

## Sprint 4 — Weeks 8–9

### Goal

Complete the temporary hold lifecycle and accessible seat-selection experience.

### Deliverables

- Accessible interactive seat grid.
- Keyboard navigation and visible focus.
- Screen-reader labels for row, number, state, and price.
- Status not communicated by colour alone.
- Server-issued expiry time and advisory countdown.
- Explicit hold release.
- Scheduled expiry command/job.
- Database-backed worker processing.
- Inline expired-seat reclamation during new holds.
- Stale map and conflict recovery.
- Short polling with jitter and freshness metadata.

### Required scenarios

- Expiry boundary before, at, and after the database timestamp.
- Delayed expiry worker.
- Two tabs displaying different seat state.
- Poll response arriving out of order.
- Keyboard-only hold journey.

### Learning focus

- Queues, workers, scheduler, retry, and failed jobs.
- Server versus client time.
- Accessible Vue components.
- Stale client recovery.

### Exit gate

Expired holds cannot be refreshed or confirmed, and expired inventory remains reclaimable even when cleanup is delayed.

---

## Sprint 5 — Weeks 10–11

### Goal

Deliver simulated asynchronous payment and exactly-once-effective booking confirmation.

### Deliverables

- Payment-attempt model and state machine.
- Simulated provider outside the booking transaction.
- HMAC-signed callback endpoint.
- Callback inbox with unique provider event ID.
- Success, failure, timeout, duplicate, and out-of-order events.
- Late-success reconciliation state.
- Atomic booking confirmation transaction.
- Unique booking by hold and payment.
- Booking and seat price snapshots.
- Transactional outbox.
- Booking reference and high-entropy QR token.

### Required scenarios

- Duplicate success callback.
- Same callback ID with different payload.
- Wrong amount or currency.
- Timeout after provider acceptance.
- Callback after hold expiry.
- Worker crash before and after commit.
- Lost response followed by same-key retry.

### Learning focus

- Idempotent webhooks.
- State-machine transitions.
- Transactional outbox.
- At-least-once processing.
- Reconciliation and unknown outcomes.

### Exit gate

Repeated callback delivery produces one booking, one inventory transition, one booking reference, and one logical outbox effect.

---

## Sprint 6 — Weeks 12–13

### Goal

Complete booking ownership, customer cancellation, operator cancellation, and auditing.

### Deliverables

- My Bookings.
- Booking detail.
- Secure QR token representation.
- Customer cancellation action.
- Cancellation cutoff enforced with PostgreSQL time.
- Atomic seat release where resale is allowed.
- Idempotent showtime cancellation.
- Block new holds immediately after showtime cancellation.
- Existing paid bookings move to documented reconciliation/refund state.
- Admin authorization.
- Append-only audit events for material commands.

### Required scenarios

- Customer A attempts to view/cancel Customer B booking.
- Cancellation races payment callback.
- Showtime cancellation races hold creation and confirmation.
- Repeated cancellation request.
- Disabled admin session and unauthorized operator command.

### Learning focus

- Policies and IDOR prevention.
- Common lock ordering.
- Auditability.
- Historical snapshots.
- Idempotent administrative commands.

### Exit gate

Cancellation and confirmation races converge on one valid, auditable terminal outcome with no unauthorized access.

---

## Sprint 7 — Weeks 14–15

### Goal

Produce production-readiness evidence.

### Deliverables

- Full real-PostgreSQL integration suite.
- Seeded concurrency and payment-chaos reports.
- Authorization and IDOR test report.
- Accessibility audit for the critical flow.
- Performance test for hot showtime and polling load.
- Worker outage and backlog-drain test.
- Migration rehearsal on production-shaped data.
- Backup and isolated restore rehearsal.
- Reconciliation command and report.
- Metrics and alerts for hold conflicts, expiry lag, payment mismatch, outbox backlog, and deadlocks.
- Operational runbook.

### Exit gate

- Zero P0 invariant violations.
- Zero open Critical/High correctness or security defects.
- Critical journey has no accessibility blocker.
- Restore completes within agreed recovery target.
- Reconciliation reports zero unexplained mismatch.

---

## Sprint 8 — Week 16

### Goal

Deploy and package Seatly as a credible Software Engineering portfolio.

### Deliverables

- Production deployment.
- Reproducible environment and migration procedure.
- Demo seed/reset command.
- README and setup guide.
- Architecture diagram and ERD.
- State-machine diagrams.
- Architecture Decision Records.
- API documentation.
- Threat model.
- Test and load evidence.
- Backup/restore report.
- Runbook.
- Demo script or walkthrough video.

### Final demonstration

1. Two customers compete for overlapping seats.
2. One complete hold wins; the other receives a deterministic conflict.
3. An abandoned hold expires and becomes reclaimable.
4. A duplicate payment callback is replayed safely.
5. Only one booking exists.
6. A late payment enters reconciliation without stealing inventory.
7. An operator cancels a showtime.
8. Audit, metrics, and reconciliation evidence are shown.

### Exit gate

The project is deployable, reproducible, explainable, and backed by execution evidence rather than unsupported production claims.

---

## Scope-change handling

Any addition below requires timeline re-estimation:

- guest checkout or account linking;
- real payment/refund integration;
- real email/SMS delivery;
- visual hall-layout editor;
- complex accessible/companion-seat pairing;
- pricing categories, promotions, or vouchers;
- multiple currencies/timezones/brands;
- offline QR scanning;
- Redis, WebSockets, broker, or service extraction.

The default response to an unplanned feature is to add it to the post-MVP backlog, not to silently absorb it into the current sprint.
