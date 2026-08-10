# Seatly — Claude Code Contract

Seatly is a documentation-only greenfield project. Do not assume Laravel has
already been initialized.

## Read first

Before planning or changing code, read:

1. `START_HERE.md`
2. `IMPLEMENTATION_BACKLOG.md`
3. `docs/BASELINE_RECONCILIATION.md`
4. `docs/BRD.md`
5. `docs/DECISIONS.md`
6. `docs/MVP_POLICY_BASELINE.md`
7. `docs/DOMAIN_STATE_MODELS.md`
8. `docs/LOGICAL_ERD.md`
9. `docs/SPRINT_0_REVIEW_DECISIONS.md`
10. `docs/TRACEABILITY_MATRIX.md`
11. `docs/RELEASE_GATES.md`
12. `docs/DELIVERY_TIMELINE.md`

Accepted requirements and decisions are authoritative. Proposed or Open items
must not be silently implemented.

`DOMAIN_STATE_MODELS.md` is authoritative for states, transitions, and guards.
`LOGICAL_ERD.md` is authoritative for storage, keys, and constraints. If a schema
constraint would reject a required transition, that is a defect in the schema —
report it, do not weaken the transition.

## Stack and boundaries

- Laravel modular monolith
- Vue 3 with Inertia
- PostgreSQL
- Pest/PHPUnit
- Playwright
- Laravel database queue initially
- Separate web, worker, and scheduler processes
- No Redis, WebSockets, Kafka, microservices, or Kubernetes for MVP
- No real payment, real refund, guest checkout, dynamic pricing, or QR scanning

Do not introduce generic reservation-engine abstractions. Seatly is
cinema-specific.

## Non-negotiable invariants

1. PostgreSQL is authoritative for inventory, holds, payments, and bookings.
2. One showtime-seat has at most one active confirmed booking.
3. Multi-seat acquisition is all-or-none.
4. Duplicate commands and callbacks have one logical effect.
5. Payment success alone never proves that seats are confirmable.
6. Late payment success never reclaims a released seat.
7. Expiry, confirmation, and cancellation races have explicit outcomes.
8. Authorization fails closed.
9. Booking reference and QR exist only after committed confirmation.
10. The expiry worker is cleanup, not the source of allocation correctness.

Use deterministic lock ordering: lock the showtime, then requested inventory
rows in sorted seat-ID order.

## Required working method

Use strict test-driven development:

1. Select one backlog item and list its requirement and release-gate IDs.
2. Write the smallest failing test first.
3. Run it and preserve evidence that it failed for the expected reason.
4. Implement the smallest change that passes it.
5. Run the focused test.
6. Refactor without changing behavior.
7. Run the affected suite and required invariant checks.
8. Record evidence and update backlog status.

A test written after implementation does not satisfy the TDD requirement unless
the implementation is reverted or disabled and the test is first demonstrated
red.

Use real PostgreSQL for transaction, lock, constraint, queue, outbox, clock, and
race behavior. SQLite or mocks may support isolated tests but cannot provide
concurrency evidence.

## Stop gate

Do not implement simulated payment, booking confirmation, or visual UI polish
until the real-PostgreSQL hold gate passes:

- at least 100 coordinated attempts for one showtime-seat;
- exactly one successful hold;
- deterministic conflict responses for losers;
- overlapping multi-seat requests never partially succeed;
- repeated seeded runs;
- post-run invariant SQL reports no duplicate ownership.

If this fails or is flaky, stop and fix the integrity path. Do not rerun until
green and call the original result a pass.

## Requirement control

For every implementation item, record:

- BR/FR/RULE/NFR/AC identifiers;
- release gate or P0 invariant;
- test and evidence locations;
- unresolved decisions that block the item.

If a requirement is unclear, contradictory, Proposed, or Open:

1. stop the affected implementation;
2. identify the exact source and IDs;
3. add or update a decision/change record;
4. state safe alternatives and impact;
5. wait for explicit approval.

Do not resolve ambiguity through code. Do not silently change requirements,
scope, architecture, release thresholds, or policy. Approved changes must update
the decision record, backlog, traceability, tests, and affected documentation.

## Design constraints

- Controllers authorize, validate, invoke one application action, and map the
  result.
- Core workflow rules belong in explicit Actions and state transitions.
- Do not hide business transitions in model observers, Vue components, or
  implicit Eloquent events.
- Prefer Eloquent for ordinary persistence.
- Query Builder or parameterized SQL is expected on concurrency-critical paths.
- Use PostgreSQL constraints as final backstops.
- Always write `CREATE OR REPLACE FUNCTION` in migrations. `migrate:fresh` drops
  tables and their triggers but leaves standalone functions behind, so a plain
  `CREATE FUNCTION` succeeds on the first run of the test suite and fails on the
  second. Triggers themselves may use plain `CREATE TRIGGER`.
- `DB::unprepared()` requires a `literal-string`, so build the SQL as a literal
  heredoc rather than interpolating it, or PHPStan will reject the call.
- Store money as integer sen with ISO currency.
- Persist authoritative instants in UTC using `timestamptz`.
- Use the PostgreSQL clock for expiry and transaction-boundary decisions.
- Preserve immutable booking, seat-label, and price snapshots.
- Avoid repositories, buses, factories, services, or interfaces without a
  current demonstrated need.
- Do not add infrastructure merely because it may be useful later.

## Completion rules

Never claim an item complete from code inspection alone. Completion requires:

- the specified test first demonstrated red and then green;
- focused and affected suites passing;
- real PostgreSQL evidence where database behavior matters;
- requirement-to-test traceability;
- no hidden scope or policy decision;
- documentation updated when behavior or evidence changes.

Coverage percentage and code completion do not override a failed release gate.

## Commands

Every command below was run successfully in this repository on 2026-08-10.
Commands still shown as placeholders have no implementation yet — do not invent
them.

The database must be running before anything that touches PostgreSQL:

```bash
docker compose up -d          # postgres:16.14, seatly_dev + seatly_test on 5432
docker compose down           # stop; add -v to discard the data volume
```

| Purpose | Command |
|---|---|
| Bootstrap | `composer install && npm install && cp .env.example .env && php artisan key:generate && php artisan migrate` |
| Dev server | `composer dev` |
| All PHP tests | `php artisan test` |
| Focused PHP test | `php artisan test --filter=<TestClassOrMethod>` |
| Unit layer only | `php artisan test --testsuite=Unit` |
| Feature layer only | `php artisan test --testsuite=Feature` |
| PostgreSQL integration | `php artisan test --testsuite=Integration` |
| Browser | `npx playwright test` (or `npm run test:browser`) |
| Lint | `composer lint:check` (write mode: `composer lint`) |
| Static analysis | `composer types:check` |
| Front-end checks | `npm run lint:check && npm run format:check && npm run types:check` |
| Full local CI chain | `composer ci:check` |

Not yet implemented — these arrive with Phase 3:

- `<CONCURRENCY_COMMAND>` — the same-seat contention harness (`H3-04`).
- `<INVARIANT_CHECK_COMMAND>` — post-run invariant SQL (`H3-06`).

Seatly is PostgreSQL-only. `Tests\Integration\IntegrationTestCase` fails loudly
if the default connection is not `pgsql`, because SQLite would report success
without exercising any of the behaviour the P0 invariants depend on.
