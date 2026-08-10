# Seatly Sprint 0 Domain State Models and Transition Specification

**Document ID:** SEATLY-DOMAIN-STATES-001  
**Version:** 1.1  
**Date:** 2026-08-10  
**Status:** Draft for Sprint 0 approval  
**Change note:** v1.1 applies `RC-02`, `RC-06`, and `RC-07` from
[`BASELINE_RECONCILIATION.md`](BASELINE_RECONCILIATION.md). `PD-01`–`PD-10`
remain **Proposed**; nothing in v1.1 approves them.  
**Scope:** Showtime, seat inventory, hold, payment attempt, booking, cancellation, reconciliation, and transactional outbox  
**System of record:** PostgreSQL  
**Related artifacts:** `BRD.md`, `PROJECT_BRIEF.md`, `DECISIONS.md`, `DELIVERY_TIMELINE.md`, `RELEASE_GATES.md`, `MVP_POLICY_BASELINE.md`, `bpmn/seatly-booking-process.bpmn`

## 1. Purpose and evidence boundary

This specification defines the authoritative domain states, allowed transitions, guards, transactional effects, race outcomes, lock ordering, and forbidden transitions required before Seatly implementation begins.

Seatly is greenfield. There is no incumbent state model to preserve.

### 1.1 Status vocabulary

- **Accepted** — already supported by the approved product or architecture baseline.
- **Proposed** — smallest portfolio-MVP default recommended to remove implementation ambiguity; Product Owner approval is still required.
- **Open** — unresolved and must not be silently implemented.

### 1.2 Accepted invariants

1. PostgreSQL is authoritative for showtime, inventory, hold, payment disposition, booking, cancellation, and outbox state.
2. Browser state, timers, queue state, workers, and the simulated provider are not authoritative for seat allocation.
3. A showtime-seat belongs to at most one active confirmed booking.
4. A multi-seat hold and its confirmation are all-or-none.
5. A payment success callback is necessary but not sufficient for confirmation.
6. Hold expiry, payment callback, customer cancellation, and showtime cancellation races are decided by committed PostgreSQL state.
7. Duplicate commands and callback deliveries produce one logical business effect.
8. A late payment success never reclaims inventory or creates a booking.
9. A booking reference and QR linkage exist only for a confirmed booking.
10. Every material state change writes its audit and required outbox records in the same transaction.
11. Outbox delivery is at least once; consumers shall be idempotent.
12. No notification or real refund behavior is implied by an outbox event or reconciliation state.

## 2. Proposed gated defaults

The following defaults are intentionally small and explicit. They remain **Proposed** until recorded in `DECISIONS.md`.

| ID | Proposed default | Rationale |
|---|---|---|
| PD-01 | A payment attempt’s initial callback deadline is the hold’s existing `expires_at`; do not introduce a second customer-facing payment timer for MVP. | Reuses the approved five-minute authoritative hold deadline and avoids conflicting timeout clocks. |
| PD-02 | If no final callback has committed by `hold.expires_at`, set the attempt to `TIMED_OUT`, expire the hold, and open reconciliation. Timeout is not provider failure. | Preserves ambiguous outcomes and safely releases scarce inventory. |
| PD-03 | Keep timed-out/reconciliation attempts inspectable for 24 hours after timeout before automatic operational closure as `UNRESOLVED`. A later callback is still stored after closure but cannot automatically confirm. | Gives the simulator and operator flow a bounded portfolio demonstration window. |
| PD-04 | First valid final provider outcome committed in PostgreSQL wins the normal business effect. Timeout does not count as a final provider outcome. | Deterministic and independent of provider timestamps or delivery order. |
| PD-05 | A later contradictory callback never reverses a committed booking, cancellation, inventory release, or earlier final outcome. It is recorded and opens/updates reconciliation. | Prevents externally supplied ordering from rewriting authoritative state. |
| PD-06 | A valid explicit payment failure immediately releases the active hold in the same transaction, provided the showtime remains on sale. | Minimizes unnecessary inventory blockage. |
| PD-07 | Customer cancellation is allowed only when PostgreSQL `transaction_timestamp() < scheduled_start_at - interval '2 hours'`; equality is ineligible. Instants are stored in UTC. | Gives the approved “until two hours before” rule an exact boundary. |
| PD-08 | For MVP, one authorized `admin` capability may cancel a showtime; a non-empty reason and audit record are mandatory. No separate operator hierarchy or approval chain is introduced. | Smallest authority model consistent with the accepted admin scope. |
| PD-09 | Showtime cancellation releases active holds, blocks unsold seats, prevents new payment initiation and confirmation, marks confirmed bookings `SHOWTIME_CANCELLED`, revokes QR use, and creates reconciliation cases for confirmed or payment-ambiguous journeys. No real refund or notification is performed. | Makes all affected states visible without inventing excluded integrations. |
| PD-10 | Showtime cancellation is terminal in MVP. Rescheduling, reopening, and “uncancel” are forbidden; create a new showtime instead. | Avoids restoring stale holds, inventory, prices, or payment intent. |

Approval of these defaults resolves BRD decisions D7–D10, D13, and the MVP portion of D15. Until approval, implementation slices depending on them remain gated.

## 3. Common state-machine rules

### 3.1 Transition execution

Every authoritative transition shall:

1. begin a PostgreSQL transaction;
2. obtain locks in the order defined in Section 12;
3. use PostgreSQL time, not application or browser time;
4. revalidate all guards after locks are held;
5. perform the complete business mutation or none;
6. increment the affected aggregate/version values;
7. insert audit and required outbox records in the same transaction;
8. commit before reporting success;
9. return the existing result for a duplicate logical command;
10. return a stable conflict or current-state result for a stale command.

A worker wake-up, scheduler tick, HTTP request, or callback receipt is only a request to attempt a transition. It is not proof that the transition remains eligible.

### 3.2 Time boundaries

For an active hold:

```text
active when transaction_timestamp() < expires_at
expired when transaction_timestamp() >= expires_at
```

Therefore, confirmation exactly at `expires_at` is forbidden.

For customer cancellation under proposed PD-07:

```text
eligible when transaction_timestamp()
              < scheduled_start_at - interval '2 hours'
```

The showtime instant is stored in UTC. Venue-facing display and policy explanations use `Asia/Kuala_Lumpur`.

### 3.3 Reason and disposition fields

Do not multiply lifecycle states merely to encode every cause. Use controlled reason fields alongside states:

- `hold.release_reason`
- `inventory.block_reason`
- `booking.cancellation_reason`
- `callback_event.disposition`
- `reconciliation_case.reason`
- `outbox_event.last_error_code`

Reason values shall be enums or constrained codes, not free-form application logic.

## 4. Showtime state model

### 4.1 States

| State | Meaning | Terminal |
|---|---|---:|
| `DRAFT` | Editable showtime not available for sale; no materialized sellable inventory is exposed. | No |
| `PUBLISHED` | Inventory has been materialized and the showtime may be browsed and sold while its start boundary permits. | No |
| `CANCELLED` | Authoritatively cancelled; no new hold, payment initiation, or booking confirmation may commit. | Yes for MVP |

“On sale” is derived, not a separately persisted state:

```text
showtime.status = PUBLISHED
AND transaction_timestamp() < scheduled_start_at
```

A past `PUBLISHED` showtime is not bookable even if a cleanup or completion job has not run.

### 4.2 Transitions

| ID | From | Command/event | To | Guards | Atomic effects |
|---|---|---|---|---|---|
| ST-01 | — | Create showtime | `DRAFT` | Authorized admin; valid movie, venue, hall, start time, price and currency | Insert showtime; audit `SHOWTIME_CREATED` |
| ST-02 | `DRAFT` | Edit showtime | `DRAFT` | Authorized; valid proposed data; no publication has occurred | Update allowed fields/version; audit |
| ST-03 | `DRAFT` | Publish | `PUBLISHED` | Authorized; movie/venue/hall active; fixed layout valid; companion links valid; no conflicting hall schedule; one price and `MYR`; future start | Materialize exactly one inventory row per active physical seat; snapshot seat identity/features and price; outbox `SHOWTIME_PUBLISHED`; audit |
| ST-04 | `PUBLISHED` | Cancel showtime | `CANCELLED` | Authorized under approved authority; non-empty reason; command idempotency valid | Apply Section 10 cancellation effects; outbox `SHOWTIME_CANCELLED`; audit |
| ST-05 | `CANCELLED` | Repeat same cancellation | `CANCELLED` | Same logical command or already cancelled | Return existing cancellation result; no repeated effects |
| ST-06 | `DRAFT` | Delete draft | — | Authorized; never published; no inventory, hold, payment, or booking references | Delete or soft-delete according to persistence convention; audit |

### 4.3 Forbidden showtime transitions

- `PUBLISHED -> DRAFT`
- `CANCELLED -> PUBLISHED`
- `CANCELLED -> DRAFT`
- physical hall/seat reassignment after publication
- structural hall edits that change a published showtime’s materialized seat meaning
- changing the price snapshot used by an existing hold, payment, or booking
- deleting a published or cancelled showtime with historical references
- confirming, creating a hold, or initiating payment when not authoritatively on sale

## 5. Seat inventory state model

Inventory identity is `(showtime_id, seat_id)`. Every row represents one physical seat in one published showtime.

### 5.1 States

| State | Required linkage | Meaning |
|---|---|---|
| `AVAILABLE` | No `hold_id`; no `booking_id` | Eligible for acquisition when the showtime is on sale |
| `HELD` | `hold_id`, `hold_expires_at` | Exclusively held by one active hold |
| `CONFIRMED` | `booking_id`; `hold_id` cleared | Allocated to one confirmed booking |
| `BLOCKED` | `block_reason`; no hold or booking owner | Not sellable due to controlled operator/showtime policy |

Linkage columns name the **current** owner only (`RC-02`). Confirmation clears
`hold_id`; blocking clears both. History is recoverable from `hold_items`,
`booking_items`, `bookings.hold_id`, and `audit_events` — never from a stale
pointer left on the inventory row.

A cancelled showtime is independently unbookable even if a historical `CONFIRMED` row has not been normalized by maintenance.

### 5.2 Transitions

| ID | From | Trigger | To | Guards | Atomic effects |
|---|---|---|---|---|---|
| INV-01 | — | Publish showtime | `AVAILABLE` | Active physical seat included in valid fixed hall layout | Insert row with immutable seat/price snapshot and version |
| INV-02 | `AVAILABLE` | Acquire hold | `HELD` | Showtime on sale; seat requested once; pairing/declaration rules pass; all requested rows available after lock | Set common `hold_id`/`hold_expires_at`; increment version |
| INV-03 | `HELD` | Confirm booking | `CONFIRMED` | Linked hold active and unexpired; row covers exactly one hold item; valid payment success; showtime still on sale | Clear active hold linkage; set `booking_id`; increment version |
| INV-04 | `HELD` | Hold expires | `AVAILABLE` | Linked hold active and `db_now >= expires_at`; showtime remains on sale | Clear hold linkage/expiry; increment version |
| INV-05 | `HELD` | Explicit release/payment failure | `AVAILABLE` | Linked active hold; showtime remains on sale | Clear hold linkage/expiry; increment version |
| INV-06 | `CONFIRMED` | Eligible customer cancellation | `AVAILABLE` | Whole booking cancellation eligible; showtime remains on sale | Remove active allocation while retaining booking-item history; increment version |
| INV-07 | `AVAILABLE` | Operator block | `BLOCKED` | Authorized; showtime not cancelled; no active owner | Set block reason; increment version; audit/outbox |
| INV-08 | `BLOCKED` | Operator unblock | `AVAILABLE` | Authorized; original block removable; showtime remains on sale | Clear block reason; increment version |
| INV-09 | `AVAILABLE` | Showtime cancellation | `BLOCKED` | Showtime cancellation owns showtime lock | Set `SHOWTIME_CANCELLED` block reason |
| INV-10 | `HELD` | Showtime cancellation | `BLOCKED` | Same transaction releases the linked hold | Clear hold linkage; set `SHOWTIME_CANCELLED` block reason |
| INV-11 | `CONFIRMED` | Showtime cancellation remedy | `BLOCKED` | Booking transitions to `SHOWTIME_CANCELLED` in same transaction | Clear `booking_id`; set block reason `SHOWTIME_CANCELLED`; `booking_items` retains the seat snapshot |

### 5.3 Accessible/companion invariants

1. A companion seat may transition to `HELD` or `CONFIRMED` only when its linked accessible seat makes the same transition for the same hold/booking.
2. The accessible seat may transition without its companion.
3. Releasing, expiring, or cancelling an accessible seat also releases its linked companion when both belong to the same hold/booking.
4. No partial companion pair may survive a failed multi-seat transaction.
5. Confirmation revalidates the declaration, policy version, and pair membership; stale client state is insufficient.

### 5.4 Forbidden inventory transitions

- `AVAILABLE -> CONFIRMED` without an active hold
- `HELD -> CONFIRMED` for only part of a hold
- `CONFIRMED -> HELD`
- `BLOCKED -> HELD` or `BLOCKED -> CONFIRMED`
- replacing another customer’s `hold_id` in place
- modifying a row based only on the browser countdown or worker schedule
- reclaiming a seat for a late payment success
- partial release or cancellation of individual booking seats in MVP
- unblocking a `SHOWTIME_CANCELLED` row while the showtime remains cancelled

## 6. Hold state model

### 6.1 States

| State | Meaning | Terminal |
|---|---|---:|
| `ACTIVE` | All hold items are exclusively held until the authoritative expiry instant. | No |
| `CONFIRMED` | The entire hold was consumed into exactly one confirmed booking. | Yes |
| `EXPIRED` | The authoritative deadline passed before confirmation. | Yes |
| `RELEASED` | The hold was deliberately released before expiry. | Yes |

Recommended `release_reason` values:

```text
CUSTOMER_ABANDONED
PAYMENT_FAILED          # Proposed PD-06
SHOWTIME_CANCELLED      # Proposed PD-09
ADMINISTRATIVE_RELEASE
```

### 6.2 Hold creation invariants

A successful hold shall contain:

- unique hold ID;
- authenticated owner ID;
- one showtime ID;
- between 1 and 8 unique requested seats;
- all required companion-seat relationships;
- `created_at` from PostgreSQL;
- `expires_at = created_at + interval '5 minutes'`;
- immutable seat and price snapshot;
- amount in integer sen and currency `MYR`;
- policy/declaration version where applicable;
- state `ACTIVE`.

No hold record or hold item shall survive an unsuccessful all-or-none acquisition.

### 6.3 Transitions

| ID | From | Trigger | To | Guards | Atomic effects |
|---|---|---|---|---|---|
| HLD-01 | — | Acquire seats | `ACTIVE` | Authenticated owner; showtime on sale; 1–8 unique seats; every seat valid/available after locks; accessible rules pass; idempotency key valid | Create hold/items/snapshot; inventory `AVAILABLE -> HELD`; outbox `HOLD_CREATED`; audit |
| HLD-02 | `ACTIVE` | Confirm booking | `CONFIRMED` | `db_now < expires_at`; showtime on sale; valid effective payment success; exact inventory coverage; no cancellation has committed | Create booking atomically; all inventory `HELD -> CONFIRMED`; outbox; audit |
| HLD-03 | `ACTIVE` | Expiry detected inline or by worker | `EXPIRED` | `db_now >= expires_at`; no booking confirmation committed | Release all held inventory to `AVAILABLE` or `BLOCKED` according to showtime state; outbox `HOLD_EXPIRED`; audit |
| HLD-04 | `ACTIVE` | Customer abandons/release command | `RELEASED` | Owner authorized; no confirmation; showtime/inventory revalidated | Release all seats; reason `CUSTOMER_ABANDONED`; outbox/audit |
| HLD-05 | `ACTIVE` | Valid explicit payment failure | `RELEASED` | Proposed PD-06; failure wins transition before confirmation | Release all seats; reason `PAYMENT_FAILED`; payment `FAILED`; outbox/audit |
| HLD-06 | `ACTIVE` | Showtime cancellation | `RELEASED` | Cancellation has showtime lock | Release/block all seats; reason `SHOWTIME_CANCELLED`; outbox/audit |
| HLD-07 | Any terminal | Repeat expiry/release/confirm command | Unchanged | Same logical command or state already terminal | Return current state/result; no repeated effects |

### 6.4 Forbidden hold transitions

- any terminal state back to `ACTIVE`
- `EXPIRED -> CONFIRMED`
- `RELEASED -> CONFIRMED`
- extending or replacing `expires_at`
- adding, removing, or swapping seats after creation
- changing owner, showtime, price, amount, currency, or policy snapshot
- confirming at or after `expires_at`
- partially confirming or partially releasing the hold
- creating more than one booking from one hold

## 7. Payment attempt state model

### 7.1 Separation of records

Use separate records for:

1. **Payment attempt** — one logical checkout attempt and its effective state.
2. **Callback event** — every received provider event and its validation/disposition.
3. **Reconciliation case** — operational visibility for ambiguous, contradictory, late, or paid-but-unbooked outcomes.

A callback record shall never be discarded because it cannot change the payment attempt.

### 7.2 Payment attempt states

| State | Meaning | Final provider outcome? |
|---|---|---:|
| `CREATED` | Attempt and correlation/idempotency identity exist; request not yet durably submitted. | No |
| `PENDING` | Payment initiation is durably queued/sent; awaiting final callback. | No |
| `SUCCEEDED` | Valid success won and a booking confirmation committed. | Yes |
| `FAILED` | Valid explicit failure won; no booking was created. | Yes |
| `TIMED_OUT` | No final callback committed by the authoritative deadline; outcome remains unknown. | No |
| `RECONCILIATION_REQUIRED` | Attempt is ambiguous, contradictory, cancelled-showtime affected, or otherwise requires explicit inspection. | No |
| `LATE_SUCCESS_REFUND_REQUIRED` | Valid success arrived after confirmation became impossible; no booking was created or reclaimed. | Yes for automatic booking |
| `UNRESOLVED` | Proposed operational closure reached without an authoritative provider outcome. | Operational terminal; later callbacks still recorded |

`REFUND_REQUIRED` is a classification only. Real refund execution is out of scope.

### 7.3 Callback dispositions

```text
RECEIVED                        # inbox row inserted, not yet classified
ACCEPTED_SUCCESS
ACCEPTED_FAILURE
DUPLICATE_SAME_PAYLOAD
DUPLICATE_PAYLOAD_MISMATCH
INVALID_SIGNATURE
MALFORMED
UNCORRELATED
AMOUNT_MISMATCH
CURRENCY_MISMATCH
CONTRADICTS_COMMITTED_SUCCESS
CONTRADICTS_COMMITTED_FAILURE
LATE_SUCCESS_NO_CONFIRMATION
LATE_FAILURE_RECORDED
NON_FINAL_ACKNOWLEDGEMENT
IGNORED_ALREADY_TERMINAL
```

`RECEIVED` is the value written when the inbox row is inserted, before the domain
transaction classifies it (`RC-07`). It is the only non-terminal disposition and
the only one permitted to lack a processing timestamp. These seventeen values are
the canonical vocabulary; `LOGICAL_ERD.md` §2.5 stores them unchanged.

The unique provider event ID prevents repeated processing. Reusing the same event ID with a different payload is a security/consistency exception, not a normal duplicate.

### 7.4 Transitions

| ID | From | Trigger | To | Guards | Atomic effects |
|---|---|---|---|---|---|
| PAY-01 | — | Initiate checkout | `CREATED` | Owned active hold; `db_now < expires_at`; showtime on sale; snapshot valid; scoped idempotency valid | Create/recover one attempt bound to hold/snapshot/amount/currency; create payment-initiation outbox event |
| PAY-02 | `CREATED` | Initiation durably queued/sent | `PENDING` | Same logical attempt; no terminal outcome already committed | Record submission/correlation timestamps; no booking effect |
| PAY-03 | `PENDING` | Valid success callback | `SUCCEEDED` | First valid final outcome; hold active/unexpired; showtime on sale; exact amount/currency; inventory still linked to hold | In one transaction: callback accepted, hold confirmed, booking/reference/QR created, inventory confirmed, outbox/audit written |
| PAY-04 | `PENDING` | Valid explicit failure | `FAILED` | First valid final outcome | Record failure; under PD-06 release active hold/inventory in same transaction; outbox/audit |
| PAY-05 | `PENDING` | Deadline reached without final outcome | `TIMED_OUT` | Proposed PD-01/02; `db_now >= timeout_at`; no final callback committed | Expire hold if still active; release/block inventory; create reconciliation case; outbox/audit |
| PAY-06 | `TIMED_OUT` | Valid late success | `LATE_SUCCESS_REFUND_REQUIRED` | No booking committed; hold expired/released or showtime unbookable | Record paid-but-unbooked result; never reacquire inventory; open/update reconciliation |
| PAY-07 | `TIMED_OUT` | Valid late failure | `FAILED` | No contradictory earlier provider final; no booking | Record final failure; resolve case as no booking/no simulated refund |
| PAY-08 | `CREATED`/`PENDING`/`TIMED_OUT` | Showtime cancellation | `RECONCILIATION_REQUIRED` | No booking confirmation committed before cancellation | Mark confirmation blocked by showtime; release hold; create/update case |
| PAY-09 | `RECONCILIATION_REQUIRED` | Valid success | `LATE_SUCCESS_REFUND_REQUIRED` | Confirmation is blocked by expiry/cancellation/release | No inventory or booking mutation; update case |
| PAY-10 | `RECONCILIATION_REQUIRED` | Valid failure | `FAILED` | No committed booking or prior accepted success | Record failure; resolve or update case |
| PAY-11 | `TIMED_OUT`/`RECONCILIATION_REQUIRED` | Reconciliation window closes | `UNRESOLVED` | Proposed PD-03; no accepted final result | Preserve inspectable history; alert/report unresolved case |
| PAY-12 | Any | Duplicate identical callback | Unchanged | Same provider event ID and payload fingerprint, or semantically duplicate final outcome | Store/return duplicate disposition; no repeated business effect |
| PAY-13 | `SUCCEEDED` | Later failure callback | `SUCCEEDED` | Booking already committed | Record contradiction; do not cancel booking; create/update reconciliation |
| PAY-14 | `FAILED` | Later success callback | `LATE_SUCCESS_REFUND_REQUIRED` | Hold released or no longer confirmable | Record contradiction/late success; no booking; create/update reconciliation |
| PAY-15 | Any | Invalid or untrusted callback | Unchanged | Signature, structure, correlation, amount, or currency validation fails | Store rejected callback disposition; no payment/booking/inventory mutation; audit/security metric |

### 7.5 Proposed contradictory-outcome precedence

Under PD-04/05:

1. A committed `SUCCEEDED` booking effect is irrevocable by later provider messages.
2. The first authenticated, structurally valid, correctly correlated final provider callback committed by Seatly wins the normal provider outcome.
3. Provider timestamps, sequence claims, and delivery order outside Seatly do not override PostgreSQL commit order.
4. Timeout is an absence-of-message state, not a final provider outcome.
5. An identical later outcome is a duplicate.
6. A contradictory later failure cannot cancel a committed booking.
7. A contradictory or late success after failure, expiry, release, or showtime cancellation becomes `LATE_SUCCESS_REFUND_REQUIRED`.
8. Any contradiction creates or updates reconciliation and remains visible to operations.

### 7.6 Forbidden payment transitions

- `FAILED -> SUCCEEDED`
- `TIMED_OUT -> SUCCEEDED`
- `RECONCILIATION_REQUIRED -> SUCCEEDED`
- `LATE_SUCCESS_REFUND_REQUIRED -> SUCCEEDED`
- `UNRESOLVED -> SUCCEEDED`
- `SUCCEEDED -> FAILED`
- creating a second booking for the same attempt, hold, or provider success
- treating acknowledgement as success
- treating timeout as provider failure
- changing amount, currency, hold, owner, or snapshot after attempt creation
- accepting a callback with invalid authentication/correlation/binding
- reclaiming inventory during reconciliation

The apparent `FAILED -> LATE_SUCCESS_REFUND_REQUIRED` transition is exception classification, not reversal to successful booking.

## 8. Booking state model

A booking is created only when confirmation commits. There is no placeholder `PENDING` booking; pending checkout is represented by the hold and payment attempt.

### 8.1 States

| State | Meaning | Active confirmed allocation? |
|---|---|---:|
| `CONFIRMED` | Payment success and inventory confirmation committed atomically. | Yes |
| `CUSTOMER_CANCELLED` | Owner completed an eligible whole-booking cancellation. | No |
| `ADMIN_CANCELLED` | Authorized booking-specific administrative cancellation, if enabled. | No |
| `SHOWTIME_CANCELLED` | The underlying showtime was authoritatively cancelled. | No |

### 8.2 Creation invariants

A confirmed booking shall have:

- unique booking ID and opaque reference;
- unique `hold_id`;
- unique effective `payment_attempt_id`;
- owner and showtime;
- exact complete seat set;
- immutable movie, venue, hall, showtime, seat-label, price, amount, and currency snapshots;
- high-entropy QR linkage with hash-only token persistence;
- state `CONFIRMED`;
- one booking item for every hold item;
- matching `CONFIRMED` inventory ownership at commit.

### 8.3 Transitions

| ID | From | Trigger | To | Guards | Atomic effects |
|---|---|---|---|---|---|
| BKG-01 | — | Confirm from valid payment success | `CONFIRMED` | All PAY-03 guards; unique hold/payment; all seats still held | Create booking/items/reference/QR linkage; hold `CONFIRMED`; inventory all `CONFIRMED`; payment `SUCCEEDED`; outbox/audit |
| BKG-02 | `CONFIRMED` | Customer cancellation | `CUSTOMER_CANCELLED` | Owner; whole booking; proposed exact cutoff passes; showtime on sale; no earlier cancellation | Revoke QR; release all seats to `AVAILABLE`; create applied cancellation; outbox/audit |
| BKG-03 | `CONFIRMED` | Admin booking cancellation | `ADMIN_CANCELLED` | Authorized capability and reason; policy approved | Revoke QR; release or block inventory according to showtime; reconciliation if payment remedy needed; outbox/audit |
| BKG-04 | `CONFIRMED` | Showtime cancellation | `SHOWTIME_CANCELLED` | Showtime cancellation wins showtime lock | Revoke QR; block inventory; create reconciliation case under PD-09; outbox/audit |
| BKG-05 | Any cancelled state | Repeat cancellation | Unchanged | Same logical request or already terminal | Return existing result/status; no repeated release or outbox effect |

### 8.4 Forbidden booking transitions

- cancelled state back to `CONFIRMED`
- `CUSTOMER_CANCELLED <-> ADMIN_CANCELLED`
- any cancelled state to `SHOWTIME_CANCELLED` unless a reporting projection needs both causes; preserve later showtime cancellation as audit rather than rewriting the original cancellation cause
- partial seat cancellation or seat swap
- owner, showtime, seat, amount, currency, or snapshot mutation after creation
- booking creation before authoritative commit
- reference or QR issuance for a hold/payment without a booking
- deleting a booking with business history

## 9. Cancellation state model

Cancellation records represent idempotent decisions and preserve denied requests without mutating the target aggregate.

All three types share one `cancellations` table (`RC-06`), with a nullable
`booking_id` and a nullable `showtime_id` selected by `type`. The machinery below
is identical whichever aggregate is targeted, so it is not duplicated per target.

### 9.1 Types and states

Types:

```text
CUSTOMER_BOOKING
ADMIN_BOOKING
SHOWTIME
```

States:

| State | Meaning |
|---|---|
| `REQUESTED` | Authorized command identity was accepted for evaluation. |
| `APPLIED` | Target transition committed exactly once. |
| `REJECTED` | No target mutation occurred; stable reason recorded. |

Recommended rejection reasons:

```text
NOT_OWNER
UNAUTHORIZED
NOT_FOUND_OR_NOT_DISCLOSED
ALREADY_CANCELLED
SHOWTIME_NOT_ON_SALE
CUTOFF_REACHED
BOOKING_NOT_CONFIRMED
INVALID_REASON
POLICY_NOT_ENABLED
```

Authorization failures may use a generic external response while recording a more precise protected audit reason.

### 9.2 Transitions

| ID | From | Trigger | To | Guards/effects |
|---|---|---|---|---|
| CAN-01 | — | Receive cancellation command | `REQUESTED` | Create or recover scoped idempotency record; bind actor, target, type, reason and request fingerprint |
| CAN-02 | `REQUESTED` | Eligible target transition commits | `APPLIED` | Apply booking/showtime/hold/inventory effects in same transaction; record decision time and resulting state; outbox/audit |
| CAN-03 | `REQUESTED` | Eligibility or authorization fails | `REJECTED` | Record stable rejection reason; no target-state or inventory mutation |
| CAN-04 | `APPLIED` | Same command retried | `APPLIED` | Return original result; no repeated effects |
| CAN-05 | `REJECTED` | Same command retried | `REJECTED` | Return original rejection; reevaluation requires a new idempotency key |

A reused idempotency key with a different target, cancellation type, or payload fingerprint shall be rejected as an idempotency conflict.

## 10. Showtime-cancellation transaction

The following is **Proposed** under PD-08/09 and remains gated.

### 10.1 Guards

- caller has `showtimes.cancel`;
- showtime exists and is `PUBLISHED`;
- non-empty controlled reason is supplied;
- command idempotency is valid;
- showtime row is locked before affected inventory or aggregates.

### 10.2 Atomic effects

Within one transaction:

1. transition showtime `PUBLISHED -> CANCELLED`;
2. prevent every later hold, payment-initiation, and confirmation transaction through showtime-state revalidation;
3. transition active holds to `RELEASED(reason=SHOWTIME_CANCELLED)`;
4. transition `AVAILABLE` and released `HELD` inventory rows to `BLOCKED(reason=SHOWTIME_CANCELLED)`;
5. transition confirmed bookings to `SHOWTIME_CANCELLED`;
6. transition their inventory to the non-sellable cancelled-showtime representation;
7. revoke affected QR linkage;
8. transition payment-ambiguous attempts to `RECONCILIATION_REQUIRED`;
9. leave already accepted `FAILED` attempts failed;
10. preserve already committed payment success and create a reconciliation case for each affected confirmed booking;
11. create one cancellation record and per-aggregate audit records;
12. create deduplicated outbox events required for internal projections or customer-facing status;
13. commit before acknowledging cancellation.

No email, SMS, funds movement, or real refund is performed.

### 10.3 Large-showtime implementation note

A single transaction is the simplest correctness baseline. If measured row volume makes it unsuitable, the showtime state transition must still commit first and block new confirmations. Batched remediation may then follow through idempotent outbox work, but the temporary state and operational visibility must be explicitly redesigned and approved rather than introduced silently.

## 11. Transactional outbox state model

### 11.1 States

| State | Meaning |
|---|---|
| `PENDING` | Event committed with domain mutation and is awaiting delivery. |
| `DELIVERING` | A worker owns a time-limited delivery lease. |
| `DELIVERED` | Required destination acknowledged the event. |
| `DEAD_LETTER` | Automatic retry policy exhausted or event is non-retryable; operator action required. |

Minimum attributes:

```text
id
event_id                 # globally unique
aggregate_type
aggregate_id
aggregate_version
event_type
payload_version
payload                  # minimum necessary, no secrets
correlation_id
causation_id
status
attempt_count
available_at
lease_owner
lease_expires_at
delivered_at
last_error_code
created_at
```

Recommended uniqueness:

```text
UNIQUE(event_id)
UNIQUE(aggregate_type, aggregate_id, aggregate_version, event_type)
```

### 11.2 Transitions

| ID | From | Trigger | To | Guards | Effects |
|---|---|---|---|---|---|
| OBX-01 | — | Material domain transition | `PENDING` | Domain mutation is in same transaction | Insert outbox event before domain transaction commit |
| OBX-02 | `PENDING` | Worker claims due event | `DELIVERING` | `available_at <= db_now`; row claim uses `FOR UPDATE SKIP LOCKED`; no active lease | Set lease owner/expiry; increment attempt count |
| OBX-03 | `DELIVERING` | Destination acknowledges | `DELIVERED` | Current lease owner; matching event ID | Record delivery time; clear lease |
| OBX-04 | `DELIVERING` | Retryable failure | `PENDING` | Current lease owner; attempts below limit | Record redacted error; set bounded backoff/`available_at`; clear lease |
| OBX-05 | `DELIVERING` | Lease expires | `PENDING` | No acknowledgement; lease deadline passed | Make event claimable again; delivery may have occurred, so consumer must deduplicate |
| OBX-06 | `DELIVERING` | Non-retryable/exhausted failure | `DEAD_LETTER` | Error classification or retry limit reached | Record error; emit metric/alert; retain event |
| OBX-07 | `DEAD_LETTER` | Authorized replay | `PENDING` | Cause remediated; replay audited | Clear lease; set next availability; preserve event ID |
| OBX-08 | `DELIVERED` | Duplicate worker execution | `DELIVERED` | Event already delivered | No repeated domain effect |

### 11.3 Outbox rules

1. Never publish before the domain transaction commits.
2. A domain rollback also rolls back its outbox insertion.
3. Do not claim exactly-once transport; guarantee one committed event identity and idempotent consumption.
4. Delivery order is guaranteed only where explicitly enforced by aggregate version.
5. A consumer receiving version `n+1` before `n` must defer, rebuild from authoritative state, or apply an approved monotonic projection rule.
6. A dead-letter event remains visible and is never silently deleted.
7. Payloads shall not contain passwords, HMAC secrets, raw QR tokens, callback bodies, medical data, or unnecessary PII.
8. Reconciliation and status retrieval must read authoritative tables, not infer state solely from outbox delivery.

## 12. Lock ordering and transaction protocol

### 12.1 Mandatory lock order

All competing domain actions shall use this order:

1. **Showtime row**, ordered by `showtime.id`.
2. **Showtime-seat inventory rows**, ordered by `seat_id`.
3. **Hold rows**, ordered by `hold.id`.
4. **Payment attempt rows**, ordered by `payment_attempt.id`.
5. **Booking rows**, ordered by `booking.id`.
6. **Cancellation/reconciliation rows**, ordered by ID.
7. Insert audit and outbox rows after the authoritative state decision.

Where an action begins with only a booking, hold, or payment ID, it may first read identifiers without a lock, then acquire the showtime and remaining rows in the mandatory order and revalidate all previously read data.

Callback inbox insertion may occur before aggregate processing through a unique provider-event constraint, but callback-driven domain mutations shall still follow the lock order.

### 12.2 Hold acquisition protocol

```text
Normalize and reject duplicate seat IDs
→ sort seat IDs
→ begin transaction
→ lock showtime
→ reject unless authoritatively on sale
→ lock requested showtime_seat rows in seat_id order
→ reclaim linked expired holds inline where eligible
→ re-read and validate every requested row
→ validate seat count and accessible/companion rules
→ fail all if any row is unavailable
→ create hold, items and immutable price snapshot
→ update every row AVAILABLE → HELD
→ insert audit and outbox records
→ commit
```

### 12.3 Confirmation protocol

```text
Receive and uniquely record callback
→ begin domain transaction
→ resolve showtime/seat IDs without trusting stale state
→ lock showtime
→ lock all hold inventory rows in seat_id order
→ lock hold
→ lock payment attempt
→ revalidate callback, showtime, hold, expiry, ownership,
  amount, currency, exact seat coverage and existing booking
→ if all guards pass:
     payment → SUCCEEDED
     hold → CONFIRMED
     inventory → CONFIRMED
     create booking/items/reference/QR linkage
     insert audit/outbox
→ otherwise classify late/contradictory/invalid outcome
→ commit
```

### 12.4 Customer cancellation protocol

```text
Resolve booking/showtime/seat IDs
→ begin transaction
→ lock showtime
→ lock booking inventory rows in seat_id order
→ lock booking
→ revalidate owner, booking state, showtime state and cutoff using DB time
→ booking → CUSTOMER_CANCELLED
→ inventory → AVAILABLE only while showtime remains on sale
→ revoke QR linkage
→ cancellation → APPLIED
→ insert audit/outbox
→ commit
```

### 12.5 Deadlock behavior

- Deterministic ordering is mandatory, but PostgreSQL may still detect deadlocks.
- Retry only the whole transaction, never a partial mutation.
- Use bounded retries with jitter.
- Preserve the original command/callback identity and correlation ID.
- After retry exhaustion, return a retrievable non-success result and expose the incident operationally.
- A deadlock retry must not create another hold, payment attempt, booking, cancellation, or outbox identity.

## 13. Race and replay behavior

| Race/scenario | Required authoritative outcome |
|---|---|
| Two customers request the same seat | Showtime and ordered inventory locks serialize contenders; at most one complete hold commits. |
| Overlapping multi-seat groups | Each transaction acquires all requested rows or none; no loser retains a partial hold. |
| Expiry worker is delayed | New hold and confirmation paths detect expiry inline using PostgreSQL time; worker timing is not correctness-critical. |
| Confirmation just before expiry | If confirmation obtains locks and validates `db_now < expires_at`, it may commit; later expiry observes `CONFIRMED` and does nothing. |
| Expiry at/before confirmation boundary | If expiry/reallocation commits first, confirmation cannot pass; success becomes late/reconciliation outcome. |
| Callback and showtime cancellation | Both lock showtime first. Confirmation may commit only if it validates before cancellation commits. After cancellation obtains and commits the showtime lock, no later confirmation may commit. |
| Customer cancellation and showtime cancellation | Showtimes and inventory serialize both. The first valid cancellation transition determines the booking’s primary cancellation state; the later event is audited without reopening inventory. |
| Customer cancellation and duplicate success | Confirmed booking may be cancelled once; duplicate success cannot recreate or reactivate it. |
| Success callback repeated | Unique provider event/callback disposition plus unique booking by hold/payment produces one booking effect. |
| Same callback ID, changed payload | Reject as payload mismatch/security exception; no domain mutation. |
| Failure followed by success | Under PD-04/05, failure releases the hold; later success becomes `LATE_SUCCESS_REFUND_REQUIRED`, never a booking. |
| Success followed by failure | Committed booking remains confirmed; later failure is contradictory and opens reconciliation. |
| Timeout followed by success | Timeout did not prove failure, but inventory is no longer reserved; classify late success and never reclaim a seat. |
| Callback received before client sees pending response | Callback may commit first; checkout/status response returns current authoritative booking result. |
| Response lost after hold or booking commit | Same scoped idempotency key returns the existing hold/booking/reference without repeating effects. |
| Outbox worker crashes after destination receives event but before local acknowledgement | Lease expires and event is redelivered with the same event ID; consumer deduplicates. |
| Restore replays callbacks or outbox events | Unique identities and state guards preserve one logical effect; mismatches appear in reconciliation. |
| Stale seat-map poll arrives after a newer poll | Client ignores lower inventory version; write path remains authoritative regardless. |

## 14. Database backstops

The schema shall include equivalent protections for:

- primary/unique `(showtime_id, seat_id)`;
- unique hold item `(hold_id, seat_id)`;
- unique booking item `(booking_id, seat_id)`;
- unique booking by `hold_id`;
- unique booking by effective `payment_attempt_id`;
- unique provider callback `provider_event_id`;
- unique scoped idempotency key;
- unique outbox `event_id`;
- non-negative integer money and ISO currency;
- `expires_at = created_at + five minutes` or an equivalent immutable validated value;
- state-dependent hold/inventory/booking linkage checks;
- at most one `APPLIED` cancellation per booking, and per showtime;
- `HELD` inventory requiring hold and expiry linkage;
- `CONFIRMED` inventory requiring booking linkage and a cleared hold pointer;
- `AVAILABLE` inventory having no hold or booking owner;
- immutable booking and price snapshots;
- unique companion links and correct seat classifications;
- optimistic `version` values for poll freshness, without replacing pessimistic write locks.

Database constraints are final backstops, not substitutes for explicit transition guards and stable domain errors.

## 15. Required forbidden-transition handling

An attempted forbidden transition shall:

1. make no target-state, inventory, booking, or payment mutation;
2. make no success outbox event;
3. return the current authoritative state and stable reason where disclosure is authorized;
4. record rejected callback/cancellation/security dispositions when required;
5. avoid turning normal stale-state conflicts into generic server errors;
6. preserve idempotent response semantics on retry.

Suggested result codes:

```text
SHOWTIME_NOT_ON_SALE
SEAT_CONFLICT
HOLD_NOT_ACTIVE
HOLD_EXPIRED
HOLD_NOT_OWNED
PAYMENT_ALREADY_FINAL
PAYMENT_BINDING_INVALID
BOOKING_ALREADY_EXISTS
BOOKING_NOT_CANCELLABLE
CANCELLATION_CUTOFF_REACHED
SHOWTIME_ALREADY_CANCELLED
IDEMPOTENCY_KEY_REUSED
CALLBACK_REJECTED
RECONCILIATION_REQUIRED
```

## 16. Traceability

| Specification area | Primary source trace |
|---|---|
| PostgreSQL authority and lock protocol | ADR-004; BR-02/03; RULE-001–005; FR-007–010; NFR-001 |
| Materialized inventory | ADR-005; PROJECT_BRIEF §§10–11; FR-004/007/038/041 |
| Five-minute non-extendable hold | PP-03/04; RULE-012/013; FR-010/011/014/015 |
| Eight-seat all-or-none acquisition | PP-02; RULE-003/011; FR-006/008/009; AC-001/002/004 |
| Accessible/companion atomicity | PP-10; `MVP_POLICY_BASELINE.md` §1 |
| Payment attempt and callback validation | ADR-008; FR-016–021; RULE-006/019; AC-005/006/010/017 |
| Timeout is not failure | FR-026; RULE-023; EX-05; AC-008 |
| Late success never reclaims seats | PP-08; RULE-018; FR-027; EX-08; AC-007 |
| Booking confirmation and QR | BR-07; FR-022–024; RULE-005/007; AC-005/018 |
| Customer cancellation | PP-06/07; RULE-015/016; FR-032–035; AC-013 |
| Showtime cancellation race | RULE-020/021; FR-036/040/041; EX-10; AC-011 |
| Transactional outbox | ADR-006; FR-043; NFR-002/006/007/009; release-gate worker/outbox scenarios |
| Idempotency and lost-response recovery | FR-017/020/028/029; RULE-006; EX-06/09/13/15; AC-006/009 |
| Lock/race release evidence | `RELEASE_GATES.md` P0 invariants and adversarial scenarios |
| Proposed payment defaults | BRD D7–D10; RULE-022–025; ADR remaining decisions |
| Proposed showtime remedy/authority | BRD D10/D13/D15; DELIVERY_TIMELINE Sprint 6 |

## 17. Sprint 0 approval checklist

Sprint 0 may exit only when:

- [ ] Every state enum and terminal/non-terminal meaning is approved.
- [ ] PD-01 through PD-10 are approved, amended, or explicitly deferred with an implementation gate.
- [ ] Payment timeout and reconciliation closure are recorded in `DECISIONS.md`.
- [ ] Contradictory callback precedence is recorded in `DECISIONS.md`.
- [ ] Explicit-failure hold treatment is recorded in `DECISIONS.md`.
- [ ] Showtime-cancellation effects and authority are recorded in `DECISIONS.md`.
- [ ] Exact customer cancellation boundary is approved.
- [ ] Database constraints and lock order are reflected in the initial ERD.
- [ ] State transitions map to named Laravel Actions rather than controllers, observers, or Vue components.
- [ ] Unit/property tests cover every allowed and forbidden transition.
- [ ] Real-PostgreSQL tests are planned for hold, expiry, confirmation, cancellation, callback, and outbox races.
- [ ] No implementation treats provider acknowledgement, timeout, UI state, or worker timing as authoritative.
- [ ] No excluded real-refund or notification behavior is implied.

---

### Completion summary

- Designed authoritative state models for showtime, inventory, hold, payment attempt, booking, cancellation, reconciliation, and transactional outbox.
- Defined guards, atomic side effects, forbidden transitions, deterministic lock ordering, replay behavior, and race precedence.
- Separated approved requirements from ten clearly labeled **Proposed** portfolio defaults for still-gated payment and showtime-cancellation policy.
- Added requirement/decision traceability and a Sprint 0 approval checklist.
- **v1.1, 2026-08-10:** applied `RC-02` (inventory linkage names the current owner
  only, and `INV-11` states its effect unconditionally), `RC-06` (one
  `cancellations` table for all three types), and `RC-07` (`RECEIVED` added as the
  pre-classification disposition). Cancellation uniqueness added to §14.
- **Issues found:** Payment timeout, contradictory-result precedence, explicit-failure hold release, exact cancellation boundary, showtime-cancellation remedies, and cancellation authority remain unapproved and block the relevant implementation slices.
