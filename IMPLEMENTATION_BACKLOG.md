# Seatly Implementation Backlog

This backlog converts the approved documentation into an evidence-gated
implementation sequence. Calendar progress does not override a failed gate.

## Status vocabulary

- `READY` — all required decisions are Accepted.
- `BLOCKED` — an Open/Proposed decision or failed prerequisite prevents work.
- `IN PROGRESS` — failing test has been demonstrated.
- `DONE` — tests and required evidence pass.
- `DEFERRED` — explicitly outside the current MVP.

Every item must record:

```text
Status:
Requirement IDs:
Gate/P0 IDs:
Red evidence:
Green evidence:
Implementation notes:
Open decisions:
```

No item becomes `DONE` solely because code exists.

---

## Phase 0 — Baseline and execution contract

**Goal:** Make implementation inputs explicit without writing product code.  
**Gate:** G0 and the Sprint 0 policy/architecture checkpoint.

### S0-01 — Baseline reconciliation

- Build a table of Accepted, Proposed, Open, Deferred, and apparently
  contradictory statements across the BRD, decisions, policy baseline, timeline,
  and release gates.
- Treat later explicit approvals as resolutions only after the governing
  documents are updated or the resolution is clearly cited.
- Do not infer missing payment or cancellation transition policy.

**Trace:** BRD §17–19; PP-01–PP-13; ADR-009/012/013; G0.

```text
Status:              DONE (2026-08-10)
Requirement IDs:     BRD §17.3, §18–19; PP-01–PP-13; RULE-010; FR-001/002;
                     ADR-007/009/012/013/014
Gate/P0 IDs:         G0
Red evidence:        n/a — documentation item, no test
Green evidence:      docs/BASELINE_RECONCILIATION.md, RC-01–RC-13;
                     docs/diagrams/*.drawio pass dgen.py verify with no findings
Implementation notes: 13 findings. One was blocking — the CONFIRMED inventory
                     CHECK required hold_id IS NOT NULL while the confirmation
                     transaction clears it, so P5-03 would have failed its own
                     constraint at commit. Enum drift fixed in payments (+2),
                     bookings (+1), showtimes (-1 ENDED), callback dispositions
                     (7 -> 17). booking_cancellations replaced by a unified
                     cancellations table. Browse-authentication contradiction
                     resolved by Product Owner decision, not by choosing one
                     side silently.
Open decisions:      at the time of this item, D6–D15, D20, PD-01–PD-10 and
                     RULE-022–025. All but D14, D20 and hall/seat versioning were
                     closed later the same day by ADR-015/016/017.
```

### S0-02 — Scope and non-goal freeze

- Restate the MVP cut-line.
- Create a post-MVP list for unsolicited features.
- Require a numbered change request for scope, architecture, policy, date,
  capacity, or release-threshold changes.

**Trace:** BRD §5 and §17.3; Project Brief §8; Delivery Timeline scope-change
handling; G0.

### S0-03 — Domain glossary and state models

Document legal states, commands, events, rejected transitions, and
reconciliation outcomes for:

- showtime;
- showtime-seat inventory;
- hold;
- payment attempt;
- callback disposition;
- booking;
- cancellation;
- outbox delivery.

Payment precedence, timeout, failure-release and cancellation remedy transitions
are Accepted as of 2026-08-10 (`ADR-015`, `ADR-016`, `ADR-017`) and are no longer
marked `BLOCKED`. Any transition that is still unapproved must be.

**Trace:** BR-14; FR-010/014/016/020–029/032–036; RULE-019–025; AC-005–011/013.

### S0-04 — Initial ERD and constraint plan

Model the minimum required objects and identify:

- primary/foreign keys;
- uniqueness constraints;
- state-dependent checks;
- snapshot fields;
- deletion restrictions;
- idempotency scope;
- callback identity;
- lock order;
- authoritative timestamps.

Do not generalize Seatly into a reusable reservation platform.

**Trace:** BR-02–04; BRD §10; ADR-001/004/005; NFR-001/002/010.

### S0-05 — Traceability and evidence matrix

Map every planned test to BR/FR/RULE/NFR/AC IDs and a gate or P0 invariant.
Define evidence locations and required metadata.

**Trace:** BRD §12/15/17; RELEASE_GATES required evidence pack; G0–G6.

### S0-06 — Record unresolved blockers

At minimum, reconcile or preserve blockers related to:

- simulator contract and callback authentication details;
- payment timeout and reconciliation-window closure;
- contradictory payment outcome precedence;
- hold release after explicit payment failure;
- showtime-cancellation remedy and authority;
- QR payload/privacy details;
- role split and destructive hall/seat edit policy;
- exact cancellation timezone boundary if not fully resolved by the accepted
  timezone baseline.

`S0-01` recorded these in `BASELINE_RECONCILIATION.md` §7, and the review session
of 2026-08-10 closed all but three. What `S0-06` still tracks:

- `D14` — QR payload detail, privacy and rotation, before the `P5-03` QR slice;
- hall and seat versioning after publication (`FR-038`, `FR-041`), before `C2-03`
  implements destructive edit rules;
- `D20` — named approvers per governance role, for the G0 multi-role sign-off.

**Trace:** D6–D10, D13–D15; RULE-022–025; FR-019/021/025–027/032/036/038/041.

**Phase exit:** Sources, states, ERD, blockers, and traceability are reviewable.
No ambiguity has been silently converted into implementation behavior.

---

## Phase 1 — Reproducible project foundation

**Goal:** Initialize the selected Laravel stack and prove the test harness.  
**Gate:** G1 foundation prerequisites.  
**Prerequisite:** Phase 0 accepted for implementation.

### F1-01 — Initialize and pin the stack

- Record Laravel, PHP, Node, PostgreSQL, Pest/PHPUnit, and Playwright versions.
- Initialize Laravel with Vue 3/Inertia.
- Include no speculative packages.

### F1-02 — Real PostgreSQL environment

- Configure separate development and integration databases.
- Provide deterministic migrate/reset/seed behavior.
- Fail clearly if concurrency tests are pointed at SQLite.
- Record PostgreSQL version and transaction assumptions.

**Trace:** BR-02; RULE-004; NFR-001; ADR-002/004.

### F1-03 — Test-first project skeleton

- Add one deliberately failing smoke test for each configured test layer.
- Demonstrate red before adding the minimum setup that makes it green.
- Verify unit, PostgreSQL integration, and Playwright execution separately.
- Replace command placeholders only with commands that actually ran.

### F1-04 — CI skeleton

CI must run, as applicable:

- dependency installation;
- configuration validation;
- lint/format checks;
- static analysis if adopted;
- migrations against PostgreSQL;
- unit and integration tests;
- browser-test setup;
- artifact retention for evidence.

Do not mark absent browser or PostgreSQL execution as skipped success.

### F1-05 — Architecture skeleton

Create only the minimum conventions needed for the first slice:

- explicit Actions;
- enums/state transitions;
- policies;
- correlation IDs;
- test factories/seeders.

Do not pre-create empty layers or interfaces for future features.

**Phase exit:** A clean environment can initialize, migrate PostgreSQL, and run
verified red/green smoke tests using documented commands.

---

## Phase 2 — Minimal catalogue and inventory fixture

**Goal:** Create only enough catalogue behavior to exercise authoritative seat
allocation. Avoid customer-facing polish.  
**Gate contribution:** G1, G2, and prerequisite to G3.

### C2-01 — Movie, venue, hall, and seat persistence

Use TDD for relationships, constraints, seat identity, classifications, and
companion links.

**Trace:** FR-037–039; PP-10; ADR-005/012; NFR-010.

### C2-02 — Showtime draft and publication

Publishing must transactionally create exactly one inventory row for every
active hall seat. Repeated publication must not duplicate inventory.

**Trace:** FR-003/039/040; RULE-001/004; ADR-005; AC-015.

### C2-03 — Structural integrity after publication

Reject changes that would corrupt active or historical showtime inventory.
Where detailed edit policy remains Open, implement only unambiguous database
protections and leave workflow decisions blocked.

**Trace:** FR-038/041; NFR-010; ADR-005.

### C2-04 — Minimal authoritative seat-map endpoint

Return stable seat identity and authoritative availability needed by tests.
Do not build polished geometry or animation.

**Trace:** FR-004/007; RULE-004; AC-001/002.

**Phase exit:** Tests can create a published showtime and authoritative inventory
without direct manual database edits.

---

## Phase 3 — Atomic hold and concurrency proof

**Goal:** Prove the central allocation invariant against real PostgreSQL.  
**Gate:** G3 / first engineering stop gate.  
**Stop rule:** Phases 4–6 remain blocked until this phase passes.

### H3-01 — Hold validation

Test before implementation:

- authenticated ownership;
- one showtime per request;
- unique seat IDs;
- one to eight seats;
- active/bookable showtime;
- accessible declaration;
- linked companion rule;
- no partial result.

**Trace:** FR-001/003/006–010; RULE-003/010–013; PP-01–04/10; AC-002/004.

### H3-02 — Transactional acquisition protocol

Implement only after failing PostgreSQL integration tests demonstrate the risk:

1. normalize and sort unique seat IDs;
2. begin transaction;
3. lock showtime;
4. lock requested inventory rows in seat-ID order;
5. evaluate availability and expiry using PostgreSQL time;
6. reject the whole request if any row fails;
7. create hold and immutable price snapshot;
8. update all inventory;
9. persist required durable event;
10. commit before reporting success.

**Trace:** BR-02–05; FR-007–012; RULE-001–005/012–014; ADR-004; AC-001–003.

### H3-03 — Scoped idempotency and lost-response recovery

Test same key/same fingerprint, same key/different fingerprint, replay after
commit, and concurrent duplicate submission.

**Trace:** FR-017/028/029; RULE-006; NFR-002/007; AC-009; EX-09/13.

### H3-04 — Same-seat contention harness

Coordinate at least 100 requests inside approximately one second:

- exactly one succeeds;
- all losers receive deterministic conflict;
- all responses complete;
- no server-error loser;
- fixed seed and repeated runs.

**Trace:** BO-01; AC-001; NFR-001; RELEASE_GATES same-seat contention.

### H3-05 — Overlapping multi-seat harness

Run repeated seeded contention for:

```text
A: A1 + A2
B: A2 + A3
C: A1 + A3
```

No losing request may retain one seat.

**Trace:** BO-02; AC-002; RULE-003; RELEASE_GATES overlapping groups.

### H3-06 — Post-run invariant SQL

Report at minimum:

- duplicate active hold ownership;
- duplicate confirmed ownership;
- inventory pointing to missing holds/bookings;
- hold-item/inventory coverage mismatch;
- partial requested-set ownership;
- invalid state/link combinations.

### H3-07 — Gate evidence

Capture:

- build identifier;
- schema checksum;
- PostgreSQL version;
- seed and request distribution;
- response classifications and latency;
- deadlock/retry information;
- invariant SQL and output;
- repeated-run results.

**Phase exit:** Zero duplicate ownership and zero partial holds across repeated
seeded real-PostgreSQL runs. Any flaky correctness result fails the gate.

---

## Phase 4 — Hold lifecycle and accessible seat selection

**Goal:** Complete expiry and the minimum usable seat-selection journey.  
**Prerequisite:** Phase 3 passed.

### L4-01 — Inline expiry correctness

New hold and confirmation paths must recognize expired inventory even if the
worker is delayed.

**Trace:** FR-011/013–015; RULE-012/013/021; AC-007; EX-03.

### L4-02 — Idempotent expiry/release worker

Test before, at, and after authoritative expiry; duplicate jobs; delayed jobs;
and worker crash/retry.

**Trace:** FR-014/015; NFR-002/007/009; RELEASE_GATES hold-expiry race.

### L4-03 — Accessible seat grid

Use semantic controls, keyboard operation, visible focus, screen-reader
names/states, non-colour status, accessible errors, and perceivable countdown.

**Trace:** BR-12; FR-004/005/011; NFR-004; AC-014; ADR-010/012.

### L4-04 — Stale-map recovery and polling

Versioned short polling with jitter and out-of-order response protection.
A stale map may cause rejection but never false success.

**Trace:** FR-004/007/009; NFR-007/008; ADR-007; P0 invariant 6.

**Phase exit:** Expired inventory remains safely reclaimable with delayed cleanup,
and the hold journey passes its keyboard-focused evidence.

---

## Phase 5 — Simulated payment and booking

**Goal:** Implement asynchronous payment.  
**Gate:** G4.  
**Prerequisite:** Phase 3 passed.  
**Status:** Unblocked 2026-08-10 — `ADR-015` resolved every payment policy this
phase depended on. See `SPRINT_0_REVIEW_DECISIONS.md` for the outcomes.

### P5-01 — Simulator contract and payment attempt

`READY`. Callback schema, authentication, idempotency identity, timeout,
contradictory-outcome precedence and failure-release policy are all Accepted
under `ADR-015`.

Implement to these guards specifically:

- checkout is rejected within 60 seconds of `hold.expires_at`
  (`HOLD_TOO_CLOSE_TO_EXPIRY`);
- at most three attempts per hold (`PAYMENT_ATTEMPT_LIMIT_REACHED`);
- an explicit failure leaves the hold `ACTIVE` until the third one;
- the deadline is `hold.expires_at` — there is no `timeout_at` column;
- `CREATED` at expiry becomes `FAILED`/`NEVER_SUBMITTED` with no case
  (`PAY-05b`), `PENDING` becomes `TIMED_OUT` with one.

**Trace:** FR-016–021/025/026; D6–D9 (closed); RULE-022–024; ADR-015.

### P5-02 — Callback authentication and inbox

Test invalid signature, malformed payload, unknown correlation, wrong
amount/currency, duplicate event ID, and same ID/different payload.

**Trace:** FR-018–020; AC-006/017; EX-06/12.

### P5-03 — Atomic booking confirmation

Lock in the common order and revalidate hold, showtime, inventory, amount,
currency, and coverage before committing one booking/reference/QR linkage.

**Trace:** BR-03/06/07; FR-022–024; RULE-005/007; AC-005.

### P5-04 — Late, duplicate, reordered, and lost-response behavior

`READY`. Test payment chaos and explicit reconciliation. Never reclaim a released
seat after late success.

Precedence is fixed by `ADR-015`: the first valid final outcome committed in
PostgreSQL wins, provider ordering is never authoritative, and a contradiction
reverses nothing. Cases are rows in `reconciliation_cases`, not a status on
`payments` — `PAY-13` and showtime cancellation both need a case while the
payment stays `SUCCEEDED`. `PAY-11b` must reopen a closed `UNRESOLVED` case on a
valid late success.

**Trace:** FR-020/021/026–029; RULE-006/018/019/022/023/025; AC-006–010; EX-05–09/13; ADR-015.

### P5-05 — Transactional outbox and crash/replay

Test worker failure before commit, after business commit, before
acknowledgement, poison work, and prolonged outage.

**Trace:** BR-14; NFR-002/006/007/009; ADR-006; RELEASE_GATES worker/outbox failure.

**Phase exit:** Repeated and adversarial delivery creates one booking, one
inventory transition, one reference, and one logical outbox effect.

---

## Phase 6 — Ownership, cancellation, and operator controls

**Goal:** Complete secure customer and operator workflows.  
**Gate:** G4/G5.  
**Prerequisite:** Phase 3 passed.  
**Status:** Unblocked 2026-08-10 — `ADR-016` fixed the cancellation boundary and
`ADR-017` the roles, cancellation effects and authority.

### O6-01 — My Bookings, detail, and QR authorization

`READY`. Test Customer A, Customer B, unauthenticated, operator, and admin
identities. Prevent protected-object disclosure.

`OPERATOR` and `ADMIN` are genuinely different under `ADR-017`: an operator must
be refused `showtimes.cancel`, `bookings.cancel`, and seat block/unblock. That
negative case is the privilege-escalation half of the matrix.

**Trace:** BR-08/11; FR-030/031; RULE-008; NFR-003; AC-012/018.

### O6-02 — Customer cancellation

`READY`. Use PostgreSQL time, stable duplicate outcomes, atomic booking
transition, and seat resale only while the showtime remains on sale.

The cutoff comes from `bookings.cancellation_cutoff_minutes_snapshot`, never the
live showtime value (`ADR-016`). Equality is ineligible — test at the boundary
instant, one second either side, and with a booking whose snapshot differs from
the showtime's current value.

**Trace:** FR-032–035; RULE-015/016/021; AC-013; EX-14; ADR-016.

### O6-03 — Showtime cancellation race

`READY`. Race cancellation with hold, payment initiation, callback, confirmation,
expiry, and stale clients. No confirmation may begin after authoritative
cancellation commits.

Per `ADR-017`: `ADMIN` only, `transaction_timestamp() < starts_at` or
`SHOWTIME_ALREADY_STARTED`, an impact preview must be confirmed first, and the
transaction opens exactly one `SHOWTIME_CANCELLED_REFUND_DUE` case per confirmed
booking. Cancellation is terminal — assert that reopening is refused.

**Trace:** FR-036/040/041; RULE-020/021/025; AC-011; EX-10; ADR-017.

### O6-04 — Audit and operational visibility

Record actor/system, subject, correlation, transition, timestamp, and result.
Keep audit inspection read-only through normal application functions.

**Trace:** BR-10/14; FR-042–044; NFR-006/009; AC-015.

**Phase exit:** Cancellation and confirmation races converge on a valid,
auditable outcome with no unauthorized access.

---

## Phase 7 — Release evidence and hardening

**Goal:** Produce reproducible evidence, not a code-complete assertion.  
**Gate:** G5/G6.

Backlog items:

- real-PostgreSQL regression and concurrency report;
- payment/outbox chaos report;
- authorization, IDOR, privacy, CSRF, and dependency evidence;
- manual keyboard and screen-reader evidence;
- hot-showtime, polling, callback-burst, lock, pool, and worker-backlog load
  evidence;
- migration interruption and rollback rehearsal;
- isolated backup restore and invariant reconciliation;
- retention/deletion job and post-restore deletion-manifest evidence;
- health, logging, metrics, alerts, and runbook;
- release and rollback plan;
- complete requirements-to-test evidence matrix.

**Trace:** NFR-001–010; AC-014/016; RELEASE_GATES all P0 invariants and required
evidence.

**Phase exit:** All P0 evidence passes on the release candidate, with zero
unexplained invariant mismatch and no open Critical/High correctness or security
defect.

---

## Deferred by default

Do not implement without an approved change request:

- real payments or refunds;
- guest checkout;
- notifications;
- loyalty, vouchers, food ordering, or promotions;
- dynamic/multi-currency pricing;
- multi-brand tenancy;
- graphical hall designer;
- offline QR scanning;
- mobile, kiosk, POS, or marketplace clients;
- Redis/Horizon, WebSockets, Kafka, microservices, or Kubernetes;
- generic reservation-engine abstractions.
