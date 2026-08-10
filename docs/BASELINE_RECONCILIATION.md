# Seatly Baseline Reconciliation Record

**Document ID:** SEATLY-RECON-001
**Version:** 1.0
**Date:** 2026-08-10
**Backlog item:** S0-01 — Baseline reconciliation
**Status:** Applied to the Sprint 0 document set

This record is the output of backlog item `S0-01`. It lists every place where the
Sprint 0 documents contradicted each other, states which document won, and names
the files amended. Nothing here decides a gated policy: `PD-01`–`PD-10` remain
**Proposed** and payment/showtime-cancellation implementation stays blocked.

## 1. Reconciliation rules used

1. A **later dated approval** beats an earlier draft. The Product Owner baseline
   of 2026-08-10 (`MVP_POLICY_BASELINE.md`) outranks the 2026-08-09 brief, BRD,
   and decision drafts.
2. `DOMAIN_STATE_MODELS.md` is authoritative for **states, transitions, and
   guards**. `LOGICAL_ERD.md` is authoritative for **storage, keys, and
   constraints**. Where a schema `CHECK` would reject a required transition, the
   schema was amended, not the transition.
3. A **contradiction between two Accepted statements** is not resolved by
   picking one silently. It is either resolved by an explicitly recorded
   decision (`RC-01`) or downgraded to Open.
4. A **Proposed or Open item is never promoted** by this record.

## 2. Status vocabulary snapshot

| Class | Count | Where |
|---|---:|---|
| Accepted business policies | PP-01–PP-13 | `BRD.md` §5.2 |
| Accepted architecture decisions | ADR-001–ADR-014 | `DECISIONS.md` |
| Proposed, gated | PD-01–PD-10 | `SPRINT_0_REVIEW_DECISIONS.md`, `DOMAIN_STATE_MODELS.md` §2 |
| Open BRD decisions | D6–D15, D20 | `BRD.md` §18 |
| Resolved BRD decisions | D1, D16–D19 | `BRD.md` §18 |
| Open business rules | RULE-022–025 | `BRD.md` §8 |

Implementation consequence, unchanged by this record:

- **Unblocked:** Phase 0, Phase 1, Phase 2, Phase 3, Phase 4.
- **Blocked:** Phase 5 (payment) pending D6–D10 / PD-01–PD-06.
- **Blocked:** Phase 6 showtime-cancellation slices pending D13/D15 / PD-08–PD-10.
- Phase 6 booking-ownership and customer-cancellation slices depend on PD-07
  only for the exact boundary; ownership/authorization work (`O6-01`) is not gated.

## 3. Findings and resolutions

### RC-01 — Browse authentication contradicted itself

| | |
|---|---|
| **Conflict** | `RULE-010` "Customer must log in before browsing" was marked **Accepted**, while `BRD.md` §4.1 called the browse-login requirement **Open**, `D1` was unresolved, and `FR-001` required authentication only before hold, payment, booking, and cancellation. |
| **Impact** | Route-level auth policy for the entire catalogue and seat-map surface. Affects `C2-04`, `L4-03`, and the BPMN customer lane. |
| **Resolution** | **Product Owner decision, 2026-08-10: browsing is public.** Movies, venues, showtimes, and the read-only seat map are reachable without an account. Authentication is required at the moment a hold is requested, and for every transactional operation thereafter. |
| **Rationale** | Matches `FR-001` as written and `PP-01`, which defers guest *checkout*, not guest *browsing*. A reviewer can reach the seat map without registering. |
| **Applied** | `RULE-010` rewritten and kept Accepted; `BRD.md` §4.1 actor note corrected; `D1` marked Resolved; new `ADR-014`; `README.md` and `PROJECT_BRIEF.md` journeys re-ordered; BPMN customer lane re-sequenced and its pool renamed `Customer`. |
| **Not changed** | `PP-01`. Guest checkout remains deferred. A hold still requires a logged-in owner. |

### RC-02 — Inventory ownership `CHECK` rejected its own transitions

| | |
|---|---|
| **Conflict** | `LOGICAL_ERD.md` §2.3 required `CONFIRMED` inventory to satisfy `hold_id IS NOT NULL`. `DOMAIN_STATE_MODELS.md` §5.1 defines `CONFIRMED` as "no active `hold_id`", and `INV-03` clears the hold linkage on confirmation. |
| **Impact** | **Blocking.** The confirmation transaction (`P5-03`, `BKG-01`) would fail its own constraint on commit. |
| **Resolution** | Schema amended to match the state model: `CONFIRMED` requires `booking_id IS NOT NULL`, `hold_id IS NULL`, `hold_expires_at IS NULL`. Hold history is preserved by `hold_items` and `audit_events`, not by a live pointer. |
| **Second half** | `INV-11` (`CONFIRMED` → `BLOCKED` on showtime cancellation) was written conditionally — "if schema separates active owner from history". The schema does **not** separate them, so the behaviour is now stated plainly: the transition clears `booking_id`, sets `blocked_reason_code = 'SHOWTIME_CANCELLED'`, and the booking's own row moves to `SHOWTIME_CANCELLED` with `booking_items` retaining the seat snapshot. |
| **Applied** | `LOGICAL_ERD.md` §2.3; `DOMAIN_STATE_MODELS.md` §5.1 and `INV-11`. |

### RC-03 — Showtime had a fourth state in the schema only

| | |
|---|---|
| **Conflict** | `LOGICAL_ERD.md` §2.3 listed `showtimes.status` as `DRAFT`, `PUBLISHED`, `CANCELLED`, `ENDED`. `DOMAIN_STATE_MODELS.md` §4.1 defines three states and derives "on sale" from `status = PUBLISHED AND transaction_timestamp() < starts_at`. |
| **Impact** | An `ENDED` state implies a lifecycle job whose failure would make past showtimes look bookable — exactly the coupling the state model rejects. |
| **Resolution** | `ENDED` **removed**. Three states only. Past-showtime behaviour stays derived, so no worker is on the correctness path. |
| **Applied** | `LOGICAL_ERD.md` §2.3 status list and state-dependent checks; state-machine diagram unchanged (it never had `ENDED`). |

### RC-04 — Payment status enum was short by two values

| | |
|---|---|
| **Conflict** | `LOGICAL_ERD.md` §2.5 listed six statuses. `DOMAIN_STATE_MODELS.md` §7.2 defines eight; `LATE_SUCCESS_REFUND_REQUIRED` and `UNRESOLVED` were missing from the schema. |
| **Impact** | `PAY-06`, `PAY-09`, `PAY-11`, and `PAY-14` had no representable target state. |
| **Resolution** | Both values added to the `payments.status` `CHECK`. |
| **Gating note** | Adding the value does **not** approve the transition. Reaching `UNRESOLVED` still depends on `PD-03`; reaching `LATE_SUCCESS_REFUND_REQUIRED` still depends on `PD-02`/`PD-04`/`PD-05`. This follows `LOGICAL_ERD.md` §8: open policy is represented by explicit statuses and reason codes rather than decided in schema defaults. |
| **Applied** | `LOGICAL_ERD.md` §2.5. |

### RC-05 — Booking status enum was short by one value

| | |
|---|---|
| **Conflict** | `LOGICAL_ERD.md` §2.6 listed `CONFIRMED`, `CUSTOMER_CANCELLED`, `SHOWTIME_CANCELLED`. `DOMAIN_STATE_MODELS.md` §8.1 also defines `ADMIN_CANCELLED` (`BKG-03`). |
| **Impact** | Administrative booking cancellation had no storable state. |
| **Resolution** | `ADMIN_CANCELLED` added to the `bookings.status` `CHECK`. |
| **Gating note** | `BKG-03` remains gated behind `D15` and `PD-08`. The value exists; the command is not authorized for implementation. |
| **Applied** | `LOGICAL_ERD.md` §2.6. |

### RC-06 — Two incompatible cancellation models

| | |
|---|---|
| **Conflict** | `DOMAIN_STATE_MODELS.md` §9 specifies a cancellation record with types `CUSTOMER_BOOKING` / `ADMIN_BOOKING` / `SHOWTIME`, states `REQUESTED` / `APPLIED` / `REJECTED`, and nine rejection reasons. `LOGICAL_ERD.md` §2.6 specified `booking_cancellations` with a `source` column and an `outcome` column mixing states and reasons (`ALREADY_CANCELLED`, `INELIGIBLE`), and had **no table at all** for showtime cancellation, which §10.2 step 11 requires. |
| **Impact** | Idempotent-cancellation machinery (`CAN-01`–`CAN-05`) had no consistent home, and showtime cancellation could not create the record its own transaction specifies. |
| **Resolution** | Single table **`cancellations`** replaces `booking_cancellations`, covering all three types with a nullable `booking_id` and a nullable `showtime_id`. `state` uses the state-model vocabulary; the ERD's `ALREADY_CANCELLED` and `INELIGIBLE` become `reason_code` values under `state = 'REJECTED'`. |
| **Why one table** | The idempotent decision record is identical for all three types — actor, target, fingerprint, decision, correlation. Two tables would duplicate `CAN-01`–`CAN-05` and its partial unique index. |
| **Applied** | `LOGICAL_ERD.md` §2.6, §3, §4, §7; `DOMAIN_STATE_MODELS.md` §9 now names the table. |

### RC-07 — Two callback disposition vocabularies

| | |
|---|---|
| **Conflict** | `DOMAIN_STATE_MODELS.md` §7.3 lists 16 dispositions. `LOGICAL_ERD.md` §2.5 listed a different set of 7. |
| **Impact** | Callback classification tests (`P5-02`) could assert either vocabulary. `AC-017` traceability would be ambiguous. |
| **Resolution** | The state model's 16 values are canonical. One value, **`RECEIVED`**, is added as the pre-processing default written when the inbox row is inserted before the domain transaction — the ERD needed it and the state model had no name for it. Total: 17. |
| **Applied** | `LOGICAL_ERD.md` §2.5; `DOMAIN_STATE_MODELS.md` §7.3. |

### RC-08 — Payment timeout deadline had no storage

| | |
|---|---|
| **Conflict** | `PAY-05` guards on `db_now >= timeout_at`. No `timeout_at` column exists in `LOGICAL_ERD.md` §2.5. |
| **Impact** | Looks like a missing column; is actually a consequence of a gated decision. |
| **Resolution** | **No column added.** Under `PD-01` the deadline *is* `holds.expires_at`, so a second stored timer would be a duplicated authority. A note now records the dependency: if `PD-01` is amended to give payment an independent deadline, add `timeout_at timestamptz` at that point. |
| **Applied** | `LOGICAL_ERD.md` §2.5. |

### RC-09 — Sprint 0 both required and forbade Laravel initialization

| | |
|---|---|
| **Conflict** | `DELIVERY_TIMELINE.md` Sprint 0 deliverables included "Initialize Laravel repository and local PostgreSQL" and "Establish CI skeleton" in Week 1. `START_HERE.md` §4, `CLAUDE.md`, and `PACKAGE_MANIFEST.md` all state that initialization is deliberately excluded and gated on plan approval. |
| **Impact** | The two readings disagree about whether Sprint 0 exits with a repository. |
| **Resolution** | The exclusion wins — it appears in three documents including the Claude Code contract, and `SPRINT_0_EXIT_CHECKLIST.md` already lists initialization under "intentionally remain for the implementation workspace". Initialization moved to **Sprint 1 / backlog Phase 1 (`F1-01`–`F1-04`)**. |
| **Applied** | `DELIVERY_TIMELINE.md` Sprint 0 and Sprint 1 deliverables. |

### RC-10 — Gate weeks disagreed with sprint weeks

| | |
|---|---|
| **Conflict** | `BRD.md` §15 timed G0 at Weeks 1–2, G1 at 3–4, G2 at 5–7, G3 at 8–10. `DELIVERY_TIMELINE.md` reaches "product rules frozen" at Week 1, "foundation ready" Week 3, "catalogue operational" Week 5, and "concurrency proven" Week 7 — inside a 16-week plan whose later gates then match. |
| **Impact** | Two published schedules for the same gates. |
| **Resolution** | The timeline's milestone table wins; it is the plan of record and its later gates (G4–G6) already agree. `BRD.md` §15 gate timings restated as G0 W1, G1 W3, G2 W5, G3 W7–9, G4 W11–13, G5 W14–15, G6 W16. G3 is expressed as a range because the concurrency stop-gate lands at Week 7 and the accessible hold journey completes at Week 9. |
| **Applied** | `BRD.md` §15. |

### RC-11 — Seat-map polling cadence stated two ways

| | |
|---|---|
| **Conflict** | `README.md`, `PROJECT_BRIEF.md` §5, and `ADR-007` said "3–5 seconds with jitter". `MVP_POLICY_BASELINE.md` §3, approved a day later, says every 5 seconds with ±20% jitter — that is 4–6 seconds — and derives the ~60 RPS polling figure from it. |
| **Impact** | The load model in the approved baseline is computed from 5 s. Using 3 s would understate polling load by roughly 40%. |
| **Resolution** | The approved baseline wins. All three documents now read "approximately every 5 seconds with ±20% jitter (4–6 s)". |
| **Applied** | `README.md`, `PROJECT_BRIEF.md` §5, `DECISIONS.md` ADR-007. |

### RC-12 — Exit checklist over-stated its approvals

| | |
|---|---|
| **Conflict** | `SPRINT_0_EXIT_CHECKLIST.md` ticks the whole product baseline as approved. `BRD.md` §19 records a signature from the Product Owner only; Sponsor, PM, BA, Tech Lead, QA, Security, Accessibility, and Operations are all Pending, and `D20` (named approvers) is Open. |
| **Impact** | Sprint 0 could appear to have passed a multi-role gate it has not. |
| **Resolution** | Ticks retained for the items the Product Owner actually approved, with an explicit note that this is single-role approval and that `D20` is open. In a solo project one person may hold several roles, but `BRD.md` §4.2 requires Business Acceptance, Technical Recommendation, QA Evidence, and Release Authorization to be **recorded separately** — so the remaining sign-offs stay unticked. |
| **Applied** | `SPRINT_0_EXIT_CHECKLIST.md`. |

### RC-13 — Approval record narrower than the approved baseline

| | |
|---|---|
| **Conflict** | `BRD.md` §19 records "MVP policies PP-01–PP-09 approved". `BRD.md` §5.2 marks PP-01–PP-13 Accepted, `DECISIONS.md` ADR-013 records the locale/performance/recovery/retention approval on the same date, and `D16`–`D19` are marked Resolved 2026-08-10. |
| **Impact** | Stale wording only; the substantive approval is recorded in three other places. |
| **Resolution** | Signature note corrected to PP-01–PP-13. |
| **Applied** | `BRD.md` §19. |

## 4. Checked and found consistent

Recorded so the next reviewer does not re-derive them.

- The five-minute hold: `PP-03`, `RULE-012`, `FR-014`, `holds.expires_at` `CHECK`, and `HLD-01` all agree, including the "no extension" prohibition.
- Eight-seat maximum: `PP-02`, `RULE-011`, `FR-006`, `holds.seat_count` `CHECK`, `bookings.seat_count` `CHECK`.
- Lock order: `DOMAIN_STATE_MODELS.md` §12.1 and `LOGICAL_ERD.md` §6 are the same order. §6 additionally puts the idempotency/callback-inbox row first, which is a refinement, not a conflict.
- Money: integer minor units and `CHAR(3)` ISO currency throughout; no floating point anywhere in the document set.
- Time: `timestamptz` in UTC, venue timezone for display and policy explanation, PostgreSQL clock authoritative for every guard.
- QR: hash-only persistence, no PII, display-only, revoked on cancellation — consistent across `PP-09`, `RULE-017`, `MVP_POLICY_BASELINE.md` §5, and `bookings`.
- Uniqueness backstops in `DOMAIN_STATE_MODELS.md` §14 all have a counterpart in `LOGICAL_ERD.md` §2 except the cancellation record, which `RC-06` resolves.

## 5. Diagrams

All three `.drawio` files were regenerated. Two classes of problem were fixed.

**Content**, from this record:

- BPMN customer lane re-sequenced for `RC-01` — browse and seat selection now
  precede login, the pool is `Customer`, and the login task is the gate on the
  hold request.
- State-machine page gained the cancellation machine from
  `DOMAIN_STATE_MODELS.md` §9, which had no diagram, plus the payment
  transitions that were missing (`PAY-07`, `PAY-13`, `PAY-14`).
- ERD page renames `booking_cancellations` to `cancellations` per `RC-06` and
  corrects the `seat_companion_links` relationship, which was drawn as one
  edge where the schema has two distinct foreign keys.

**Rendering** — every page failed the draw.io readability check before this pass:

- `page="1"` on all three, so a wide diagram tiles into A4 fragments.
- Every edge on all three pages had no fixed anchors or waypoints, leaving
  routing to draw.io, which draws lines straight through boxes.
- Swimlane children were positioned as siblings overlapping their lane rather
  than as lane content.

All three now carry computed waypoints and pass
`dgen.py verify` with no findings.

## 6. Files amended

| File | Findings applied |
|---|---|
| `docs/BRD.md` | RC-01, RC-10, RC-13 |
| `docs/DECISIONS.md` | RC-01 (ADR-014), RC-11, plus this record referenced |
| `docs/LOGICAL_ERD.md` | RC-02, RC-03, RC-04, RC-05, RC-06, RC-07, RC-08 |
| `docs/DOMAIN_STATE_MODELS.md` | RC-02, RC-06, RC-07 |
| `docs/DELIVERY_TIMELINE.md` | RC-09 |
| `docs/MVP_POLICY_BASELINE.md` | unchanged — it is the winning source for RC-11 |
| `docs/SPRINT_0_EXIT_CHECKLIST.md` | RC-12, plus S0-01 completion |
| `docs/TRACEABILITY_MATRIX.md` | reconciliation row added |
| `README.md`, `docs/PROJECT_BRIEF.md` | RC-01, RC-11 |
| `IMPLEMENTATION_BACKLOG.md` | S0-01 status and evidence |
| `PACKAGE_MANIFEST.md` | this document listed |
| `docs/diagrams/*.drawio`, `docs/bpmn/*.bpmn` | RC-01, RC-06, plus rendering fixes |

## 7. Still open after this record

Unchanged by reconciliation. Listed so nothing here reads as progress on them.

| ID | Decision | Blocks |
|---|---|---|
| D6 / PD-01 | Simulator contract; callback deadline | `P5-01` |
| D7 / PD-02, PD-03 | Payment timeout; reconciliation window closure | `P5-01`, `P5-04` |
| D8 / PD-04, PD-05 | Contradictory outcome precedence | `P5-04` |
| D9 / PD-06 | Hold release after explicit payment failure | `P5-01` |
| D10 | Late-success remedy | `P5-04` |
| D11 / PD-07 | Exact cancellation boundary | `O6-02` boundary tests |
| D13 / PD-09, PD-10 | Showtime-cancellation effects and terminality | `O6-03` |
| D14 | QR payload and rotation detail | `P5-03` QR slice |
| D15 / PD-08 | Admin roles and cancellation authority | `O6-03`, `BKG-03` |
| D20 | Named approvers for each governance role | G0 multi-role sign-off |
