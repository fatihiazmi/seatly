# Seatly Sprint 0 Review Decisions

**Review candidate:** 2026-08-10
**Decision owner:** Product Owner
**Status:** Closed — all ten items decided 2026-08-10

The approved product, locale, accessibility, performance, recovery, backup, and retention baselines remain unchanged.

The baseline reconciliation of 2026-08-10
([`BASELINE_RECONCILIATION.md`](BASELINE_RECONCILIATION.md)) did **not** touch
these ten items. It only made the schema able to hold their outcomes, which is
deliberately not the same as approving the transitions into them. The decisions
below were taken separately, in a review session on the same date, and are
recorded in full as `ADR-015`, `ADR-016` and `ADR-017` in
[`DECISIONS.md`](DECISIONS.md).

## Outcomes

| ID | Proposed default | Outcome |
|---|---|---|
| PD-01 | Payment callback deadline equals the existing hold expiry; no second customer timer. | **ACCEPTED, AMENDED.** Deadline unchanged. `PAY-01` gains a guard rejecting checkout unless `transaction_timestamp() < expires_at - interval '60 seconds'`, returning `HOLD_TOO_CLOSE_TO_EXPIRY`. |
| PD-02 | No final callback by hold expiry → payment `TIMED_OUT`, hold expires, reconciliation opens; timeout is not provider failure. | **ACCEPTED, EXTENDED.** Unchanged for `PENDING`. New `PAY-05b`: an attempt still `CREATED` at expiry → `FAILED`, reason `NEVER_SUBMITTED`, no reconciliation case. |
| PD-03 | Keep timed-out/reconciliation attempts active for 24 hours, then close operationally as `UNRESOLVED`; later callbacks remain recorded. | **ACCEPTED, EXTENDED.** Window unchanged. New `PAY-11b`: `UNRESOLVED` plus a valid late success → `LATE_SUCCESS_REFUND_REQUIRED`, reopening the case. |
| PD-04 | First valid final provider outcome committed in PostgreSQL wins the normal effect; timeout is not a final outcome. | **ACCEPTED** as written. Provider-sequence ordering with a settling window was considered and rejected. |
| PD-05 | Later contradictory callbacks never reverse committed booking/cancellation/inventory state; record reconciliation. | **ACCEPTED, NEW RECORD.** Cases move into a `reconciliation_cases` table — the third record type §7.1 always required. |
| PD-06 | Valid explicit payment failure immediately releases an active hold while showtime remains on sale. | **AMENDED.** The hold stays `ACTIVE`; up to three attempts per hold until `expires_at`; the third failure releases it. |
| PD-07 | Cancellation is eligible only when PostgreSQL time is strictly earlier than showtime start minus two hours; equality is ineligible. | **ACCEPTED, SOURCE AMENDED.** Boundary and strict inequality unchanged. The interval is not a literal: it reads a per-booking snapshot of `showtimes.cancellation_cutoff_minutes`, default 120. |
| PD-08 | One `admin` capability may cancel a showtime with mandatory controlled reason and audit record. | **ACCEPTED, SPLIT NAMED.** `ADMIN` holds the irreversible commands; `OPERATOR` holds catalogue, showtime CRUD and inspection. No approval chain. |
| PD-09 | Showtime cancellation releases holds, blocks unsold seats, prevents later confirmation, marks confirmed bookings `SHOWTIME_CANCELLED`, revokes QR, and opens reconciliation where needed. | **AMENDED.** `ST-04` additionally requires `transaction_timestamp() < starts_at`. "Where needed" replaced by one case per confirmed booking, reason `SHOWTIME_CANCELLED_REFUND_DUE`. |
| PD-10 | Showtime cancellation is terminal in MVP; reschedule/reopen by creating a new showtime. | **ACCEPTED, CONTROL ADDED.** Terminality unchanged. The cancel command is two-step: impact preview, then confirmation quoting the showtime reference. |

## Defects found during review

Four gaps were latent in the model and are fixed by the same decisions:

1. `CREATED` had no exit at hold expiry — `PAY-05` covered only `PENDING`.
2. `UNRESOLVED` had no exit at all, so a genuinely paid-but-unbooked customer
   could be filed as "we stopped waiting".
3. `reconciliation_cases` was required by `DOMAIN_STATE_MODELS.md` §7.1 and
   absent from the ERD. Two scenarios cannot be expressed without it, because in
   both the payment is and must remain `SUCCEEDED`.
4. `payments.attempt_number` and its unique constraint were unreachable while
   PD-06 released the hold on the first failure.

A fifth was introduced by the reconciliation pass itself: removing the stored
`ENDED` showtime state under `RC-03` left `ST-04` able to cancel a screening that
had already happened. `ADR-017` closes it.

## Effect on implementation

Payment implementation and showtime-cancellation implementation are unblocked.
Backlog items `P5-01`, `P5-04` and `O6-03` move from `BLOCKED` to `READY`.

`D14` (QR payload detail and rotation) and `D20` (named approvers per governance
role) remain open. Neither blocks a slice.
