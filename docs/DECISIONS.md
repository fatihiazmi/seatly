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

On 2026-08-10 the same Product Owner resolved `PD-01`–`PD-10`, recorded as
`ADR-015` (payment attempt lifecycle), `ADR-016` (cancellation cutoff) and
`ADR-017` (staff roles and showtime cancellation). That closes BRD `D6`–`D11`,
`D13`, the MVP portion of `D15`, and `RULE-022`–`RULE-025`.

No detailed state-transition policy remains gated. `D14` (QR payload detail and
rotation) and `D20` (named approvers per governance role) are still open, and
neither blocks an implementation slice.

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

---

## ADR-015 — Payment attempt lifecycle

**Status:** Accepted (Product Owner approval: 2026-08-10)
**Resolves:** `PD-01`–`PD-06`, BRD `D6`–`D10`, `RULE-022`–`RULE-024`

### Decision

**One clock (`PD-01`, accepted with amendment).** A payment attempt's deadline is
the hold's existing `expires_at`. No second payment timer and no `timeout_at`
column. A deadline later than `expires_at` is meaningless because `PP-08` forbids
a late success from confirming; a deadline earlier would declare timeout while
the seat is still legitimately held.

Amendment: `PAY-01` gains a guard. Checkout initiation is rejected unless

```text
transaction_timestamp() < hold.expires_at - interval '60 seconds'
```

returning `HOLD_TOO_CLOSE_TO_EXPIRY`. This is a validation rule, not a second
clock. Without it a customer who spends 4m55s choosing seats can start a payment
with five seconds of budget, producing an attempt that cannot succeed and a
reconciliation case for someone who did nothing wrong.

**Timeout is absence, not failure (`PD-02`, accepted with addition).** If no
final callback has committed by `hold.expires_at`, a `PENDING` attempt becomes
`TIMED_OUT`, the hold expires, and a reconciliation case opens.

Addition — `PAY-05b`: an attempt still in `CREATED` at hold expiry becomes
`FAILED` with reason `NEVER_SUBMITTED` and opens **no** case. `PAY-05` covered
only `PENDING`, leaving `CREATED` with no exit. The two are not equally
ambiguous: initiation was never durably sent, so no money can have moved.
Reconciliation stays a queue that means "money might have moved", which is the
only reason anyone would read it.

**Closure stops the clock, it does not seal the record (`PD-03`, accepted with
addition).** Timed-out and reconciliation attempts stay open for 24 hours, then
close as `UNRESOLVED`. Nothing in MVP scope can resolve a case by hand —
operator capability is inspection only (`FR-042`–`FR-044`) — so the window
exists for the provider, not for staff.

Addition — `PAY-11b`: `UNRESOLVED` plus a valid late success becomes
`LATE_SUCCESS_REFUND_REQUIRED` and reopens the case. `UNRESOLVED` previously had
no exit at all, which would have buried a genuinely paid-but-unbooked customer
as "we stopped waiting". No booking is created and no inventory is touched.

**Commit order is the tiebreak (`PD-04`, accepted as written).** The first valid,
authenticated, correctly correlated final outcome committed in PostgreSQL wins.
Provider timestamps and sequence claims are recorded but never authoritative.

Considered and rejected: ordering by provider sequence with a settling window
before acting. It makes external data authoritative over PostgreSQL, which
`ADR-004` refuses, and puts a delay on every success to serve a rare reordering.
When commit order and provider intent disagree, the losing outcome still lands in
the append-only inbox and opens reconciliation, so nothing is lost silently.

**Contradictions never reverse committed state (`PD-05`, accepted with a new
table).** A later contradictory callback cannot undo a booking, cancellation,
inventory release, or earlier final outcome. `PAY-13` leaves a confirmed booking
standing and the cinema absorbing the loss; `PAY-14` leaves the customer paid
with no seat and a refund owed. Both resolve toward the customer.

New table — `reconciliation_cases`. `DOMAIN_STATE_MODELS.md` §7.1 has always
required three records: payment attempt, callback event, and reconciliation
case. Only the first two existed in the schema. A status on `payments` cannot
carry the two cases that matter most, because in both the payment is and must
remain `SUCCEEDED`:

- `PAY-13` — a committed success later contradicted by a failure.
- `PD-09` — a confirmed booking killed by showtime cancellation, refund owed.

**Failure does not end the checkout (`PD-06`, amended).** A valid explicit
payment failure sets the attempt `FAILED` but leaves the hold `ACTIVE`. The
customer may start a further attempt until `expires_at`, to a maximum of three
attempts per hold; the third failure releases the hold with reason
`PAYMENT_FAILED`.

Rationale: PD-06 as drafted released the hold on first failure, which made
`payments.attempt_number` and `UNIQUE (hold_id, attempt_number)` unreachable —
`PAY-01` requires an `ACTIVE` hold, so a second attempt could never exist. It
also meant a declined card, the commonest real failure, cost the customer their
seats with minutes left on a hold they legitimately owned. Retry within the
window weakens no invariant: the seats stay owned by the same customer until the
same deadline either way, and the 60-second guard applies to each retry.

### Consequences

- `HLD-05` becomes conditional — it fires on the third failure, not the first.
- `PAY-01` admits a hold carrying prior `FAILED` attempts, and rejects a fourth
  with `PAYMENT_ATTEMPT_LIMIT_REACHED`.
- `payments.attempt_number` and its unique constraint are live.
- `reconciliation_cases` is written by `PAY-05`, `PAY-06`, `PAY-08`, `PAY-09`,
  `PAY-11b`, `PAY-13` and showtime cancellation.
- Phase 5 is unblocked.

### Revisit trigger

A real payment provider is introduced, real refunds enter scope, or measured
retry behaviour shows the cap of three is wrong.

---

## ADR-016 — Cancellation cutoff is a snapshotted policy

**Status:** Accepted (Product Owner approval: 2026-08-10)
**Resolves:** `PD-07`, BRD `D11`, `RULE-015` boundary

### Decision

Customer cancellation is eligible only when

```text
transaction_timestamp() < scheduled_start_at - cutoff
```

Equality is ineligible. The cutoff is **not** a literal. `showtimes` carries
`cancellation_cutoff_minutes NOT NULL DEFAULT 120`, snapshotted onto the booking
at confirmation as `cancellation_cutoff_minutes_snapshot`, and the guard reads
the snapshot.

### Context

The "exact timezone boundary" left open by `RULE-015` and `D11` was a
non-question: `starts_at` is `timestamptz`, so the comparison is instant
arithmetic, and `Asia/Kuala_Lumpur` has no DST. Venue-local time governs how the
deadline is *explained*, never how it is *evaluated*.

The real conflict was that `PROJECT_BRIEF.md` §5 promised a "configurable"
cutoff while `PD-07` hard-coded `interval '2 hours'` into the guard.

### Rationale

A cancellation cutoff is a promise made at the point of sale, in the same class
as the price, the seat label, and the venue name — all of which this design
already snapshots so a later edit cannot rewrite what a customer was sold.
Evaluating a live setting would silently re-term every existing booking the day
the policy changed. Two columns with a default make "configurable" true without
building a settings engine.

### Consequences

- Changing the policy affects only showtimes published afterwards.
- Cancellation eligibility is answerable from the booking row alone.
- An eligible cancellation always returns seats with at least the cutoff left to
  resell them, satisfying `PP-07`.

### Revisit trigger

Per-venue or per-movie cutoffs are required, or a regulator sets a minimum.

---

## ADR-017 — Staff roles and showtime cancellation

**Status:** Accepted (Product Owner approval: 2026-08-10)
**Resolves:** `PD-08`–`PD-10`, BRD `D13`, MVP portion of `D15`, `RULE-025`

### Decision

**Two staff roles (`PD-08`, accepted with the split named).**

| Role | Capabilities |
|---|---|
| `OPERATOR` | Movie, venue, hall and seat maintenance; showtime create, update, publish; all inspection of bookings, inventory, exceptions and audit |
| `ADMIN` | Everything `OPERATOR` has, plus the irreversible ones — `showtimes.cancel`, `bookings.cancel`, seat block and unblock |

`roles.code` already carried `CUSTOMER | OPERATOR | ADMIN`, and
`RELEASE_GATES.md` already requires the authorization matrix to be exercised
with "operator and admin identities" — a gate that is only meaningful if there
is something an operator cannot do. Collapsing to one staff role would have
deleted the privilege-escalation case, which is the first thing a security
reviewer looks for. No approval chain and no deeper hierarchy in MVP.

Cancellation reasons are constrained codes, never free text, per §3.3.

**Cancellation effects are definite (`PD-09`, amended).** In one transaction the
showtime moves `PUBLISHED → CANCELLED`, active holds are released, unsold and
released seats are blocked, later confirmation is prevented, confirmed bookings
move to `SHOWTIME_CANCELLED`, and affected QR linkage is revoked.

Amendment one — `ST-04` gains the guard `transaction_timestamp() < starts_at`,
rejecting with `SHOWTIME_ALREADY_STARTED`. Removing the stored `ENDED` state in
`RC-03` left a finished showtime sitting in `PUBLISHED` forever, so `ST-04`'s
only guard would have allowed an admin to "cancel" a screening from six weeks
ago — voiding past bookings, revoking QRs for a film people had already watched,
and raising refunds for it.

Amendment two — "opens reconciliation **where needed**" is replaced by a
countable rule: **one reconciliation case per confirmed booking**, reason
`SHOWTIME_CANCELLED_REFUND_DUE`, plus a case for every payment-ambiguous
attempt. Every confirmed booking has a `SUCCEEDED` payment and the cinema is the
party cancelling, so a refund is owed for all of them, not for a judgement call's
worth of them.

Retroactive voiding of a failed screening is a real cinema event and is
deliberately **not** modelled by overloading this command. It goes to the
post-MVP backlog as a separate command with its own authority and audit.

**Terminal, behind an impact preview (`PD-10`, accepted with a control).**
Cancellation is terminal. Reschedule, reopen and uncancel are forbidden; create a
new showtime instead. Uncancelling would have to restore released holds, blocked
inventory, revoked QRs and abandoned payment intent, all of which have moved on.

Control: the cancel command is two-step. A preview returns the counts of active
holds, confirmed bookings and reconciliation cases that the command will create;
the caller then confirms, quoting the showtime reference. The BPMN admin lane
already modelled this as `a_review`, "Authorise and review cancellation impact" —
the rules simply never required it.

Rationale: with one admin capability, no approval chain, sweeping effects, no
undo, and no notifications in scope, a single misclick destroys a sold-out
screening irreversibly and silently. The audit record only tells you afterwards.
A typed confirmation costs no state, no schema and no timer, and is the only
control available given `PD-08`.

Considered and rejected: a grace window deferring booking and inventory effects.
It buys a real undo at the cost of a second timer, an intermediate state, and a
partially-cancelled showtime to reason about in every race in §13.

### Consequences

- Phase 6 showtime-cancellation slices (`O6-03`) are unblocked.
- `O6-01`'s authorization matrix gains customer / operator / admin as three
  distinct identities.
- No email, SMS, funds movement or real refund is performed by any of this.

### Revisit trigger

A second venue or franchise introduces genuine segregation-of-duties
requirements, real refunds enter scope, or notifications are added.

## ADR-018 — Companion-seat adjacency

**Status:** Accepted (Product Owner approval: 2026-08-10). Closes `D21`.

A companion seat is adjacent to its accessible seat when both share the same
`row_label` **and** their `sort_order` values differ by exactly one. Enforced in
the `seat_companion_links` deferred constraint trigger, alongside the same-hall,
classification and both-active rules.

### Context

`LOGICAL_ERD.md` §2.2 required the trigger to enforce that "the declared layout
relationship is valid/adjacent according to the hall layout", and
`MVP_POLICY_BASELINE.md` §1 speaks of "one adjacent companion seat". Neither
defines adjacency, and `C2-01` refused to settle it in code — the four
unambiguous rules were implemented and adjacency was raised as `D21`.

Three facts shaped the choice:

- No document mentions aisles.
- `x_position` and `y_position` are nullable and nothing populates them, so
  true grid adjacency is unavailable without a schema change and a geometry
  decision that belongs with the Phase 4 seat grid.
- `sort_order` is `NOT NULL`, unique per hall, and already the canonical display
  and locking order.

`BO-08` — the exact accessibility standard to certify against — is itself still
open, so no external standard currently binds this definition.

### Decision

Same row, consecutive `sort_order`. Adjacency is symmetric: the companion may
sit on either side.

Rejected: **same row plus consecutive `seat_number`**, because a row that omits
a number — 13 is commonly skipped — would falsely reject a genuine pair.
Rejected: **`sort_order` alone**, because the last seat of one row and the first
of the next are consecutive but physically far apart.
Rejected for now: **grid adjacency on `x_position`/`y_position`**, which is the
only reading that survives an aisle, but which forces every layout to carry
geometry before the seat grid exists.
Rejected: **no database enforcement**, which leaves the invariant unowned.

### Known limitation

Two seats either side of an aisle are consecutive in `sort_order`, so the pair
would be accepted although the companion is not physically beside the accessible
seat. The schema cannot see an aisle. This is a false accept, visible to any
human reviewing the layout, and it does not affect allocation correctness —
pairing remains relational and all-or-none.

### Consequences

- `C2-03` may let an operator declare pairs, with the database rejecting every
  cross-row, non-consecutive, cross-hall, misclassified or inactive pair.
- Hall layouts must assign `sort_order` in physical seating order within a row.
  That was already true — `sort_order` is the display and locking order — but it
  now carries an accessibility guarantee as well.
- The `C2-01` limitation note in `BASELINE_RECONCILIATION.md` is superseded.

### Revisit trigger

A hall with an aisle inside a row, `x_position`/`y_position` becoming populated
and mandatory, or `BO-08` closing on a standard that prescribes adjacency.

---

## ADR-019 — Hall and seat edit policy after publication

**Status:** Accepted (Product Owner approval: 2026-08-10). Closes the hall/seat
versioning item open against `FR-038` and `FR-041`.

A hall's **sellable seat set, seat identity, ordering and classification** are
frozen while an upcoming published showtime references that hall. Purely
descriptive attributes stay editable. `halls.layout_version` increments on every
frozen-category change.

### Context

`ADR-005` states that "structural hall edits are blocked after affected showtimes
are published", and `DOMAIN_STATE_MODELS.md` §4.3 forbids "structural hall edits
that change a published showtime's materialized seat meaning". Neither defines
*structural*, *affected*, or for how long — so `FR-038` and `FR-041` both carried
"detailed rules Open" and `C2-03` was blocked.

The decisive fact is that **booking history is already protected by snapshots,
not by freezing the hall**. `LOGICAL_ERD.md` §5 has `hold_items` capture "exact
seat labels/types and line prices" and `booking_items` capture
`seat_label_snapshot`, `row_label_snapshot`, `seat_number_snapshot` and
`seat_type_snapshot`, and states that "later catalogue edits or seat deactivation
cannot alter booking history".

So the freeze is not there to protect history. It is there to protect the
**materialized inventory** created by `ADR-005`: `showtime_seats` is written once
at publication, and a later layout change would leave it describing a hall that
no longer exists.

### Decision

**Frozen** while a `PUBLISHED` showtime with `transaction_timestamp() < starts_at`
references the hall:

| Change | Why it is frozen |
|---|---|
| Adding a seat | The published showtime has no inventory row for it, so it is physically present but unsellable and invisible |
| Deleting a seat | Already refused by the restrict foreign key once inventory exists |
| `seats.is_active` | Removes a seat that already has an inventory row |
| `seats.hall_id` | Moves a seat out from under existing inventory |
| `seats.row_label` | Breaks the `ADR-018` adjacency rule for existing pairs |
| `seats.seat_number` | Part of seat identity, unique per hall |
| `seats.sort_order` | Breaks `ADR-018` adjacency and the canonical display order |
| `seats.seat_type` | Breaks companion pairing and the accessible-seat rules |
| `halls.is_active` | Would leave an upcoming published showtime in a retired hall |

**Editable at any time:** `halls.name`, `halls.code`, `seats.display_label`,
`seats.x_position`, `seats.y_position`, and all venue address fields. These are
descriptive: bookings and holds snapshot the seat label they sold, so changing it
cannot rewrite what a customer bought.

**Freeze scope.** The same derived on-sale rule used everywhere else
(`status = 'PUBLISHED' AND transaction_timestamp() < starts_at`). Once every
showtime in a hall has started or been cancelled, the hall is editable again.
Past bookings remain correct because of their snapshots.

**`halls.layout_version`** increments on any frozen-category change. By
definition that can only happen while the hall is not frozen. It is a cheap audit
fact answering "has this layout changed since?", not a version graph: previous
layouts are not retained.

**Enforcement** is a database trigger, with an application guard above it that
returns named reason codes. The trigger is authoritative because a direct SQL
write must not be able to corrupt published inventory.

### Rejected alternatives

**Hard freeze** — nothing on the hall or its seats changes after publication, not
even a typo in a display label. Simplest to state, but an operator would have to
cancel and re-create a showtime to fix a spelling mistake, and the snapshots make
that caution unnecessary.

**Copy-on-write layout versions** — a structural edit clones the hall into a new
version and published showtimes keep the old one. Operators are never blocked,
but `LOGICAL_ERD.md` §8 explicitly lists "separate seat-layout version graph"
among the premature abstractions to avoid, and it would add a version dimension
to every seat, inventory, hold-item and booking-item lookup in the system.

**Freeze forever once published** — a hall that has ever hosted a published
showtime could never be edited again, forcing operators to create a replacement
hall for any change.

### Consequences

- `C2-03` is unblocked and implements exactly this table.
- An operator correcting a real layout mistake must cancel or wait out the
  upcoming published showtimes for that hall. This is intended: the alternative
  is inventory that no longer describes the room.
- `halls.layout_version` gains a defined meaning for the first time.
- Venue deactivation with upcoming published showtimes is the same class of
  problem and is **not** covered here; `FR-041` still owns it.

### Revisit trigger

Operators report the freeze blocking legitimate corrections often enough to cost
real time, a hall needs restructuring while showtimes are on sale, or seat
geometry stops being descriptive and starts driving allocation.

---

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
