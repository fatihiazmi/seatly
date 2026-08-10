# Decision closure sweep — 2026-08-10

A sweep of every item still marked Open, Proposed or TBC across the document set,
and what happened to each.

## Closed by citation — stale register entries, not live decisions

`BRD.md` §18 still listed these as unresolved and "required by G0", but each was
already covered by an Accepted `PP` row approved on 2026-08-10. Same class as
`RC-13`: stale wording, substantive approval recorded elsewhere. No new decision
was taken.

| ID | Was | Closed by |
|---|---|---|
| `D2` | Maximum seat count and scope | `PP-02`, scoped by `RULE-003`/`RULE-011` |
| `D3` | Hold duration, start instant, rounding, clock | `PP-03` + `ADR-004`; no rounding arises, expiry is a `timestamptz` comparison |
| `D4` | No-extension policy, interruption treatment | `PP-04`; interruption covered by `ADR-015` |
| `D5` | Currency, tax/fee, rounding, price snapshot | `PP-11` + `ADR-005` + `LOGICAL_ERD.md` §5; tax/fee engines excluded by §8 |
| `D15` (versioning half) | Hall/seat versioning | `ADR-019` |
| `RULE-014` | Currency/tax/fee source | `PP-11`; tax/fee an explicit non-goal |

## Closed by decision

| ID | Outcome |
|---|---|
| `D20` | All roles held by Fatihi, named per role in `BRD.md` §19. **Naming is not approving** — Business Acceptance, Technical Recommendation, QA Evidence and Release Authorization stay four separate records, due at G3, G5 and G6 respectively. Their Decision column remains `Pending` because the evidence does not exist yet. |
| `BO-08` | **WCAG 2.2 Level AA, critical journey only** — browse, seat selection, hold countdown, payment, confirmation, cancellation. Operator and admin screens are out of certification scope. |
| `FR-041` venue half | `ADR-020` — `venues.is_active` frozen while any hall in the venue has an upcoming published showtime. |

## Deferred by decision, not by oversight

| ID | Outcome |
|---|---|
| `D14` | QR payload, privacy and rotation deferred to the `P5-03` slice in Phase 5. `PP-09` already fixes QR as display-only and not an admission credential. Settling the payload before booking and payment exist would decide it against a design that has not been built. |

## Remaining assumptions

`BRD.md` §17 still carries project assumptions marked Open or Proposed —
reviewer availability, representative catalogue data, the load profile, and a
production-like restore environment. These are delivery assumptions for a solo
project rather than product or architecture decisions, and none gates a code
slice. They fall due with the Phase 7 evidence pack.

## ADR-020 verification

```text
       venue       | venue_frozen |  code  | hall_frozen | layout_version
-------------------+--------------+--------+-------------+----------------
 Seatly Mid Valley | t            | HALL-1 | t           |             61
 Seatly Mid Valley | t            | HALL-2 | f           |             61
```

HALL-2 has no upcoming published showtime of its own, so its layout is editable,
while the venue is frozen because HALL-1 is still selling. Refused at the
database, not only through Eloquent:

```text
ERROR:  venue 1: cannot change activation while one of its halls has an
        upcoming published showtime
CONTEXT:  PL/pgSQL function venues_structural_guard() line 5 at RAISE
```

152 tests, 309 assertions, Pint and PHPStan clean.
