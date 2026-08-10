# Seatly Sprint 0 Review Package Manifest

**Package date:** 2026-08-10  
**Purpose:** Review Sprint 0 product, domain, data, delivery, release, and Claude Code implementation artifacts.  
**Application scaffold:** Not included.

## Start here

1. `START_HERE.md`
2. `CLAUDE.md`
3. `IMPLEMENTATION_BACKLOG.md`
4. `docs/SPRINT_0_REVIEW_DECISIONS.md`
5. `docs/SPRINT_0_EXIT_CHECKLIST.md`

## Product and governance

- `README.md`
- `docs/BRD.md`
- `docs/PROJECT_BRIEF.md`
- `docs/DELIVERY_TIMELINE.md`
- `docs/DECISIONS.md`
- `docs/MVP_POLICY_BASELINE.md`
- `docs/RELEASE_GATES.md`
- `docs/BASELINE_RECONCILIATION.md`

## Sprint 0 engineering artifacts

- `docs/DOMAIN_STATE_MODELS.md`
- `docs/LOGICAL_ERD.md`
- `docs/TRACEABILITY_MATRIX.md`
- `docs/SPRINT_0_REVIEW_DECISIONS.md`
- `docs/SPRINT_0_EXIT_CHECKLIST.md`

## Diagrams

- `docs/diagrams/seatly-booking-process.drawio`
- `docs/diagrams/seatly-logical-erd.drawio`
- `docs/diagrams/seatly-state-machines.drawio`
- `docs/bpmn/seatly-booking-process.bpmn`

The three `.drawio` files are generated from `docs/diagrams/generate/`. Edit the
script and re-run it; do not hand-edit the XML. See that folder's `README.md`.

## Deliberate exclusions

The package contains no Laravel/PHP application scaffold and therefore no:

- `artisan`
- `composer.json`
- `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `storage/`, `tests/`, or `vendor/`
- framework-generated JavaScript or build configuration
- `.env`, credentials, API keys, production data, or personal demo data

Use Claude Code Plan mode with the prompt in `START_HERE.md`. Do not initialize Laravel until the review plan and proposed PD-01–PD-10 decisions are handled explicitly.
