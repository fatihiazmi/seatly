# Seatly

Seatly is a production-minded cinema seat reservation and booking system built as a Laravel mastery project.

The project is designed to demonstrate more than CRUD: atomic multi-seat holds, concurrency control, idempotent payment processing, background workers, transactional outbox, authorization, accessibility, observability, recovery, and adversarial testing.

## Status

- Project type: Greenfield
- Council verdict: Conditional Go
- Current phase: Sprint 0 — review candidate; Laravel initialization intentionally deferred to Sprint 1
- Product baseline: MVP policies and operational targets approved on 2026-08-10; `PD-01`–`PD-10` resolved the same day as `ADR-015`, `ADR-016`, `ADR-017`
- Open decisions remaining: `D14` (QR payload detail), `D20` (named approvers), hall/seat versioning after publication — none block a slice
- Baseline reconciliation (`S0-01`) applied 2026-08-10 — see [docs/BASELINE_RECONCILIATION.md](docs/BASELINE_RECONCILIATION.md)
- Stack direction: Laravel + Vue 3/Inertia + PostgreSQL
- Production promotion: No-Go until all P0 release gates pass

## Core invariant

> For one showtime and one seat, there can be at most one confirmed booking.

PostgreSQL is the final authority for seat ownership. Browser state, cache, application memory, and background workers must never be the final allocation authority.

## Documents

- [Start Here for Claude Code](START_HERE.md)
- [Claude Code Contract](CLAUDE.md)
- [Implementation Backlog](IMPLEMENTATION_BACKLOG.md)
- [Business Requirements Document](docs/BRD.md)
- [Baseline Reconciliation Record](docs/BASELINE_RECONCILIATION.md)
- [Project Brief](docs/PROJECT_BRIEF.md)
- [Delivery Timeline](docs/DELIVERY_TIMELINE.md)
- [Architecture Decisions](docs/DECISIONS.md)
- [Approved MVP Policy and Operational Baseline](docs/MVP_POLICY_BASELINE.md)
- [Domain State Models](docs/DOMAIN_STATE_MODELS.md)
- [Logical ERD Specification](docs/LOGICAL_ERD.md)
- [Sprint 0 Review Decisions](docs/SPRINT_0_REVIEW_DECISIONS.md)
- [Sprint 0 Exit Checklist](docs/SPRINT_0_EXIT_CHECKLIST.md)
- [Traceability Matrix](docs/TRACEABILITY_MATRIX.md)
- [Release Gates](docs/RELEASE_GATES.md)
- [Editable BPMN diagram (Draw.io)](docs/diagrams/seatly-booking-process.drawio)
- [Editable Logical ERD (Draw.io)](docs/diagrams/seatly-logical-erd.drawio)
- [Editable State Machines (Draw.io)](docs/diagrams/seatly-state-machines.drawio)
- [Semantic BPMN 2.0 XML](docs/bpmn/seatly-booking-process.bpmn)

## Approved stack direction

- Backend: Laravel
- Frontend: Vue 3 + Inertia
- Database: PostgreSQL
- Styling: Tailwind CSS
- Tests: Pest/PHPUnit plus Playwright
- Queue: Laravel database queue initially
- Scheduler: Laravel scheduler
- Architecture: Modular monolith
- Deployment: Containerized web, worker, scheduler, and managed PostgreSQL

## MVP journey

```text
Browse movies
  → Select venue and showtime
  → Select seats
  → Register/Login at the hold request
  → Create five-minute server-side hold
  → Simulated payment
  → Confirm booking
  → Receive booking reference and QR
  → View or cancel booking
```

## Explicit non-goals for MVP

- Real payment and refund integration
- Guest checkout
- Loyalty, vouchers, promotions, and food ordering
- Dynamic pricing and multi-currency
- Multi-brand tenancy
- Drag-and-drop hall designer
- Offline QR validation
- Native mobile application
- Redis, Kafka, WebSockets, microservices, and Kubernetes

## First engineering gate

Before UI polish, run a real-PostgreSQL concurrency spike:

```text
100 concurrent attempts
→ same showtime
→ same seat
→ at most one successful hold
```

If this invariant is not proven, development must not proceed to payment or UI polish.
