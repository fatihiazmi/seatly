# Seatly Architecture and Product Decisions

**Version:** 0.2  
**Date:** 2026-08-10

This document records accepted decisions, proposed defaults awaiting confirmation, rejected alternatives, consequences, and revisit triggers.

v0.2 adds `ADR-014` and applies `RC-11` from
[`BASELINE_RECONCILIATION.md`](BASELINE_RECONCILIATION.md).

## Decision status legend

- **Accepted:** Current project direction.
- **Proposed:** Recommended default, pending explicit product approval.
- **Deferred:** Intentionally excluded from MVP.
- **Rejected:** Considered and not suitable for the current scope.

---

## ADR-001 — Cinema-specific product

**Status:** Accepted

Seatly is a cinema booking application, not a generic reservation engine.

### Consequences

- Domain language can be explicit: movie, venue, hall, showtime, seat, hold, booking.
- No generic resource/rule/workflow abstractions are required.
- Future generalization requires evidence from a second real domain.

### Revisit trigger

A validated second reservation domain requires the same core behavior.

---

## ADR-002 — Laravel mastery stack

**Status:** Accepted

Use Laravel as the backend framework, Vue 3 with Inertia for the interactive web UI, and PostgreSQL as the database.

### Rationale

The project is a deliberate Laravel mastery exercise. Deep understanding and a completed evidence-backed system are more valuable than shallow implementation in a stack selected only for market signalling.

### Consequences

- Eloquent is used for ordinary persistence and relationships.
- Query Builder or parameterized SQL is permitted and expected for concurrency-critical paths.
- Core workflows live in explicit Actions, not controllers or model observers.
- Production transaction semantics must be tested against real PostgreSQL.

---

## ADR-003 — Modular monolith

**Status:** Accepted

Use one repository and one application/domain model, with separate web, worker, and scheduler processes.

### Rejected alternatives

- Microservices.
- Kubernetes.
- Per-feature deployable services.

### Revisit trigger

Measured scaling, independent ownership, release cadence, or failure-isolation needs justify extraction.

---

## ADR-004 — PostgreSQL is the seat authority

**Status:** Accepted

PostgreSQL transactions, locks, and constraints decide seat ownership.

Browser state, cache, in-memory mutexes, application checks, and workers are not authoritative.

### Required protocol

- Sort unique requested seat IDs.
- Lock showtime, then inventory rows in deterministic seat order.
- Revalidate all rows after locks are acquired.
- Acquire all seats or none.
- Commit hold and outbox state atomically.

### Rejected alternatives

- In-memory application lock.
- Redis lock for MVP.
- Optimistic UI availability as final authority.
- Separate transaction per selected seat.

---

## ADR-005 — Materialized showtime-seat inventory

**Status:** Accepted

Publishing a showtime creates one inventory row for every active physical seat in the hall.

### Rationale

This makes locking, availability, expiry, blocking, auditing, and operator inspection explicit and operationally simple.

### Consequences

- More rows are stored, but expected cinema-scale volume is manageable.
- Structural hall edits are blocked after affected showtimes are published.
- Booking and price snapshots preserve historical truth.

---

## ADR-006 — Database queue and transactional outbox

**Status:** Accepted

Use the Laravel database queue initially and store required domain/outbox records in the same PostgreSQL transaction as booking state changes.

### Rejected alternatives

- Redis/Horizon as an MVP requirement.
- RabbitMQ or Kafka.
- Dispatching external side effects before transaction commit.

### Revisit trigger

Measured queue contention, latency, throughput, or isolation requirements exceed the PostgreSQL-backed design.

---

## ADR-007 — Short polling instead of WebSockets

**Status:** Accepted

Use versioned short polling, approximately every 5 seconds with ±20% jitter — an effective 4–6 second interval — for seat-map freshness.

The interval is the one the approved load model in `MVP_POLICY_BASELINE.md` §3 is
computed from; its ~60 RPS polling figure assumes 5 seconds. Earlier drafts said
"3–5 seconds", which would understate polling load by roughly 40% (`RC-11`).

### Rationale

Polling is operationally simpler. The write transaction revalidates availability, so WebSockets do not improve final correctness.

### Revisit trigger

Measured polling fan-out or a validated real-time UX requirement justifies another transport.

---

## ADR-008 — Simulated asynchronous payment

**Status:** Accepted

Use a simulated payment provider that behaves like an external asynchronous dependency.

It must generate success, failure, timeout, duplicate, out-of-order, wrong-amount, and late-success callbacks.

### Required controls

- HMAC callback authentication.
- Unique provider event ID.
- Amount/currency/booking binding validation.
- Idempotent state transitions.
- Reconciliation for unknown or late outcomes.

### Deferred

Real payment certification, charging, settlement, and refunds.

---

## ADR-009 — Approved MVP checkout policies

**Status:** Accepted (Product Owner approval: 2026-08-10)

- Public browsing; authentication from the hold request onward; guest checkout deferred (see `ADR-014`).
- Maximum 8 seats.
- Five-minute hold.
- No hold extension.
- One showtime price.
- Cancellation allowed until two hours before showtime.
- Eligible cancellation reopens seats.
- Late payment success never steals a seat; it enters reconciliation/refund-required state.
- QR is an informational high-entropy token; scanner/check-in deferred.

These policies form the frozen MVP product baseline. Changes require an explicit decision-record update.

---

## ADR-010 — Accessibility is a release gate

**Status:** Accepted

The critical journey must be keyboard-operable and screen-reader understandable. Seat state must not rely on colour alone. Countdown warnings must be perceivable and operable.

A critical accessibility blocker is a production release veto.

---

## ADR-011 — Evidence-based production claims

**Status:** Accepted

Seatly cannot be described as production-ready based only on code completion, test count, or coverage percentage.

Required evidence includes:

- real-PostgreSQL concurrency tests;
- payment/callback chaos tests;
- authorization/IDOR tests;
- accessibility evidence;
- load results;
- migration rehearsal;
- backup restore;
- reconciliation report;
- operational runbook.

---

## Remaining product and operational decisions

The Product Owner approved public browsing with authentication from the hold onward (`ADR-014`), maximum 8 seats, a five-minute non-extendable hold, one showtime price, cancellation up to two hours before showtime with eligible seat resale, late-payment reconciliation, and display-only QR on 2026-08-10.

The same approval adopts the council-recommended accessible/companion-seat rules, `en-MY`, `Asia/Kuala_Lumpur`, `MYR`, performance/load targets, 15-minute RPO, four-hour service RTO, backup schedule, and retention/deletion rules recorded in [MVP Policy and Operational Baseline](MVP_POLICY_BASELINE.md).

The following detailed state-transition policies remain gated before their implementation slices:

1. Payment timeout, contradictory-outcome precedence, and explicit-failure hold-release behavior.
2. Showtime-cancellation remedies and administrative authority.

---

## ADR-012 — Accessible and companion-seat baseline

**Status:** Accepted (Product Owner approval: 2026-08-10)

Use self-declaration without medical evidence. An accessible seat may be booked alone. A companion seat may be booked only with its uniquely linked accessible seat in the same booking. Pair acquisition is server-enforced and atomic. Store only acknowledgement, timestamp, and policy version.

Full rules and release gates are recorded in [MVP Policy and Operational Baseline](MVP_POLICY_BASELINE.md).

---

## ADR-013 — Locale, performance, recovery, and retention baseline

**Status:** Accepted (Product Owner approval: 2026-08-10)

Adopt `en-MY`, venue timezone `Asia/Kuala_Lumpur`, UTC persistence, `MYR`, and integer sen. Design for 300 active customers, 70 RPS normal mixed peak, 100 RPS burst, and the documented p95/worker targets. Use a 15-minute PostgreSQL RPO, four-hour service RTO, encrypted base backups with WAL archiving, a 90-day maximum backup age, and the documented minimised retention schedule.

These are portfolio/demo engineering defaults, not a legal-compliance claim or contractual SLA. Revisit before commercial production or when the documented triggers occur.

---

## ADR-014 — Public browsing, authentication at the hold

**Status:** Accepted (Product Owner approval: 2026-08-10)

Browsing is public. Movies, venues, dates, showtimes, and the read-only seat map
are reachable without an account. Authentication is required at the moment a hold
is requested, and for every transactional operation thereafter — hold, checkout,
payment initiation, booking retrieval, and cancellation.

### Context

The baseline contradicted itself. `RULE-010` read "Customer must log in before
browsing" and was marked Accepted, while `BRD.md` §4.1 called the same question
Open, `D1` was unresolved, and `FR-001` required authentication only before the
hold, payment, booking, and cancellation operations. Two Accepted statements
could not both stand (`RC-01`).

### Rationale

- `FR-001` already drew the line at transactional operations; this decision
  matches it rather than contradicting it.
- `PP-01` defers guest **checkout**, not guest **browsing**. A seat cannot be
  held without an owner, so no guest path is introduced.
- A reviewer can reach the seat map without registering, which matters for a
  portfolio project.

### Consequences

- Catalogue and seat-map read routes are unauthenticated; every write route and
  every ownership-scoped read route is authenticated and fails closed.
- The seat-map endpoint (`C2-04`) exposes seat identity and availability only —
  no holder identity, no booking reference, no personal data.
- Anonymous polling is in scope for the load model; `MVP_POLICY_BASELINE.md` §3
  already counts availability polling as 70% of the mixed profile.
- The login step in the customer journey moves from before browsing to the hold
  request; the BPMN customer lane is sequenced accordingly.
- Abuse controls for anonymous browse/poll traffic are rate limiting only. No
  new decision is implied.

### Rejected alternatives

- Whole application behind authentication. Smallest attack surface, but a
  reviewer must register to see anything, and it contradicts `FR-001`.
- Guest checkout. Out of scope under `PP-01`.

### Revisit trigger

Anonymous polling produces measured abuse or cost, or a future decision
introduces personalised catalogue content.

## Rejected MVP technologies/features

- Spring Boot, ASP.NET Core, and other backend stacks for this project direction.
- Generic reservation-engine abstractions.
- Real payments and refunds.
- Guest checkout/account linking.
- Notifications.
- Loyalty, vouchers, food ordering, and dynamic pricing.
- Redis, Horizon, WebSockets, Kafka, microservices, and Kubernetes.
- Drag-and-drop hall designer.
- Offline QR validation.
