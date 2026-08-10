# Seatly Project Brief

**Version:** 0.1  
**Date:** 2026-08-09  
**Project type:** Greenfield  
**Council verdict:** Conditional Go

## 1. Product summary

Seatly is a cinema seat reservation and booking web application. A customer browses movies and showtimes, selects one or more seats, obtains a temporary server-side hold, completes a simulated asynchronous payment, and receives a booking reference and QR ticket.

The project is intentionally cinema-specific. It is not a generic reservation engine.

### One-line pitch

> A concurrency-safe cinema booking platform built with Laravel and PostgreSQL, featuring atomic multi-seat holds, idempotent payment processing, accessible seat selection, and evidence-based production release gates.

## 2. Problem statement

Cinema booking systems must handle conditions that ordinary CRUD applications do not:

- multiple customers requesting the same seat concurrently;
- overlapping multi-seat selections;
- temporary holds that expire;
- delayed or unavailable workers;
- stale seat maps in browsers;
- duplicate requests after client timeouts;
- duplicate, delayed, or out-of-order payment callbacks;
- payment success after a hold has expired;
- cancellation racing with confirmation;
- showtime changes after sales begin;
- authorization and booking privacy;
- backup, restore, and reconciliation after failure.

A failure can cause double-booking, lost payments, permanently blocked seats, duplicate bookings, data exposure, or misleading confirmation.

## 3. Objectives

### Product objective

Deliver a complete customer booking journey and a minimum operator workflow while guaranteeing authoritative seat inventory and traceable booking outcomes.

### Engineering objective

Prove that Laravel can support a reliable transactional system using explicit PostgreSQL transactions, deterministic locking, constraints, idempotency, durable background work, and reconciliation.

### Learning objective

Develop deep Laravel mastery in:

- application structure and the service container;
- Eloquent, Query Builder, and explicit SQL;
- PostgreSQL transactions and `lockForUpdate()`;
- policies and authorization;
- queues, workers, scheduling, retries, and failed jobs;
- state machines and enums;
- idempotent HTTP commands and callbacks;
- transactional outbox;
- integration, concurrency, and browser testing;
- observability, deployment, migration, backup, and recovery.

## 4. Actors

### Customer

- Register and log in.
- Browse movies, venues, dates, and showtimes.
- Select accessible seats.
- Hold seats temporarily.
- Complete simulated payment.
- Receive and view booking confirmation.
- View and cancel owned bookings according to policy.

### Cinema operator/admin

- Manage movies, venues, halls, and fixed seat layouts.
- Create, publish, and cancel showtimes.
- View booking and inventory state.
- Block or unblock seats through controlled operations.
- Review audit and reconciliation information.

### System worker

- Expire abandoned holds.
- Process transactional outbox records.
- Retry failed operations.
- Reconcile payment, booking, and inventory mismatches.

### Simulated payment provider

- Accept a payment request outside the booking transaction.
- Produce signed success, failure, timeout, duplicate, and late callbacks.

## 5. Approved MVP policies

These MVP defaults were approved by the Product Owner on 2026-08-10. Changes require an explicit decision-record update.

| Policy | Approved MVP baseline |
|---|---|
| Customer access | Public browsing; account required from the hold request onward (`ADR-014`) |
| Guest checkout | Deferred |
| Maximum seats per booking | 8 |
| Hold duration | 5 minutes |
| Hold extension | Not allowed |
| Multi-seat acquisition | All requested seats or none |
| Time authority | PostgreSQL UTC time |
| Display time | Venue timezone |
| Price model | One showtime price |
| Price snapshot | At hold creation |
| Payment | Simulated asynchronous provider |
| Callback authentication | HMAC signature |
| Late payment success | No booking; reconciliation/refund-required state |
| Customer cancellation | Allowed before configurable cutoff |
| Cancellation cutoff | 2 hours before showtime |
| Cancellation inventory result | Seats become available again if showtime is still on sale |
| Showtime cancellation | Block new holds immediately |
| QR | High-entropy confirmation token; no PII |
| QR scanner/check-in | Deferred |
| Seat-map refresh | Short polling approximately every 5 seconds with ±20% jitter (4–6 s) |
| Accessibility | WCAG 2.2 AA-oriented critical journey |

## 6. Core user journeys

### Golden path

```text
Browse movies
  → Select venue/date/showtime
  → View seat map
  → Select seats
  → Register/Login at the hold request
  → Create server-side hold
  → Review price snapshot
  → Simulated payment succeeds
  → Booking transaction confirms seats
  → Booking reference and QR issued
  → Booking appears in My Bookings
```

### Seat conflict

```text
Customer selects a seat from a stale map
  → Another customer acquires it first
  → Hold transaction revalidates under row lock
  → Request receives deterministic conflict
  → No partial hold is created
  → UI refreshes and preserves still-valid choices where possible
```

### Lost response

```text
Mutation commits
  → Client connection times out before response
  → Client retries with the same idempotency key
  → Server returns the original committed result
```

### Late payment success

```text
Hold expires
  → Seat may be acquired by another customer
  → Payment success callback arrives late
  → Seat is not reclaimed
  → Payment becomes LATE_SUCCESS / REFUND_REQUIRED
  → Reconciliation event is created
```

## 7. MVP scope

### Customer capabilities

- Registration and login.
- Movie, venue, date, and showtime browsing.
- Accessible interactive seat map.
- Atomic multi-seat hold.
- Server-authoritative countdown.
- Checkout review and price snapshot.
- Simulated payment outcomes.
- Booking confirmation, reference, and QR.
- My Bookings and booking detail.
- Policy-controlled cancellation.

### Operator capabilities

- Basic admin authorization.
- Movie and venue management.
- Fixed hall and seat configuration.
- Showtime draft, publication, and cancellation.
- Inventory materialization at publication.
- Booking and seat-state visibility.
- Audit of material admin commands.

### Platform capabilities

- Database-backed queue.
- Hold-expiry worker.
- Transactional outbox.
- Payment callback inbox and deduplication.
- Retry and dead-letter handling.
- Reconciliation command.
- Structured logs and correlation IDs.
- Health checks and booking metrics.
- Migration, backup, and restore procedures.

## 8. Explicit non-goals

- Real payment gateway and refunds.
- Guest checkout and account linking.
- Email, SMS, and push notifications.
- Loyalty, vouchers, promotions, and food ordering.
- Dynamic pricing and multi-currency.
- Multi-brand tenancy.
- Drag-and-drop hall designer.
- Offline QR validation.
- Native mobile application.
- Redis, Kafka, WebSockets, microservices, and Kubernetes.

## 9. Architecture

### Stack

```text
Backend:        Laravel
Frontend:       Vue 3 + Inertia
Database:       PostgreSQL
Styling:        Tailwind CSS
Testing:        Pest/PHPUnit
Browser tests:  Playwright
Queue:          Laravel database queue initially
Scheduler:      Laravel scheduler
Deployment:     Containerized modular monolith
```

### Runtime topology

```text
Browser
   ↓ HTTPS
Laravel + Inertia web process
   ↓
PostgreSQL
   ↑
Laravel queue worker
   ↑
Laravel scheduler
```

The web, worker, and scheduler share one repository and domain model but run as separate processes.

### Suggested application structure

```text
app/
├── Actions/
│   ├── Holds/
│   ├── Payments/
│   ├── Bookings/
│   └── Showtimes/
├── Domain/
│   ├── Catalog/
│   ├── Inventory/
│   ├── Holds/
│   ├── Payments/
│   └── Bookings/
├── Enums/
├── Exceptions/
├── Http/
├── Jobs/
├── Models/
├── Policies/
└── Support/
```

Controllers parse, authorize, call one application action, and map the result. Core booking rules must not live in controllers, Vue components, model observers, or hidden Eloquent events.

## 10. Core data model

```text
users
roles
movies
venues
halls
seats
showtimes
showtime_seats
holds
hold_items
payments
payment_callback_events
bookings
booking_items
idempotency_keys
outbox_events
audit_events
```

### Authoritative inventory

Every physical seat receives one materialized inventory row for every published showtime.

```text
showtime_seats
├── showtime_id
├── seat_id
├── status
├── hold_id
├── booking_id
├── hold_expires_at
├── price_minor
├── currency
└── version
```

State transitions:

```text
AVAILABLE → HELD → CONFIRMED
     ↑         |
     └─────────┘ expired/released

BLOCKED is controlled by operator/showtime policy.
```

Required database protections include:

- primary or unique key on `(showtime_id, seat_id)`;
- state-dependent `CHECK` constraints;
- unique active confirmed owner for each showtime-seat;
- unique booking by hold and payment;
- unique payment-provider event ID;
- unique scoped idempotency key;
- money stored in integer minor units;
- immutable booking, seat-label, and price snapshots.

## 11. Correctness protocol

Atomic multi-seat hold:

```text
Validate and sort unique seat IDs
  → begin transaction
  → lock showtime
  → lock requested inventory rows ordered by seat ID
  → revalidate all rows using PostgreSQL time
  → fail all if any seat is unavailable
  → create hold and snapshots
  → update all seat rows
  → insert outbox event
  → commit
```

The expiry worker is cleanup, not the sole source of correctness. New hold and confirmation transactions must detect expiry inline using PostgreSQL time.

## 12. Success criteria

### Product

- Customer completes browse-to-booking journey.
- Operator prepares and runs the demo without direct database edits.
- Every confirmed booking has a unique reference and QR.
- Customer can access only owned bookings.

### Engineering

- Zero confirmed double-booking.
- Multi-seat holds are all-or-nothing.
- Same-key retries return the same result.
- Duplicate callbacks produce one logical effect.
- Worker crash and replay do not duplicate booking effects.
- Expired seats are reclaimable if cleanup is delayed.
- Payment, booking, inventory, and outbox reconcile.
- Backup is restored and validated successfully.
- Critical flow is keyboard and screen-reader usable.

### Portfolio

- Project brief and timeline.
- Architecture diagram and ERD.
- State-machine diagrams.
- Architecture Decision Records.
- API documentation.
- Concurrency and payment-chaos reports.
- Accessibility evidence.
- Load-test results.
- Deployment guide and operational runbook.
- Backup/restore evidence.
- Demo script or walkthrough video.

## 13. Primary risks

| Risk | Impact | Mitigation |
|---|---:|---|
| Confirmed double-booking | Critical | Ordered row locks, constraints, real-PostgreSQL race tests |
| Partial multi-seat hold | High | One transaction; all-or-nothing validation |
| Stale seat map | Medium | Advisory UI; authoritative write-path revalidation |
| Delayed expiry worker | High | Inline expiry checks plus durable cleanup worker |
| Duplicate payment callback | High | Unique provider event and idempotent state machine |
| Payment success after expiry | High | Reconciliation state; no forced seat reclaim |
| Cancellation/confirmation race | High | Common lock order beginning with showtime |
| Lost response after commit | High | Scoped idempotency keys and result recovery |
| Hall edit after publication | Critical | Freeze structural changes; preserve snapshots |
| Booking IDOR/PII leak | Critical | Policies, ownership checks, opaque references, no PII in QR |
| Inaccessible seat map | High | Semantic controls and manual accessibility testing |
| Unusable backup | Critical | Actual isolated restore and invariant reconciliation |

## 14. Definition of done

Seatly is complete only when:

- the complete customer journey works;
- all P0 invariants pass against real PostgreSQL;
- no unresolved Critical or High security/correctness defects remain;
- worker failure and retry are demonstrated safely;
- deployment and migration are reproducible;
- backup restore and reconciliation succeed;
- accessibility evidence covers the critical journey;
- the portfolio evidence pack and runbook are complete.
