# Seatly Sprint 0 Traceability Matrix

**Status:** Review candidate  
**Purpose:** Connect approved requirements and Sprint 0 design artifacts to implementation and evidence gates.

| Concern | Requirement / decision sources | Sprint 0 artifact | First implementation backlog | Evidence / gate |
|---|---|---|---|---|
| Cinema-specific MVP scope | BRD §5; ADR-001/009; PP-01–13 | `PROJECT_BRIEF.md`, `MVP_POLICY_BASELINE.md` | S0-01, S0-02 | G0 baseline approval |
| Baseline consistency | BRD §17.3; all Sprint 0 artifacts | `BASELINE_RECONCILIATION.md` | S0-01 (done), S0-06 | G0 — no contradiction converted into code |
| Browse authentication boundary | RULE-010; FR-001/002; PP-01; ADR-014; D1 | `DECISIONS.md` ADR-014 | C2-04, H3-01, O6-01 | Authorization matrix — public read routes vs fail-closed write routes |
| PostgreSQL seat authority | BR-02/03; RULE-001–005; ADR-004 | `DOMAIN_STATE_MODELS.md` §§3–6/12; `LOGICAL_ERD.md` §§2.3/6 | H3-01–H3-07 | P0 invariants 1–6; G3 |
| Materialized showtime inventory | FR-003/004/007/038–041; ADR-005 | `LOGICAL_ERD.md` §§2.3/5 | C2-02–C2-04 | Publication and inventory integration evidence |
| All-or-none multi-seat hold | BR-04; RULE-003; AC-001/002 | `DOMAIN_STATE_MODELS.md` §§5–6/13 | H3-01/02/04/05 | Same-seat and overlapping-group reports |
| Five-minute expiry | PP-03/04; RULE-012/013; FR-011/014/015 | `DOMAIN_STATE_MODELS.md` §§3.2/6 | L4-01/02 | Expiry-boundary and delayed-worker evidence |
| Accessible/companion seats | PP-10; ADR-012; NFR-004 | `MVP_POLICY_BASELINE.md` §1; `LOGICAL_ERD.md` §§2.2/2.4 | C2-01, H3-01, L4-03 | Keyboard/screen-reader and pair-concurrency evidence |
| Idempotent payment callbacks | BR-06/14; FR-016–029; RULE-006/019 | `DOMAIN_STATE_MODELS.md` §7; `LOGICAL_ERD.md` §2.5 | P5-01–P5-05 | Payment-chaos and callback-inbox evidence |
| Atomic confirmation | BR-03/07; FR-022–024; AC-005 | `DOMAIN_STATE_MODELS.md` §§7–8/12.3 | P5-03 | Booking/inventory invariant SQL |
| Late payment reconciliation | PP-08; RULE-018; AC-007 | `DOMAIN_STATE_MODELS.md` §7 | P5-04 | Paid-but-unbooked reconciliation report |
| Customer cancellation | PP-06/07; FR-032–035; AC-013 | `DOMAIN_STATE_MODELS.md` §§8–9/12.4 | O6-02 | Boundary, retry and resale tests |
| Showtime cancellation race | RULE-020/021; FR-036; AC-011 | `DOMAIN_STATE_MODELS.md` §10/13 | O6-03 | Cancellation-vs-confirmation concurrency evidence |
| Transactional outbox | ADR-006; NFR-002/006/007/009 | `DOMAIN_STATE_MODELS.md` §11; `LOGICAL_ERD.md` §2.7 | P5-05 | Worker crash/replay/dead-letter evidence |
| Authorization/privacy | BR-11; NFR-003; AC-012/017 | `BRD.md`, `MVP_POLICY_BASELINE.md` | O6-01/O6-04 | IDOR matrix, scans and redacted audit evidence |
| Recovery/retention | PP-13; NFR-005 | `MVP_POLICY_BASELINE.md` §§4–5 | Phase 7 | Restore, RPO/RTO, deletion-manifest evidence |

## Status rule

An implementation item is `DONE` only when its requirement IDs, release gate, red/green tests, exact executed commands, and reproducible evidence paths are recorded. Code existence or coverage percentage alone is insufficient.
