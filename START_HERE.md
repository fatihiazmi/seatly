# Start Here

Seatly currently contains the approved review material and implementation
contract. It intentionally does not contain a Laravel scaffold.

Do not begin by generating features from the project brief. First establish
traceability, resolve blockers for the next slice, and initialize a reproducible
Laravel/PostgreSQL test environment.

## 1. Read the sources in order

1. `CLAUDE.md`
2. `docs/BASELINE_RECONCILIATION.md`
3. `docs/BRD.md`
4. `docs/DECISIONS.md`
5. `docs/MVP_POLICY_BASELINE.md`
6. `docs/DOMAIN_STATE_MODELS.md`
7. `docs/LOGICAL_ERD.md`
8. `docs/SPRINT_0_REVIEW_DECISIONS.md`
9. `docs/TRACEABILITY_MATRIX.md`
10. `docs/RELEASE_GATES.md`
11. `docs/DELIVERY_TIMELINE.md`
12. `IMPLEMENTATION_BACKLOG.md`

Read the reconciliation record early. It names every place the other documents
once disagreed, which document won, and what is still open — so you do not
rediscover a contradiction that has already been resolved, or treat a resolved
one as still ambiguous.

Use the BRD status vocabulary literally:

- **Accepted:** may be implemented.
- **Proposed:** requires approval.
- **Open:** must not be silently implemented.

The decision and policy documents may resolve older BRD questions. Where the
documents appear inconsistent, record the discrepancy and request a baseline
update instead of choosing an interpretation in code.

## 2. First Claude Code prompt

Use Plan mode before allowing edits:

```text
Read CLAUDE.md, START_HERE.md, IMPLEMENTATION_BACKLOG.md, and every file under
docs/. Review the editable Draw.io diagrams as supporting models. Do not write
application code yet.

Produce:
1. a source-of-truth summary of Accepted policies;
2. a list of Proposed/Open or contradictory items;
3. a traceability table for backlog Phase 0 and Phase 1;
4. the smallest TDD execution plan;
5. the exact files you would change.

Do not invent decisions, commands, schemas, or framework versions.
Do not initialize Laravel until I approve the plan.
```

## 3. Sprint 0 review checkpoint

Before repository initialization, confirm:

- the MVP cut-line and non-goals are unchanged;
- the core state names and legal transitions are documented;
- the first data model supports constraints and immutable snapshots;
- open payment and cancellation remedies are marked as blockers for their later
  slices rather than guessed;
- the PostgreSQL concurrency proof is planned before payment and UI polish;
- every planned item names BRD and release-gate IDs;
- evidence has a defined output location.

Suggested evidence layout after initialization:

```text
docs/evidence/
├── sprint-0/
├── postgres/
├── concurrency/
├── payment-chaos/
├── security/
├── accessibility/
├── migration/
└── restore/
```

Generated reports and logs should be reproducible and must not contain secrets
or unnecessary personal data.

## 4. Initialize the application separately

The review package does not prescribe a Laravel installer command or framework
version. Select and record them explicitly before initialization.

Initialization must establish:

- Laravel with Vue 3/Inertia;
- PostgreSQL as the development and integration database;
- Pest/PHPUnit;
- Playwright;
- database-backed queue support;
- separate web, worker, and scheduler execution;
- environment examples without secrets;
- reproducible database reset and seed behavior;
- CI skeleton;
- verified project commands.

Until that work is complete, use these placeholders:

```text
<BOOTSTRAP_COMMAND>
<DEV_COMMAND>
<PHP_TEST_COMMAND>
<PHP_FOCUSED_TEST_COMMAND>
<POSTGRES_INTEGRATION_COMMAND>
<CONCURRENCY_COMMAND>
<INVARIANT_CHECK_COMMAND>
<PLAYWRIGHT_COMMAND>
<LINT_COMMAND>
<STATIC_ANALYSIS_COMMAND>
<CI_COMMAND>
```

Replace each placeholder only after the corresponding command has run
successfully. Record the verified command, environment assumptions, and result.

Do not treat SQLite, an in-memory repository, or mocked database locking as a
substitute for PostgreSQL.

## 5. Execute one backlog item at a time

For each item:

1. quote the item ID and traceability IDs;
2. state the expected behavior and failure mode;
3. write the smallest failing test;
4. run it and confirm the expected red result;
5. implement only enough to pass;
6. run the focused and affected suites;
7. capture required PostgreSQL or browser evidence;
8. update item status and evidence links;
9. stop if a requirement decision is missing.

Keep work in vertical slices and maintain a small work-in-progress limit.

## 6. First mandatory engineering proof

Before payment, confirmation, or polished seat-map UI, prove the hold protocol
against real PostgreSQL:

```text
100 coordinated requests
→ same published showtime
→ same available seat
→ exactly one successful hold
→ deterministic conflict for every loser
→ no duplicate active owner
```

Also run overlapping requests such as:

```text
A: A1 + A2
B: A2 + A3
C: A1 + A3
```

Every winning request owns its complete set. Every losing request owns none.

The proof must include:

- fixed random seed and run metadata;
- repeated runs;
- response counts and classifications;
- database/server version;
- schema/migration checksum;
- post-run invariant SQL;
- failure output if any run violates the invariant.

If the proof fails or flakes, stop. Payment work and UI polish remain blocked.

## 7. Handoff completion

A Claude Code session is complete only when it reports:

- backlog items attempted and completed;
- requirement and release-gate IDs covered;
- files changed;
- tests observed red, then green;
- exact commands executed and their outcomes;
- evidence paths;
- remaining blockers and open decisions;
- scope intentionally not implemented.

Do not accept “should pass,” “appears correct,” or unsupported
“production-ready” claims.
