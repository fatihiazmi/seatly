# Seatly Sprint 0 Exit Checklist

**Sprint:** 0 — Discovery and Foundation  
**Review status:** Pending artifact validation  
**Laravel application scaffold:** Explicitly excluded from this review package

## Approval scope of these ticks

Every tick below records **Product Owner approval only**, given 2026-08-10.
Sponsor, Project Manager, Business Analyst, Technical Lead, QA, Security,
Accessibility, and Operations sign-offs are all still pending in `BRD.md` §19, and
`D20` — naming those approvers — is open (`RC-12`).

In a solo project one person may hold several roles, but `BRD.md` §4.2 requires
Business Acceptance, Technical Recommendation, QA Evidence, and Release
Authorization to be recorded **separately**. Sprint 0 has one of the four.

## Product baseline

- [x] Product scope and MVP cut-line approved.
- [x] Public browsing; authentication from the hold request onward; guest checkout deferred (`ADR-014`).
- [x] Maximum eight seats per booking.
- [x] Five-minute server-authoritative hold; no extension.
- [x] One MYR price per showtime; money stored in integer sen.
- [x] Cancellation allowed until two hours before showtime; eligible seats reopen while showtime remains on sale.
- [x] Late payment never steals a seat; unresolved money enters reconciliation/refund-required state.
- [x] Display-only QR; scanner/check-in deferred.
- [x] Accessible/companion-seat baseline approved.
- [x] Locale, performance, recovery, backup, and retention baselines approved.

## Engineering contract

- [x] Laravel + Vue 3/Inertia + PostgreSQL selected.
- [x] Modular monolith selected.
- [x] PostgreSQL is the final seat-allocation authority.
- [x] Materialized `showtime_seats` inventory selected.
- [x] Database queue and transactional outbox selected.
- [x] Short polling selected; WebSockets deferred.
- [x] Simulated asynchronous payment selected.
- [x] Production release gates documented.
- [x] Business process documented in BPMN and editable Draw.io.
- [x] Domain state-model review candidate produced.
- [x] Logical ERD specification and editable Draw.io review candidate produced.
- [x] Requirement-to-artifact traceability matrix produced.
- [x] Baseline reconciled; contradictions resolved or downgraded, not silently chosen (`S0-01`, `BASELINE_RECONCILIATION.md`).
- [ ] Domain state models reviewed and accepted by Product Owner/Tech Lead.
- [ ] Logical ERD reviewed and accepted by Product Owner/Tech Lead/DBA.
- [x] Detailed payment timeout/contradictory-result/failure-release transitions approved before payment implementation (`ADR-015`).
- [x] Cancellation cutoff boundary and its source approved (`ADR-016`).
- [x] Detailed showtime-cancellation remedies and admin authority approved before cancellation implementation (`ADR-017`).

## Development foundation

These items intentionally remain for the implementation workspace and are not bundled as generated Laravel code:

- [ ] Initialize Git repository.
- [ ] Initialize current stable Laravel application.
- [ ] Configure PostgreSQL-only development and test environments.
- [ ] Add Vue 3/Inertia and authentication foundation.
- [ ] Establish Pint/static-analysis/test/Playwright commands.
- [ ] Establish CI using real PostgreSQL.
- [ ] Add factories, deterministic fixtures, and concurrency harness.

## Hard stop gates

Do not proceed to payment or UI polish until a repeatable real-PostgreSQL test proves:

```text
100 concurrent requests
→ same showtime
→ same seat
→ exactly one successful hold
→ 99 deterministic conflicts
→ zero duplicate ownership
→ zero partial multi-seat hold
```

Production remains No-Go until every P0 release gate has reproducible evidence.

## Still open at Sprint 0 exit

Neither blocks an implementation slice:

- `D14` — QR payload detail and rotation policy, needed before the `P5-03` QR slice.
- `D20` — named approvers per governance role.
- Hall and seat versioning rules after a showtime has been published (`FR-038`,
  `FR-041`), needed before destructive catalogue edits in `C2-03`.
- `BO-08` — the exact accessibility standard to certify against, as distinct
  from the WCAG 2.2 AA-oriented journey already approved.
