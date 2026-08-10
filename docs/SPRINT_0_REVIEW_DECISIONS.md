# Seatly Sprint 0 Review Decisions

**Review candidate:** 2026-08-10  
**Decision owner:** Product Owner

The approved product, locale, accessibility, performance, recovery, backup, and retention baselines remain unchanged.

The baseline reconciliation of 2026-08-10
([`BASELINE_RECONCILIATION.md`](BASELINE_RECONCILIATION.md)) did **not** touch
these ten items. Schema states and reason codes now exist to hold their
outcomes, which is deliberately not the same as approving the transitions into
them.

## Decisions requested from this review

The state-model specialist recommends the following smallest portfolio-MVP defaults. They are **Proposed**, not silently accepted:

| ID | Proposed default |
|---|---|
| PD-01 | Payment callback deadline equals the existing hold expiry; no second customer timer. |
| PD-02 | No final callback by hold expiry → payment `TIMED_OUT`, hold expires, reconciliation opens; timeout is not provider failure. |
| PD-03 | Keep timed-out/reconciliation attempts active for 24 hours, then close operationally as `UNRESOLVED`; later callbacks remain recorded. |
| PD-04 | First valid final provider outcome committed in PostgreSQL wins the normal effect; timeout is not a final outcome. |
| PD-05 | Later contradictory callbacks never reverse committed booking/cancellation/inventory state; record reconciliation. |
| PD-06 | Valid explicit payment failure immediately releases an active hold while showtime remains on sale. |
| PD-07 | Cancellation is eligible only when PostgreSQL time is strictly earlier than showtime start minus two hours; equality is ineligible. |
| PD-08 | One `admin` capability may cancel a showtime with mandatory controlled reason and audit record. |
| PD-09 | Showtime cancellation releases holds, blocks unsold seats, prevents later confirmation, marks confirmed bookings `SHOWTIME_CANCELLED`, revokes QR, and opens reconciliation where needed; no real refund/notification. |
| PD-10 | Showtime cancellation is terminal in MVP; reschedule/reopen by creating a new showtime. |

## Review choices

For each PD item, record one of:

- `ACCEPTED`
- `AMENDED` with exact replacement wording
- `DEFERRED` with implementation gate
- `REJECTED` with replacement direction

Until approved, payment implementation and showtime-cancellation implementation remain blocked. Catalogue, PostgreSQL foundation, inventory materialization, and atomic hold/concurrency work may proceed after the remaining Sprint 0 artifacts are accepted.
