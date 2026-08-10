# Seatly Business Requirements Document

**Document ID:** SEATLY-BRD-001  
**Version:** 0.1  
**Date:** 2026-08-09  
**Project:** Seatly Cinema Reservation and Booking System  
**Project type:** Greenfield  
**Status:** Product policy baseline approved with conditions  
**Prepared by:** Business Analyst and Project Manager Council  
**Council verdict:** Conditional Go

## Document control

| Version | Date | Description | Approval status |
|---|---|---|---|
| 0.1 | 2026-08-09 | Initial BRD incorporating project brief, PM governance, BA requirements, acceptance criteria, and BPMN process | Product policy baseline approved 2026-08-10; detailed operational decisions remain open |
| 0.2 | 2026-08-10 | Baseline reconciliation applied — `RC-01` (browse authentication resolved, `RULE-010` rewritten, `D1` closed), `RC-10` (gate timings aligned to the delivery plan), `RC-13` (approval record corrected to PP-01–PP-13). See [`BASELINE_RECONCILIATION.md`](BASELINE_RECONCILIATION.md) | Product Owner approved `RC-01` on 2026-08-10; `D6`–`D15` and `D20` remain open |
| 0.3 | 2026-08-10 | `PD-01`–`PD-10` resolved as `ADR-015` (payment lifecycle), `ADR-016` (cancellation cutoff), `ADR-017` (staff roles and showtime cancellation). `RULE-022`–`RULE-025` become Accepted; `RULE-015` gains its exact boundary; `D6`–`D13` closed, `D15` partly closed | Product Owner approved 2026-08-10. `D14`, `D20`, hall/seat versioning and `BO-08`'s exact accessibility standard remain open |

### Requirement status vocabulary

- **Accepted** — supported by the approved project baseline.
- **Proposed** — recommended business policy that still requires Product Owner approval.
- **Open** — unresolved decision; it must not be silently implemented.

Normative wording: **shall** indicates a requirement. A Proposed or Open item does not become Accepted merely because it appears in this BRD.

---

## 1. Executive summary

Seatly is a greenfield, cinema-specific web application for cinema customers and cinema operators. A customer discovers a movie and showtime without an account, selects seats using an accessible seat map, authenticates at the hold request, obtains a temporary all-or-none hold, completes a simulated asynchronous payment, and receives a booking reference and QR after authoritative confirmation. Customers can inspect their own bookings and request eligible cancellation. Authorized operators maintain the cinema catalogue, fixed halls, showtimes, and operational booking state.

The primary business invariant is:

> A seat at one showtime shall belong to at most one active confirmed booking.

PostgreSQL is the authoritative source of hold, inventory, payment disposition, and booking state. Browser state, application memory, worker state, and payment-provider state are not authoritative for seat allocation.

The planned solution is a Laravel modular monolith using Vue 3/Inertia and PostgreSQL, operated through web, worker, and scheduler processes. The baseline delivery plan is 16 weeks part-time and is governed by evidence-based exit gates. Production promotion remains **No-Go** until mandatory concurrency, idempotency, security, accessibility, migration, backup/restore, and reconciliation evidence passes.

No external revenue, conversion, customer-growth, or booking-volume target is treated as confirmed in this BRD.

---

## 2. Business context

### 2.1 Current state

Seatly is a greenfield initiative. No incumbent Seatly workflow, production process, migration source, customer dataset, payment integration, or existing cinema operating procedure has been supplied.

Therefore:

- there is no current Seatly process to preserve;
- no data migration is assumed;
- real charging, settlement, chargeback, and refunds are not part of the MVP;
- cinema-specific policies not explicitly marked Accepted remain Proposed or Open.

### 2.2 Future state

The target service provides a consistent and auditable booking journey under contention, retries, asynchronous payment callbacks, hold expiry, cancellation races, worker delays, and lost client responses.

The system shall expose explicit outcomes such as:

```text
PENDING
CONFIRMED
FAILED
EXPIRED
CANCELLED
SHOWTIME_CANCELLED
RECONCILIATION_REQUIRED
```

An asynchronous exception shall never be silently discarded or silently converted into a booking confirmation.

---

## 3. Business objectives and measurable outcomes

| ID | Objective | Measurable MVP outcome / approval evidence | Status |
|---|---|---|---|
| BO-01 | Prevent confirmed double-booking | Concurrency and retry suites produce zero duplicate confirmed allocations for the same showtime-seat | Accepted |
| BO-02 | Preserve multi-seat integrity | A hold request receives all requested seats or none; no partial hold is presented as success | Accepted |
| BO-03 | Provide a complete booking journey | An authenticated user completes browse, hold, simulated payment, confirmation, reference/QR, and My Bookings in E2E evidence | Accepted |
| BO-04 | Handle asynchronous payment safely | Duplicate, delayed, contradictory, reordered, and retried simulated events do not create duplicate bookings or contradictory final states | Accepted |
| BO-05 | Preserve ambiguous outcomes | Late or uncertain payment outcomes enter an explicit, inspectable reconciliation state | Accepted |
| BO-06 | Support customer self-service visibility | Confirmed booking details, reference, status, and QR are available only to the booking owner and authorized operator | Accepted |
| BO-07 | Support controlled cinema operations | Authorized operators manage catalogue/showtimes and inspect booking, inventory, exception, and audit state | Accepted |
| BO-08 | Provide an inclusive journey | The critical booking flow passes approved keyboard, screen-reader, focus, error, and non-colour-only checks | Accepted. Standard fixed 2026-08-10: **WCAG 2.2 Level AA**, critical journey only — browse, seat selection, hold countdown, payment, confirmation, cancellation. Operator and admin screens are out of certification scope |
| BO-09 | Demonstrate operational recoverability | Deployment, migration rehearsal, clean-environment restore, and integrity reconciliation pass before production approval | Accepted |
| BO-10 | Deliver within an exit-gated baseline | Progress is assessed against the 16-week part-time baseline and milestone evidence; elapsed dates cannot override a failed gate | Accepted |

---

## 4. Stakeholders and actors

### 4.1 Business actors

| Actor | Responsibilities and interests | Boundary notes |
|---|---|---|
| Customer | Browse, select showtime/seats, request hold, review price, initiate simulated payment, receive outcome, view and cancel owned booking | Browsing is public. Authentication is required from the hold request onward, and for every transactional operation thereafter (`ADR-014`, `RC-01`) |
| Cinema Operator | Maintain movie, venue, fixed hall/seat, and showtime records; publish showtimes; inspect booking, inventory, exception and audit state | Cannot cancel a showtime or a booking, and cannot block or unblock seats (`ADR-017`) |
| Cinema Admin | Everything an Operator may do, plus showtime cancellation, booking cancellation, and seat block/unblock | Irreversible commands require a controlled reason and an audit record; showtime cancellation additionally requires a confirmed impact preview (`ADR-017`) |
| Seatly System/Worker | Authoritative validation, hold, snapshot, payment event processing, booking confirmation, expiry, status, audit, and reconciliation | Internal implementation does not change business rules |
| Simulated Payment Provider | Accept simulated payment initiation and issue configured acknowledgement/callback scenarios | No real funds movement |

### 4.2 Project governance roles

All roles are held by Fatihi (`D20`, closed 2026-08-10). One person may hold multiple roles in a solo project, but Business Acceptance, Technical Recommendation, QA Evidence, and Release Authorization shall still be recorded **separately**, each against its own gate and its own evidence — naming one person does not let one signature satisfy four.

| Role | Primary accountability |
|---|---|
| Business Sponsor | Mandate, baseline approval, escalation, final business Go/No-Go |
| Product Owner | Requirement priority, policy decisions, business acceptance |
| Project Manager | Plan, RAID, dependencies, gate coordination, reporting, change control |
| Business Analyst | Requirements, business rules, process, traceability, acceptance criteria |
| Technical Lead | Architecture, data integrity, concurrency design, migration and technical readiness |
| Engineering | Implementation and engineering evidence |
| QA Lead | Test strategy, defect status, traceability and quality recommendation |
| UX/Accessibility Owner | Journey and accessibility evidence |
| Security Reviewer | Authorization, privacy and production security recommendation |
| Operations/Release Owner | Deployment, monitoring, backup, restore, rollback and release execution |

### 4.3 RACI

**R:** Responsible, **A:** Accountable, **C:** Consulted, **I:** Informed.

| Deliverable/decision | Sponsor | Product Owner | PM | BA | Tech Lead | Engineering | QA | UX/A11y | Security | Operations |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| BRD and MVP baseline | A | R | R | R | C | I | C | C | C | C |
| Business-policy decisions | C | A/R | C | R | C | I | C | C | I | I |
| Delivery plan and forecast | I | C | A/R | C | C | C | C | C | C | C |
| Architecture/data integrity | I | C | C | C | A | R | C | I | C | C |
| Acceptance/traceability | I | A | R | R | C | C | R | C | C | I |
| Accessibility acceptance | I | A | C | C | C | R | R | R | I | I |
| Security recommendation | I | C | C | C | R | R | C | I | A | C |
| Migration/deployment/restore | I | I | C | I | R | C | C | I | C | A/R |
| Production Go/No-Go | A | R | C | C | C | I | C | C | C | R |

---

## 5. Scope

### 5.1 Accepted MVP scope

#### Customer capabilities

- Authentication before transactional operations.
- Browse/search by movie, venue, date, and showtime.
- Accessible fixed-hall seat map.
- Atomic, temporary multi-seat hold.
- Server-authoritative hold expiry and accessible countdown.
- Immutable checkout price snapshot.
- Simulated asynchronous payment.
- Booking confirmation, unique reference, and displayable QR linkage.
- My Bookings and booking detail.
- Policy-controlled customer cancellation.

#### Operator capabilities

- Authorized movie and venue maintenance.
- Fixed hall and seat maintenance while protecting historical integrity.
- Showtime create, update, publish, inspect, and cancel.
- Booking, inventory, exception, and audit visibility.
- Controlled administrative cancellation.

#### Platform capabilities

- PostgreSQL-authoritative inventory and booking state.
- Atomic all-or-none hold and confirmation.
- Scoped idempotency for customer commands and callbacks.
- Database-backed queue and transactional outbox.
- Expiry, callback, reconciliation, and retry workers.
- Structured logs, correlation identifiers, health checks, and metrics.
- Migration rehearsal, backup, restore, and invariant reconciliation.

### 5.2 Approved MVP policies

These policies were approved by the Product Owner on 2026-08-10 and are baseline business rules.

| ID | Approved policy | Status |
|---|---|---|
| PP-01 | Public browsing; authentication required from the hold request onward; guest checkout deferred | Accepted; browse boundary resolved 2026-08-10 (`ADR-014`) |
| PP-02 | Maximum eight seats per booking | Accepted |
| PP-03 | Hold duration is five minutes from authoritative creation | Accepted |
| PP-04 | Hold cannot be extended | Accepted |
| PP-05 | One price applies to all seats in one showtime | Accepted |
| PP-06 | Customer cancellation allowed until two hours before showtime | Accepted |
| PP-07 | Eligible cancelled seats return to availability while the showtime remains on sale | Accepted |
| PP-08 | Late payment success creates reconciliation/refund-required state and never reclaims a seat | Accepted treatment; no real refund implied |
| PP-09 | QR is display-only and is not an offline admission credential | Accepted |
| PP-10 | Accessible seating uses booking-scoped self-declaration without medical evidence; a companion requires its uniquely linked accessible seat | Accepted; see `MVP_POLICY_BASELINE.md` |
| PP-11 | Customer locale `en-MY`, venue timezone `Asia/Kuala_Lumpur`, UTC persistence, currency `MYR`, money in integer sen | Accepted |
| PP-12 | Capacity and latency baseline: 300 active customers, 70 RPS normal peak, 100 RPS burst, documented p95 and worker-lag targets | Accepted; engineering objective, not SLA |
| PP-13 | PostgreSQL RPO 15 minutes, service RTO 4 hours, maximum backup age 90 days, and documented minimised retention schedule | Accepted; review before commercial production |

### 5.3 Out of scope

- Real payment gateway, funds movement, settlement, chargeback, and real refunds.
- Guest checkout and account linking.
- Email, SMS, and push notifications.
- Loyalty, memberships, vouchers, promotions, and food ordering.
- Dynamic, seat-tier, or multi-currency pricing.
- Multi-brand tenancy.
- Graphical hall-layout designer.
- Offline QR scanning or validation.
- Native mobile, kiosk, POS, and third-party marketplace integrations.
- Incumbent data migration.
- Redis/Horizon as a requirement, WebSockets, Kafka, microservices, and Kubernetes.
- Detailed operator-maintenance BPMN, finance reconciliation BPMN, and support case-management BPMN.

---

## 6. Business requirements

| ID | Requirement | Status |
|---|---|---|
| BR-01 | Seatly shall provide a cinema-specific online journey from authentication and showtime discovery through booking outcome | Accepted |
| BR-02 | Seatly shall maintain one authoritative, consistent seat-inventory and booking record in PostgreSQL | Accepted |
| BR-03 | Seatly shall prevent a showtime-seat from belonging to more than one active confirmed booking | Accepted |
| BR-04 | Seatly shall reserve a requested multi-seat selection atomically: all requested seats or none | Accepted |
| BR-05 | Seatly shall expose a checkout price snapshot for the exact held seats/showtime before payment initiation | Accepted |
| BR-06 | Seatly shall remain correct under duplicate, late, lost-response, retried, and out-of-order simulated payment interactions | Accepted |
| BR-07 | Seatly shall issue a booking reference and QR only for a confirmed booking | Accepted |
| BR-08 | Seatly shall let customers view their own bookings and request cancellation under approved policy | Accepted; policy details Proposed/Open |
| BR-09 | Seatly shall let authorized staff maintain movies, venues, fixed halls/seats, and showtimes, including publication/cancellation | Accepted |
| BR-10 | Seatly shall provide authorized operational visibility of booking, inventory, exceptions, and audit history | Accepted |
| BR-11 | Seatly shall protect personal and booking data and restrict access by authorization | Accepted |
| BR-12 | Seatly shall provide an accessible customer journey and seat-map interaction | Accepted |
| BR-13 | Seatly shall recover authoritative booking and inventory data through tested backup and restore | Accepted |
| BR-14 | Operational outcomes shall be explicit and traceable; asynchronous exceptions shall not be silently discarded or confirmed | Accepted |

---

## 7. Functional requirements

### 7.1 Customer discovery and seat selection

| ID | Functional requirement | Status |
|---|---|---|
| FR-001 | Authenticate a customer before creating/changing a hold, payment attempt, booking, or cancellation | Accepted |
| FR-002 | Permit browse/search by movie, venue, date, and showtime without authentication | Accepted (`RULE-010`) |
| FR-003 | Present showtime status and prevent booking initiation for an unbookable showtime | Accepted |
| FR-004 | Present the fixed-hall seat map with seat identity and current selectable/unavailable state | Accepted |
| FR-005 | Make seat selection usable without relying solely on colour, pointer input, or visual geometry | Accepted |
| FR-006 | Validate selected seat count against the approved maximum | Accepted: 8 |
| FR-007 | Revalidate showtime and seat availability authoritatively when a hold is requested | Accepted |

### 7.2 Hold and checkout

| ID | Functional requirement | Status |
|---|---|---|
| FR-008 | Create one authoritative all-or-none hold transaction for the requested seats | Accepted |
| FR-009 | Return a stale-seat/conflict outcome and create no partial hold when any seat is unavailable | Accepted |
| FR-010 | A successful hold records unique ID, owner, showtime, seats, creation, expiry, and state | Accepted |
| FR-011 | Expose hold expiry during checkout in an accessible form | Accepted |
| FR-012 | Create/display an immutable checkout price snapshot before payment | Accepted |
| FR-013 | Reject payment initiation for an absent, non-owned, non-active, expired, or showtime-invalid hold | Accepted |
| FR-014 | Expire an unconfirmed hold at its approved deadline and make seats eligible for authoritative reallocation | Accepted: 5 minutes |
| FR-015 | Do not extend a hold | Accepted |

### 7.3 Payment and confirmation

| ID | Functional requirement | Status |
|---|---|---|
| FR-016 | Create one identifiable simulated payment attempt against one snapshot/booking intent | Accepted |
| FR-017 | Submit one logical payment request while safely recovering lost responses/retries | Accepted |
| FR-018 | Support an asynchronous provider callback correlated to the payment attempt | Accepted |
| FR-019 | Validate callback authenticity, structure, correlation, amount, and currency | Accepted (`ADR-015`) |
| FR-020 | Process callbacks idempotently so duplicates repeat no business effect | Accepted |
| FR-021 | Handle out-of-order/contradictory outcomes under the committed-order precedence policy | Accepted (`ADR-015`, `RULE-022`) |
| FR-022 | Before confirmation, revalidate active/owned/unexpired hold, showtime, and seat coverage | Accepted |
| FR-023 | On valid timely success, atomically convert held inventory into one confirmed booking | Accepted |
| FR-024 | Create a unique booking reference and display-only QR linkage after confirmation | Accepted |
| FR-025 | Explicit payment failure creates no booking; the hold survives for a further attempt, and the third failure releases it | Accepted (`ADR-015`, `RULE-024`) |
| FR-026 | Timeout is neither success nor definitive failure; expose pending/timeout/reconciliation status. The deadline is the hold's expiry | Accepted (`ADR-015`, `RULE-023`) |
| FR-027 | Late success after hold expiry/release shall not confirm seats and opens a reconciliation case recording the refund owed | Accepted (`ADR-015`, `RULE-025`) |
| FR-028 | Same-operation retry after lost response returns/recovers the existing logical outcome | Accepted (`ADR-015`) |
| FR-029 | Customer can retrieve latest authoritative booking/payment outcome | Accepted |

### 7.4 Booking visibility and cancellation

| ID | Functional requirement | Status |
|---|---|---|
| FR-030 | My Bookings lists only bookings the authenticated customer may view | Accepted |
| FR-031 | Booking detail shows reference, movie, venue, date/time, seats, status, and QR | Accepted |
| FR-032 | Evaluate cancellation against ownership, booking/showtime state, PostgreSQL time, and the cutoff snapshotted on the booking | Accepted (`ADR-016`) |
| FR-033 | Eligible cancellation changes booking state atomically and returns seats to availability while showtime remains on sale | Accepted |
| FR-034 | Ineligible/duplicate cancellation returns stable state/reason without repeated effects | Accepted |
| FR-035 | Distinguish customer cancellation from cinema/showtime cancellation | Accepted |
| FR-036 | Showtime cancellation prevents new confirmation once authoritative, applies to future showtimes only, and opens one reconciliation case per affected confirmed booking | Accepted (`ADR-017`) |

### 7.5 Administration and audit

| ID | Functional requirement | Status |
|---|---|---|
| FR-037 | Authorized admins create/update/activate/deactivate/inspect movies and venues | Accepted |
| FR-038 | Authorized admins maintain fixed halls/seats while protecting historical booking integrity | Accepted; post-use edit rules closed by `ADR-019` |
| FR-039 | Authorized admins create/update showtimes against valid movie, venue, hall, and timing data | Accepted |
| FR-040 | Authorized admins publish and cancel showtimes | Accepted |
| FR-041 | Prevent or explicitly resolve destructive admin changes conflicting with active holds/bookings | Accepted; hall/seat rules closed by `ADR-019`, venue deactivation by `ADR-020` |
| FR-042 | Authorized admins inspect booking and inventory by cinema/showtime criteria | Accepted |
| FR-043 | Audit security-relevant and business-state-changing actions with actor/system, subject, time, correlation, and result | Accepted |
| FR-044 | Authorized admins inspect audit history without altering it through normal functions | Accepted |

---

## 8. Business rules

| ID | Rule | Status |
|---|---|---|
| RULE-001 | A seat is inventory in the context of one showtime | Accepted |
| RULE-002 | No showtime-seat may belong to more than one active confirmed booking | Accepted |
| RULE-003 | A multi-seat hold is all-or-none | Accepted |
| RULE-004 | PostgreSQL is authoritative for hold, booking, and inventory state | Accepted |
| RULE-005 | Payment success alone is insufficient; hold/showtime/inventory must remain confirmable | Accepted |
| RULE-006 | Reprocessing the same logical request/callback shall not repeat its effect | Accepted |
| RULE-007 | Booking reference/QR may be issued only after confirmation | Accepted |
| RULE-008 | Customers may access only their own transactional records | Accepted |
| RULE-009 | Administrative functions require an authorized role | Accepted |
| RULE-010 | Browsing the catalogue, showtimes, and the read-only seat map is public. A customer shall be authenticated before a hold is created, and for every transactional operation thereafter | Accepted 2026-08-10 (`ADR-014`, `RC-01`) |
| RULE-011 | Maximum eight seats per booking | Accepted |
| RULE-012 | Hold expires five minutes after authoritative creation | Accepted |
| RULE-013 | Hold cannot be extended | Accepted |
| RULE-014 | One price applies to all seats | Accepted; currency is `MYR` per `PP-11`, and tax/fee engines are an explicit non-goal (`LOGICAL_ERD.md` §8) |
| RULE-015 | Customer cancellation is eligible only while PostgreSQL time is strictly earlier than scheduled start minus the cutoff snapshotted on the booking, default 120 minutes; equality is ineligible | Accepted 2026-08-10 (`ADR-016`) |
| RULE-016 | Eligible cancelled seats return for resale while the showtime remains on sale | Accepted |
| RULE-017 | QR is display-only, not an admission-validation credential | Accepted |
| RULE-018 | Late success cannot reinstate/reclaim a seat | Accepted |
| RULE-019 | Failure, timeout, duplicate, contradictory, uncorrelated, and late callbacks receive an explicit disposition | Accepted |
| RULE-020 | Authoritative showtime cancellation prevents subsequent confirmation | Accepted |
| RULE-021 | Committed database outcome determines simultaneous expiry/callback/cancellation ordering | Accepted |
| RULE-022 | The first valid final provider outcome committed in PostgreSQL wins; provider timestamps and sequence claims are never authoritative; a later contradictory outcome is recorded and opens reconciliation but reverses nothing | Accepted 2026-08-10 (`ADR-015`) |
| RULE-023 | The payment deadline is the hold's `expires_at`; no final callback by then makes the attempt `TIMED_OUT`, which is absence of an outcome and not provider failure; the case closes as `UNRESOLVED` 24 hours later and reopens on a valid late success | Accepted 2026-08-10 (`ADR-015`) |
| RULE-024 | Explicit payment failure leaves the hold active for a further attempt until expiry, to a maximum of three attempts; the third failure releases the hold | Accepted 2026-08-10 (`ADR-015`) |
| RULE-025 | Late success, showtime cancellation, and administrative cancellation each open an explicit reconciliation case recording that a remedy is owed; no real refund or notification is performed in MVP | Accepted 2026-08-10 (`ADR-015`, `ADR-017`) |

---

## 9. Business-facing non-functional requirements

| ID | Requirement / release gate | Acceptance evidence | Status |
|---|---|---|---|
| NFR-001 | Concurrency integrity | Repeatable production-equivalent PostgreSQL tests show zero duplicate confirmation and zero partial holds | Accepted |
| NFR-002 | Idempotency | Duplicate/reordered/delayed/replayed commands and callbacks preserve stable logical outcome | Accepted |
| NFR-003 | Privacy and authorization | Cross-customer and unauthorized-admin matrix passes; logs/outbox/QR exclude unnecessary secrets/PII | Accepted |
| NFR-004 | Accessibility | Automated plus manual keyboard/screen-reader/focus/contrast/error evidence passes | Accepted; baseline in `MVP_POLICY_BASELINE.md` |
| NFR-005 | Recoverability | Restore exercise meets 15-minute PostgreSQL RPO, four-hour service RTO, and invariant checks | Accepted |
| NFR-006 | Auditability | Sampled journeys/exceptions reconstruct actor, correlation, transition, timestamp, and disposition | Accepted |
| NFR-007 | Outcome resilience | Lost-response tests recover status without repeating irreversible effect | Accepted |
| NFR-008 | Timeliness and capacity | Seat-map, hold, payment, booking, worker, and load targets in `MVP_POLICY_BASELINE.md` pass | Accepted engineering objective |
| NFR-009 | Operational observability | Staff can identify unresolved payment/expiry/cancellation/callback exceptions by correlation ID | Accepted |
| NFR-010 | Data integrity over change | Hall/seat/showtime changes cannot corrupt active or historical booking meaning | Accepted |

---

## 10. Business data objects

| Object | Purpose / minimum attributes | Lifecycle notes |
|---|---|---|
| Customer Account | Customer identity, authentication link, status | Identity and retention details Open |
| Movie | ID, title, descriptive/status data | Admin-maintained |
| Venue | ID, name, location/timezone, status | Timezone required for policy evaluation |
| Hall | ID, venue, name/number, status, layout version if adopted | Fixed layout; version/edit rule Open |
| Seat Definition | ID, hall, row/label, accessibility/features, status | Stable historical identity required |
| Showtime | ID, movie, hall, scheduled start, status | Authoritative bookability context |
| Seat Inventory State | Showtime-seat key, status, hold/booking linkage, price, version | PostgreSQL authoritative |
| Hold | ID, customer, showtime, seat set, created/expiry, status | All-or-none creation |
| Price Snapshot | Hold, line/total amount, currency, pricing basis/version, time | Immutable before payment initiation |
| Payment Attempt | Attempt, hold/snapshot, provider correlation, amount/currency, status/timestamps | Simulated; precedence/timeout Open |
| Callback Record | Provider message identity, correlation, claimed result, receipt time, validation/disposition | Supports idempotency/audit |
| Booking | ID/reference, customer, showtime, seats, snapshot, status/timestamps | Confirmed atomically |
| QR Linkage | Booking and display token/version | Proposed display-only; no PII |
| Cancellation | ID, actor, scope, reason, decision, time, disposition | Customer/showtime cancellation distinct |
| Audit Record | Actor/system, action/transition, subject, correlation, time, result | Append-only through normal application use |
| Idempotency/Correlation Record | Scope, logical operation key, fingerprint, result linkage | Exact representation not prescribed by BRD |

---

## 11. Process exceptions

| ID | Trigger | Required disposition | Customer outcome |
|---|---|---|---|
| EX-01 | Displayed seat becomes unavailable before hold commit | Create no hold; refresh authoritative inventory | Conflict; select again |
| EX-02 | One seat in a multi-seat request is unavailable | Reject/roll back entire request | No seats held |
| EX-03 | Hold expires before confirmation | Expire; do not confirm; allow authoritative reallocation | Restart selection |
| EX-04 | Explicit payment failure | No confirmation; record result; apply approved hold-release rule | Failed with safe next step |
| EX-05 | Callback absent by timeout | Do not infer provider failure/success; expose timeout/pending and reconcile | Pending/timeout |
| EX-06 | Duplicate callback | Return existing disposition; no repeated effects | Stable outcome |
| EX-07 | Out-of-order/contradictory callback | Apply approved precedence or explicit review | Stable status or review |
| EX-08 | Success after hold expiry/release | Record late success; never auto-confirm; route approved remedy | Not confirmed; review/remedy |
| EX-09 | Lost synchronous response and retry | Correlate and recover original outcome | Existing result/status |
| EX-10 | Showtime cancellation races confirmation | One authoritative outcome; cancellation prevents later confirmation | Cancelled/affected or committed-before-cancel state |
| EX-11 | Unauthorized access | Deny without protected-object disclosure; audit as appropriate | Generic denied/not-found |
| EX-12 | Malformed/untrusted callback | No business mutation; rejected disposition | Current authoritative status unchanged |
| EX-13 | Confirmation commits but response is lost | Retry/status returns same booking/reference | Existing confirmation |
| EX-14 | Duplicate customer/showtime cancellation | One authoritative transition; idempotent repeat | Stable cancellation status |
| EX-15 | Recovery replays asynchronous input | Replay remains idempotent; invariants hold | Stable status |

---

## 12. Traceable acceptance criteria

| ID | Given / When / Then | Traces to |
|---|---|---|
| AC-001 | Given two customers request the same showtime-seat concurrently, when holds commit, then at most one receives a successful hold | BR-02/03, FR-007–009, RULE-001–004, NFR-001 |
| AC-002 | Given any seat in a multi-seat request is unavailable, when processed, then no seat in that request is held | BR-04, FR-008/009, RULE-003 |
| AC-003 | Given a successful hold, when checkout displays, then showtime, seats, expiry, amount, and currency snapshot are identifiable | BR-05, FR-010–012 |
| AC-004 | Given an approved seat limit, when it is exceeded, then no hold is created and an accessible validation message appears | FR-006, RULE-011 |
| AC-005 | Given active valid hold and first success callback, when confirmation commits before expiry/cancellation, then one booking/reference covers all held seats | BR-03/06/07, FR-018/020/022–024 |
| AC-006 | Given duplicate success callbacks, when processed, then one payment disposition and one booking effect exist | FR-020, RULE-006, NFR-002 |
| AC-007 | Given success after hold expiry/release, when processed, then no booking is auto-confirmed and late-success disposition is traceable | FR-027, RULE-018/019, EX-08 |
| AC-008 | Given no callback by timeout, when deadline passes, then Seatly does not infer success/definitive failure and exposes retrievable pending/timeout | FR-026/029, EX-05 |
| AC-009 | Given response loss after a commit, when same logical request is retried, then prior result/current state is returned without repeated effect | FR-017/028/029, NFR-002/007, EX-09/13 |
| AC-010 | Given contradictory outcomes, when processed, then approved precedence applies once or explicit review remains | FR-021, RULE-022, EX-07 |
| AC-011 | Given showtime cancellation races payment success, when transactions finish, then no confirmation commits against an already-cancelled showtime | FR-036, RULE-020/021, EX-10 |
| AC-012 | Given Customer A booking, when Customer B requests detail/QR/cancel, then access is denied without protected disclosure | BR-11, FR-030, RULE-008, NFR-003 |
| AC-013 | Given eligible confirmed booking, when owner cancels and retries, then one cancellation occurs and approved resale rule applies | FR-032–034, RULE-015/016, EX-14 |
| AC-014 | Given keyboard/screen-reader use, when completing critical journey, then every action, seat state, timer, outcome, and error is operable/understandable | BR-12, FR-004/005/011, NFR-004 |
| AC-015 | Given authorized admin, when changing/publishing/cancelling records, then permitted change applies, active state is protected, and audit is inspectable | BR-09/10, FR-037–044, NFR-006/010 |
| AC-016 | Given production-equivalent backup, when restored, then approved RPO/RTO and booking/inventory invariants pass | BR-13, NFR-005, EX-15 |
| AC-017 | Given malformed/uncorrelated/unauthorized callback, when received, then no booking/inventory mutation occurs and rejection is traceable | FR-019, EX-12, NFR-006 |
| AC-018 | Given confirmed booking, when confirmation and My Bookings open, then reference, movie, venue, showtime, seats, state, and QR agree | BR-07/08, FR-024/030/031 |

---

## 13. BPMN future-state process

### 13.1 Diagram artifacts

- Editable Draw.io BPMN diagram: [`diagrams/seatly-booking-process.drawio`](diagrams/seatly-booking-process.drawio)
- Semantic BPMN 2.0 XML: [`bpmn/seatly-booking-process.bpmn`](bpmn/seatly-booking-process.bpmn)

The Draw.io file is the maintained visual diagram. The BPMN XML is a supplementary semantic collaboration model. The analytical model is non-executable until Open policies, message correlation, deadline values, error semantics, and data mappings are approved and technically refined.

### 13.2 Pools/lanes

- Customer
- Seatly — Customer Service
- Seatly — Inventory and Booking
- Seatly — Payment and Reconciliation
- Simulated Payment Provider
- Cinema Operator/Admin (showtime cancellation interaction only)

### 13.3 Process summary

```text
Customer browses without an account
  → selects seats
  → logs in or registers at the hold request
  → Seatly validates and atomically holds all or none
  → customer reviews snapshot
  → checkout request
  → Seatly creates/reuses payment attempt
  → provider emits configured asynchronous result
  → Seatly validates and classifies callback
  → valid timely success atomically confirms booking
  → booking reference/QR returned
```

Timer and exception paths cover:

- hold expiry before checkout;
- hold expiry while payment is pending;
- payment timeout without inference of failure;
- duplicate/invalid/out-of-order callback;
- late success routed to reconciliation, never confirmation;
- lost response and authoritative status recovery;
- showtime cancellation racing hold/payment/confirmation.

### 13.4 BPMN-to-requirement traceability

| BPMN activity/group | Primary requirements |
|---|---|
| Browse and accessible seat map | BR-01/12, FR-001–005, NFR-004 |
| Validate and atomic hold | BR-02–04, FR-006–010, RULE-001–004, NFR-001 |
| Hold timer and snapshot | BR-05, FR-011–015, RULE-012–014 |
| Payment attempt and callback | BR-06/14, FR-016–021, RULE-006/019/022/023, NFR-002/007/009 |
| Atomic confirmation and QR | BR-03/07, FR-022–024, RULE-005/007 |
| Timeout/late reconciliation | FR-025–029, RULE-018/023–025, EX-04–09 |
| Showtime cancellation race | FR-035/036/040/041, RULE-020/021, EX-10/14 |
| Status/My Bookings recovery | BR-08/11, FR-028–031, NFR-003/007 |

---

## 14. Assumptions, dependencies, and constraints

### 14.1 Assumptions

| Item | Status |
|---|---|
| Part-time delivery over a 16-week baseline | Accepted |
| Reviewers are available at gates | Proposed; availability Open |
| Representative catalogue/hall/showtime data can be supplied | Open — Product Owner |
| Simulator can reproduce required callback scenarios | Proposed — Technical Lead to confirm |
| Usage/load profile will be defined before performance sign-off | Open — Product Owner/Technical Lead |
| Production-like migration and restore environment is available | Open — Operations Owner |

### 14.2 Dependencies

- Approval of Proposed booking/cancellation/payment policies.
- Named owners for business, technical, QA, accessibility, security, and release decisions.
- Representative fixed-hall and seat data.
- PostgreSQL environment for real concurrency tests.
- Worker/scheduler operation in production-like environment.
- Defined simulated payment message/correlation contract.
- Backup storage and clean restore target.
- Approved accessibility standard and test method.

### 14.3 Constraints

- Confirmed double-booking is not an acceptable residual risk.
- Multi-seat hold is atomic.
- PostgreSQL remains authoritative.
- Architecture remains a Laravel modular monolith for MVP.
- Fixed hall layouts only.
- Real payment/refund is excluded.
- Delivery capacity is part-time.
- Elapsed date does not equal milestone completion.
- Production cannot proceed with failed or absent mandatory P0 evidence.

---

## 15. Milestones and gates

| Gate | Timing | Required exit evidence | Approval |
|---|---:|---|---|
| G0 — BRD/governance baseline | Week 1 | Scope/non-goals, Proposed/Open policies, named authorities, RACI, RAID, traceability | Product Owner + PM; Sponsor baseline |
| G1 — Solution/integrity design | Week 3 | Architecture/state model, PostgreSQL authority, atomic hold, idempotency/reconciliation, migration plan | Tech Lead + QA |
| G2 — Browse/admin foundation | Week 5 | Login, browse, catalogue/fixed hall/showtime admin, authorization evidence | Product Owner + QA |
| G3 — Hold/concurrency | Weeks 7–9 | Accessible map, all-or-none holds, expiry, zero double-booking/partial hold under contention. **Concurrency stop-gate at Week 7**; accessible hold journey completes at Week 9 | Tech Lead + QA + Accessibility |
| G4 — Payment/booking/cancellation | Weeks 11–13 | Async payment, booking/ref/QR, My Bookings, cancellation, retries, reconciliation E2E | Product Owner + QA + Tech Lead |
| G5 — Release readiness | Weeks 14–15 | Regression, security, accessibility, migration, restore, worker/scheduler, zero P0 | QA + Security + Accessibility + Operations |
| G6 — Production Go/No-Go | Week 16 | Complete P0 evidence, traceability, risks, release/rollback plan, recorded decision | Sponsor + Product Owner + Release Owner |

---

## 16. Risk register

Probability/impact values are initial assessments and shall be reviewed at every gate.

| ID | Risk | Probability | Impact | Mitigation | Owner/status |
|---|---|---:|---:|---|---|
| R1 | Concurrent requests create confirmed double-booking | Medium | Critical | DB constraints, ordered locks, contention tests, idempotent confirmation | Tech Lead — named owner Open |
| R2 | Partial multi-seat hold | Medium | High | One all-or-none transaction and negative tests | Tech Lead — Open |
| R3 | Duplicate/reordered payment events cause contradictory booking | High | High | Callback idempotency, committed-order precedence (`RULE-022`), reconciliation, replay tests | Tech Lead — policy closed, evidence pending |
| R4 | Late success lacks approved remedy | Medium | High | Closed 2026-08-10: `ADR-015` opens a reconciliation case recording the refund owed | Product Owner — Closed |
| R5 | Cancellation/resale rules ambiguous | Medium | High | Closed 2026-08-10: `ADR-016` fixes the boundary and snapshots the cutoff; `PP-07`/`INV-06` fix resale | Product Owner — Closed |
| R6 | Accessibility defects found late | Medium | High | Standard and incremental manual/automated evidence | Accessibility Owner — Open |
| R7 | Authorization exposes booking/admin data | Medium | High | Policies, cross-user matrix, security review, least privilege | Security Reviewer — Open |
| R8 | Migration failure delays release | Medium | High | Expand/contract review and production-like rehearsal | Tech Lead/Operations — Open |
| R9 | Backup cannot be restored | Medium | Critical | Clean restore and invariant reconciliation | Operations — Open |
| R10 | Part-time capacity causes slippage | High | Medium | Weekly forecast, WIP limit, strict non-goals | PM — Accepted constraint |
| R11 | Scope creep adds deferred features | High | Medium | Baseline/non-goals/change control | PM/Product Owner |
| R12 | Worker/scheduler failure leaves stale state | Medium | High | Monitoring, retries, expiry/reconciliation tests, runbook | Tech Lead/Operations — Open |
| R13 | Undefined load makes performance evidence inconclusive | Medium | Medium | Approve load profile before G3 | Product Owner/Tech Lead — Open |

---

## 17. Governance and change control

### 17.1 Proposed cadence

- Weekly delivery/RAID/decision review chaired by PM.
- Gate reviews G0–G6 using versioned evidence.
- Decision log records status, owner, due date, rationale, and approval.
- Requirements-to-test traceability is maintained throughout delivery.
- P0 risk/defect escalates immediately.

### 17.2 Gate outcomes

Each gate receives one recorded result:

1. **Approved** — all mandatory criteria passed.
2. **Approved with conditions** — remaining items are non-P0, owned, dated, and do not undermine the next gate.
3. **Rejected** — mandatory criteria failed.
4. **Deferred** — evidence or decision authority unavailable.

Silence, elapsed time, code completion, or demonstration alone does not constitute approval.

### 17.3 Change-control procedure

A proposed change to scope, non-goals, invariants, policy, timeline, architecture, or release threshold shall:

1. receive a numbered change request;
2. state business rationale and requested decision date;
3. identify scope/schedule/capacity/architecture/security/accessibility/test/operation impact;
4. identify displaced work if the baseline date remains;
5. receive Technical Lead and QA impact review;
6. receive Product Owner approval for requirement/priority changes;
7. receive Sponsor approval when committed scope/date/capacity/risk changes;
8. update BRD, backlog, traceability, forecast, and RAID after approval.

---

## 18. Open decisions

| ID | Decision | Owner | Required by |
|---|---|---|---|
| D1 | Resolved 2026-08-10: browsing is public; authentication begins at the hold request. Recorded in `ADR-014` and `RULE-010` | Product Owner | Closed |
| D2 | Resolved 2026-08-10 by citation: `PP-02` (maximum eight seats per booking) is Accepted, and `RULE-003`/`RULE-011` scope it to one hold for one showtime | Product Owner | Closed |
| D3 | Resolved 2026-08-10 by citation: `PP-03` fixes five minutes from authoritative creation and `ADR-004` makes PostgreSQL the clock. No rounding arises — expiry is a `timestamptz` comparison | Product Owner + Tech Lead | Closed |
| D4 | Resolved 2026-08-10 by citation: `PP-04` (no extension) is Accepted. Interruption is covered by `ADR-015` — the hold survives payment failure for up to three attempts within its original expiry | Product Owner | Closed |
| D5 | Resolved 2026-08-10 by citation: `PP-11` fixes `MYR` in integer sen, so no rounding arises; the price snapshot is `ADR-005` plus `LOGICAL_ERD.md` §5; tax and fee engines are excluded by `LOGICAL_ERD.md` §8 | Product Owner | Closed |
| D6 | Resolved 2026-08-10: deadline, idempotency identity and callback validation fixed by `ADR-015`; the simulator implements that contract | Product Owner + Tech Lead | Closed |
| D7 | Resolved 2026-08-10: timeout at hold expiry, 24-hour window, `UNRESOLVED` closure that reopens on late success — `ADR-015` | Product Owner + Operations | Closed |
| D8 | Resolved 2026-08-10: PostgreSQL commit order is the sole tiebreak — `ADR-015` | Product Owner + Tech Lead | Closed |
| D9 | Resolved 2026-08-10: hold survives failure; three attempts, third releases — `ADR-015` | Product Owner | Closed |
| D10 | Resolved 2026-08-10: reconciliation case records the remedy owed; no real refund — `ADR-015`, `ADR-017` | Product Owner | Closed |
| D11 | Resolved 2026-08-10: strict inequality against a snapshotted cutoff, default 120 minutes — `ADR-016` | Product Owner | Closed |
| D12 | Resolved: `PP-07` and `RULE-016` already require eligible cancelled seats to return to availability, and `INV-06` releases them in the cancellation transaction itself | Product Owner | Closed |
| D13 | Resolved 2026-08-10: effects, guards and impact preview fixed by `ADR-017` | Product Owner | Closed |
| D14 | **Deferred to Phase 5 by decision, 2026-08-10**, not by oversight. `PP-09` already fixes QR as display-only and not an admission credential; payload, privacy and rotation are settled at the `P5-03` slice, when booking and payment exist to design against | Product Owner + Security | Open, scheduled |
| D15 | Resolved 2026-08-10: `OPERATOR`/`ADMIN` split and cancellation authority — `ADR-017`; hall/seat edit policy after publication — `ADR-019` | Product Owner + Security | Closed |
| D16 | Resolved 2026-08-10: accessible/companion policy and WCAG-oriented P0 journey recorded in `MVP_POLICY_BASELINE.md` | Product Owner + Accessibility | Approved; commercial review required |
| D17 | Resolved 2026-08-10: load, latency, availability, and worker-lag targets recorded in `MVP_POLICY_BASELINE.md` | Product Owner + Tech Lead | Approved engineering baseline |
| D18 | Resolved 2026-08-10: RPO/RTO, backup retention, and restore frequency recorded in `MVP_POLICY_BASELINE.md` | Product Owner + Operations | Approved engineering baseline |
| D19 | Resolved 2026-08-10: data retention and deletion defaults recorded in `MVP_POLICY_BASELINE.md` | Product Owner + Security | Approved portfolio baseline; legal review before commercial use |
| D20 | Resolved 2026-08-10: all roles held by Fatihi, recorded per role in §19. Naming is not approving — Business Acceptance, Technical Recommendation, QA Evidence and Release Authorization remain four separate records against G3, G5 and G6 | Sponsor/Project authority | Closed |

---

## 19. Approval

| Role | Name | Decision | Date | Signature/reference |
|---|---|---|---|---|
| Business Sponsor | Fatihi | Pending |  | Business Go/No-Go, at G6 |
| Product Owner | Fatihi | Approved with conditions | 2026-08-10 | MVP policies PP-01–PP-13 approved (`ADR-009`, `ADR-012`, `ADR-013`); browse boundary resolved (`ADR-014`); `PD-01`–`PD-10` resolved (`ADR-015`, `ADR-016`, `ADR-017`), closing `D6`–`D13` and the MVP portion of `D15`. hall/seat versioning closed (`ADR-019`), companion adjacency closed (`ADR-018`), `D20` closed, `BO-08` closed. `D14` deferred to Phase 5 by decision |
| Project Manager | Fatihi | Pending |  | Gate coordination |
| Business Analyst | Fatihi | Pending |  | Requirements and traceability |
| Technical Lead | Fatihi | Pending |  | **Technical Recommendation** — due at G3, the real-PostgreSQL concurrency gate |
| QA Lead | Fatihi | Pending |  | **QA Evidence** — due at G5, needs the evidence pack to exist first |
| Security Reviewer | Fatihi | Pending |  | Authorization, IDOR, privacy and dependency evidence, at G5 |
| Accessibility Owner | Fatihi | Pending |  | WCAG 2.2 AA critical-journey evidence (`BO-08`), at G5 |
| Operations/Release Owner | Fatihi | Pending |  | **Release Authorization** — due at G6 |

### BRD approval effect

Approval baselines Accepted requirements and any Proposed/Open decisions explicitly resolved in the approval record. Unresolved Proposed/Open items remain unresolved and cannot be silently treated as approved implementation rules.
