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
- `D20` — named approvers per governance role, for the G0 multi-role sign-off.

Closed since: companion adjacency (`ADR-018`, raised as `D21` during `C2-01`) and
hall/seat versioning after publication (`ADR-019`), both on 2026-08-10.

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

```text
Status:              DONE (2026-08-10)
Requirement IDs:     BR-02; ADR-002
Gate/P0 IDs:         G1
Red evidence:        n/a — initialization item, no behavioural test
Green evidence:      docs/evidence/F1-03/green.md version matrix; composer.lock;
                     package-lock.json
Implementation notes: laravel/vue-starter-kit main branch, cloned and de-gitted
                     rather than composer create-project, because the tagged
                     package v1.0.2 still pins laravel/framework ^12.0 while the
                     main branch targets ^13.17. Resolved to framework v13.24.0
                     on PHP 8.5.3. Ships Inertia v3.3.1, Fortify v1.37.3 (auth
                     foundation), Wayfinder, Chisel, Pint, Larastan, PHPUnit
                     12.5.33 — PHPUnit rather than Pest, which CLAUDE.md permits.
                     Playwright 1.62.1 added; nothing else installed. Removed the
                     starter kit's post-create-project-cmd sqlite touch, which
                     would have created database/database.sqlite.
Open decisions:      none
```

### F1-02 — Real PostgreSQL environment

- Configure separate development and integration databases.
- Provide deterministic migrate/reset/seed behavior.
- Fail clearly if concurrency tests are pointed at SQLite.
- Record PostgreSQL version and transaction assumptions.

**Trace:** BR-02; RULE-004; NFR-001; ADR-002/004.

```text
Status:              DONE (2026-08-10)
Requirement IDs:     BR-02; RULE-004; NFR-001; ADR-002/004
Gate/P0 IDs:         G1
Red evidence:        docs/evidence/F1-03/red.md (integration layer)
Green evidence:      docs/evidence/F1-03/green.md, incl. the SQLite-guard section
Implementation notes: docker-compose.yml pins postgres:16.14 to match the psql
                     client already on the machine. docker/postgres/init creates
                     seatly_test alongside seatly_dev and installs btree_gist and
                     pgcrypto in both, per LOGICAL_ERD.md "Required extensions".
                     PostgreSQL 18 was considered and rejected: its temporal
                     WITHOUT OVERLAPS constraint duplicates the GiST exclusion
                     constraint the ERD already specifies, uuidv7 would leak
                     creation time through public_id, and pg_dump 16 cannot dump
                     an 18 server — friction against the Phase 7 restore gate for
                     no feature. phpunit.xml points every suite at seatly_test;
                     no layer touches SQLite. Tests\Integration\IntegrationTestCase
                     fails with an explanatory message when the driver is not
                     pgsql — verified by running the suite with DB_CONNECTION=sqlite,
                     which failed all five cases rather than passing.
Open decisions:      none
```

### F1-03 — Test-first project skeleton

- Add one deliberately failing smoke test for each configured test layer.
- Demonstrate red before adding the minimum setup that makes it green.
- Verify unit, PostgreSQL integration, and Playwright execution separately.
- Replace command placeholders only with commands that actually ran.

```text
Status:              DONE (2026-08-10)
Requirement IDs:     BR-02; RULE-004; NFR-001
Gate/P0 IDs:         G1
Red evidence:        docs/evidence/F1-03/red.md — Unit, Feature, and Integration
                     each failed for its stated reason before any setup was added.
                     Browser layer red captured separately: expected 418, got 200.
Green evidence:      docs/evidence/F1-03/green.md — Unit 3, Feature 40,
                     Integration 5, Browser 2. composer test (Pint + PHPStan +
                     48 tests, 150 assertions) passes.
Implementation notes: four layers, run separately. Unit is plain PHPUnit with no
                     container. Feature boots the app and serves GET /.
                     Integration extends IntegrationTestCase and asserts server
                     version 16.x, both required extensions, a zero-offset server
                     clock, and timestamptz round-tripping. Browser is Playwright
                     against php artisan serve. Two of my own green assertions
                     were themselves wrong and had to be corrected: PostgreSQL
                     reports its session zone as 'Etc/UTC' not 'UTC' (now asserts
                     the offset), and 18.50 * 3 is exactly 55.5 in IEEE 754, so
                     that made a useless float-precision example (now 0.10 + 0.20).
                     tsconfig.json extended to cover tests/Browser and the
                     Playwright config so eslint and vue-tsc see them.
Open decisions:      none
```

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

```text
Status:              BLOCKED (2026-08-10) — written and pushed, cannot execute
Requirement IDs:     NFR-001; ADR-002/004
Gate/P0 IDs:         G1
Red evidence:        n/a
Green evidence:      none. Run 31361221981 on the first push to
                     github.com/fatihiazmi/seatly failed in 2 seconds with zero
                     steps executed and an empty runner_name — GitHub never
                     allocated a runner. The workflow file itself is valid: it
                     parses, declares 19 steps and the postgres service, and
                     Actions is enabled on the repository (allowed_actions: all).
                     This is an account-level Actions block, consistent with the
                     GitHub billing lock recorded on 2026-08-05. It is not a
                     defect in this workflow and cannot be fixed from the
                     repository.
Implementation notes: .github/workflows/tests.yml runs postgres:16.14 as a
                     service, installs btree_gist and pgcrypto, asserts
                     config('database.default') is pgsql before anything else so a
                     silent SQLite fallback cannot make the job meaningless,
                     migrates, then runs Pint, PHPStan, the front-end lint/format/
                     type checks, the three PHP suites separately, a production
                     asset build, and Playwright. Evidence is uploaded on always().
                     PHP pinned to 8.5 and Node to 24 to match the development
                     machine.
Open decisions:      none. Blocked on the GitHub account's Actions billing being
                     restored. This item cannot become DONE from a workflow file
                     that has never run, and no amount of editing it will change
                     that.
```

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

```text
Status:              DONE (2026-08-10)
Requirement IDs:     FR-037, FR-038; PP-10; ADR-005/012; NFR-010
Gate/P0 IDs:         G1, G2
Red evidence:        docs/evidence/C2-01/red.md — 31 of 37 cases failed on
                     missing classes before any model or migration existed.
Green evidence:      docs/evidence/C2-01/green.md — 40 integration tests, and
                     83 tests / 196 assertions across the whole suite with Pint
                     and PHPStan clean.
Implementation notes: covers migration sequencing steps 4 and 5 — movies,
                     venues, halls, seats, seat_companion_links. Showtimes are
                     C2-02. Roles and role_user (step 3) are deliberately not
                     built: nothing in C2-01 authorizes anything, and the backlog
                     forbids pre-creating empty layers. They arrive with the
                     first authorization test in O6-01.

                     CHECK constraints are raw DDL because Laravel's schema
                     builder has no first-class support for them: runtime > 0,
                     layout_version > 0, sort_order >= 0, seat_type membership
                     derived from App\Enums\SeatType so the enum and the
                     constraint cannot drift.

                     The seat_companion_links cross-row rules are a DEFERRABLE
                     INITIALLY DEFERRED constraint trigger, since a CHECK cannot
                     read the referenced seat rows. Because RefreshDatabase never
                     commits, the tests force it with SET CONSTRAINTS ALL
                     IMMEDIATE — without that they would pass without the trigger
                     ever running. Verified deferrable in pg_trigger.

                     Venue and hall FKs restrict on delete, so a referenced hall
                     or seat cannot be deleted; is_active is the intended path.

                     public_id is UUID v4 assigned by App\Concerns\HasPublicId,
                     with gen_random_uuid() as the database default. Not v7 —
                     a v7 value encodes its creation timestamp and public_id is
                     handed to unauthenticated clients.

                     Two real defects surfaced. migrate:fresh drops tables but
                     leaves standalone functions, so the trigger function needed
                     CREATE OR REPLACE or the second suite run collided. And
                     PHPStan rejected chr() in SeatFactory as unbounded, which
                     was fixed by computing the row label from a fixed alphabet
                     rather than by silencing the check.
Open decisions:      none. D21 was raised here — companion-seat adjacency was
                     undefined in every source document, so the four unambiguous
                     rules were enforced and adjacency was not. Closed the same
                     day by ADR-018 (same row, consecutive sort_order) and
                     enforced by migration 2026_08_10_140000.
```

### C2-02 — Showtime draft and publication

Publishing must transactionally create exactly one inventory row for every
active hall seat. Repeated publication must not duplicate inventory.

**Trace:** FR-003/039/040; RULE-001/004; ADR-005; AC-015.

```text
Status:              DONE (2026-08-10)
Requirement IDs:     FR-003/039/040; RULE-001/004; ADR-005; AC-015
Gate/P0 IDs:         G1, G2; prerequisite to G3
Red evidence:        docs/evidence/C2-02/red.md — all 40 cases failed on missing
                     classes before any migration, model or action existed.
Green evidence:      docs/evidence/C2-02/green.md — 123 tests / 274 assertions
                     across the suite, Pint and PHPStan clean, plus the coverage
                     invariant SQL returning zero on both halves.
Implementation notes: covers ST-01 through ST-03 and INV-01. ST-04 cancellation
                     is O6-03 and is not implemented here.

                     App\Actions\Showtimes\PublishShowtime is one transaction:
                     lock the showtime first per the global lock order, run the
                     ST-03 guards, INSERT ... SELECT one AVAILABLE row per active
                     seat, then flip status. Set-based on purpose — a per-seat
                     loop could interleave with a layout change halfway through,
                     and the (showtime_id, seat_id) primary key makes duplication
                     impossible regardless.

                     No stored ENDED state (RC-03). Showtime::scopeOnSale is
                     status = PUBLISHED AND transaction_timestamp() < starts_at,
                     evaluated by PostgreSQL inside the asking transaction, so a
                     finished showtime can never read as bookable because a job
                     failed to run.

                     The hall-overlap exclusion constraint is partial on
                     status = 'PUBLISHED' and half-open '[)', so two drafts may
                     overlap and one showtime may end exactly as the next begins.
                     PublishShowtime checks the same condition first, turning a
                     raw constraint violation into HALL_SCHEDULE_CONFLICT.

                     Deviation from migration sequencing, recorded deliberately:
                     the state-dependent showtime_seats linkage CHECK is created
                     at step 7 rather than step 12. Step 12 defers it until the
                     composite owner FKs exist, but the CHECK only compares
                     columns within one row and has no dependency on holds or
                     bookings. Adding it now makes every row this slice writes
                     provably clean. The composite owner FKs themselves remain
                     at step 11 as documented.

                     Two real defects surfaced. ShowtimeSeat has the composite
                     primary key the ERD requires, which Eloquent cannot model,
                     so every chunk-based helper — each(), chunkById(), find() —
                     emits ORDER BY "" and fails; documented on the model and
                     avoided in the tests. And PHPStan showed the @return
                     array<string, string> annotation on casts() made Larastan
                     read status as a string, so the enum guard in PublishShowtime
                     was typed as "always true"; fixed with explicit @property
                     declarations on Showtime, Seat and ShowtimeSeat.

                     Not included, and deliberately: the SHOWTIME_PUBLISHED
                     outbox event and the audit record, whose tables arrive at
                     sequencing step 14 in Phase 5; and authorization, which
                     arrives with roles in O6-01.

                     CatalogueSeeder covers sequencing step 16 — a venue, two
                     6x10 halls with two accessible/companion pairs each, three
                     movies, and publish-time materialization rehearsed against
                     real PostgreSQL.
Open decisions:      none. D21 was still open while this slice was written and
                     did not block it; it closed the same day as ADR-018.
```

### C2-03 — Structural integrity after publication

Reject changes that would corrupt active or historical showtime inventory.
Where detailed edit policy remains Open, implement only unambiguous database
protections and leave workflow decisions blocked.

**Trace:** FR-038/041; NFR-010; ADR-005/019.

```text
Status:              DONE (2026-08-10)
Requirement IDs:     FR-038/041; NFR-010; ADR-005/019
Gate/P0 IDs:         G1, G2
Red evidence:        docs/evidence/C2-03/red.md — 10 of 20 cases failed, each
                     reporting that the expected refusal did not happen.
Green evidence:      docs/evidence/C2-03/green.md — 148 tests / 304 assertions,
                     Pint and PHPStan clean, plus a direct psql UPDATE refused
                     against a frozen hall and accepted against an open one.
Implementation notes: the edit policy is no longer Open — ADR-019 closed it
                     before this slice began, so this implements a defined table
                     rather than "unambiguous database protections only".

                     Enforcement is a BEFORE trigger on seats and on halls, not
                     an Action, because NFR-010 is about what the data permits: a
                     direct SQL write must not be able to corrupt published
                     inventory. Proven by running the UPDATE through psql.

                     hall_layout_is_frozen(hall_id) is a STABLE SQL function
                     wrapping the same derived on-sale rule used everywhere else,
                     so the trigger and Hall::layoutIsFrozen() cannot disagree
                     about what "upcoming" means — both ask PostgreSQL for the
                     time inside the asking transaction.

                     Frozen on seats: hall_id, row_label, seat_number, sort_order,
                     seat_type, is_active, plus INSERT and DELETE. Frozen on
                     halls: is_active. Editable throughout: display_label,
                     x_position, y_position, hall name and code.

                     The seat UPDATE guard checks both OLD and NEW hall_id, so a
                     seat cannot be moved out of a frozen hall or into one.

                     layout_version is bumped by an AFTER trigger rather than by
                     application code, for the same reason as the guard. Laying
                     out a 60-seat hall leaves it at 61: initial construction
                     counts, because no state marks a layout as finished. The
                     value is monotonic and its magnitude carries no meaning; the
                     test states this explicitly rather than leaving it to be
                     rediscovered.

                     One defect, and a repeat of the C2-01 one: migrate:fresh
                     drops tables and their triggers but leaves standalone
                     functions, so plain CREATE FUNCTION passed on the first suite
                     run and collided on the second. All four functions now use
                     CREATE OR REPLACE, and the rule is recorded in CLAUDE.md so
                     it does not happen a third time.
Open decisions:      none for halls and seats. Venue deactivation with upcoming
                     published showtimes is the same class of problem, is not
                     covered by ADR-019, and remains with FR-041 — it should be
                     closed before the venue admin screens.
```

### C2-04 — Minimal authoritative seat-map endpoint

Return stable seat identity and authoritative availability needed by tests.
Do not build polished geometry or animation.

**Trace:** FR-004/007; RULE-004; AC-001/002.

```text
Status:              DONE (2026-08-10)
Requirement IDs:     FR-003/004/005; RULE-001/004; ADR-005/007/012/014;
                     AC-001/002
Gate/P0 IDs:         G1, G2; prerequisite to G3
Red evidence:        docs/evidence/C2-04/red.md — 17 of 21 cases failed on a
                     missing route.
Green evidence:      docs/evidence/C2-04/green.md — 173 tests / 372 assertions,
                     Pint and PHPStan clean, plus curl against the real seeded
                     database covering 200, draft 404, unknown 404, malformed
                     404, and an availability change moving the version.
Implementation notes: GET /showtimes/{public_id}/seat-map, public per ADR-014.
                     Resolved on public_id only; the internal bigint does not
                     resolve. A draft 404s because nothing is materialized before
                     publication and an unpublished showtime should not be
                     disclosed; cancelled and past showtimes are still served
                     with on_sale false, so a customer on a stale link is told
                     why rather than shown a 404 (FR-003).

                     Two rules are shared with the write path so the two cannot
                     disagree. On sale is derived from the PostgreSQL clock
                     (RC-03). An expired HELD row reads as selectable, matching
                     LOGICAL_ERD.md 5.1 — reporting it unavailable would tell a
                     customer no when a hold request would say yes.

                     Availability is a boolean, not the stored status. HELD
                     versus CONFIRMED would disclose to an unauthenticated
                     stranger whether someone is midway through checkout, and
                     FR-004 asks only for selectable versus unavailable. Verified
                     by asserting the response contains neither the status nor
                     the block reason.

                     inventory_version is the sum of row versions: monotonic
                     because every transition increments its row. L4-04 builds
                     the polling; this only exposes the number so it can.

                     Companion pairs are exposed as linked_seat_id in both
                     directions, since PP-10 requires the UI to identify pairing.

                     One real defect: a malformed identifier reached PostgreSQL,
                     where comparing it to a uuid column raised a cast error and
                     the endpoint answered 500 instead of 404. Fixed with
                     whereUuid on the route so it never gets that far.

                     Two of my own test assertions were wrong. A substring check
                     for leaked internal names failed on linked_seat_id, which
                     legitimately contains "seat_id" — replaced with key and
                     value assertions. And the fixture set seat_number without
                     matching display_label, so the ordering assertion compared
                     against labels from the factory sequence.
Open decisions:      none
```

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
