# Seatly MVP Policy and Operational Baseline

**Version:** 1.0  
**Approved by:** Product Owner  
**Approval date:** 2026-08-10  
**Scope:** Portfolio/demo MVP

These defaults are production-minded engineering targets, not claims of Malaysian legal compliance or a real cinema operator's policy. Revalidate them with accessibility, security, legal, and operator stakeholders before commercial production use.

## 1. Accessible and companion seats

### Classification and layout

Each fixed hall seat is classified as `standard`, `accessible`, or `companion`.

Pairing is represented relationally in the fixed hall layout:

```text
seat_companion_links
- accessible_seat_id  UNIQUE FK seats
- companion_seat_id   UNIQUE FK seats
```

A pair contains exactly one accessible seat and zero or one adjacent companion seat. A seat cannot belong to multiple pairs. The UI identifies seat type and pairing using text and semantics, not colour alone.

### Eligibility and privacy

- Selecting an accessible seat requires an unticked self-declaration confirming that the customer or someone in the booking requires the accessible seating option.
- Store only the acknowledgement flag, timestamp, and policy version.
- Do not request or store disability type, diagnosis, medical evidence, government ID, or supporting documents.
- The declaration applies only to the current booking.
- An accessible seat may be booked without a companion.

### Companion rules

- A companion seat may be held or booked only with its linked accessible seat in the same booking and showtime.
- Selecting an accessible seat does not automatically add the companion; offer an explicit add-companion option.
- Removing or expiring the accessible seat also removes or releases its linked companion seat.
- If the companion is unavailable, the accessible seat remains bookable.
- No arbitrary nearby-seat substitution, staff override, individual seat swap, or partial post-confirmation cancellation in MVP.
- Whole-booking cancellation follows the approved two-hour cutoff policy.

The server enforces declaration, pairing, and all-or-none allocation during hold and confirmation using one PostgreSQL transaction. Direct API requests and stale clients cannot bypass the rules.

## 2. Locale, timezone, and money

| Item | Approved default |
|---|---|
| Customer/HTML locale | `en-MY` |
| Fallback locale | `en` |
| Venue/business timezone | `Asia/Kuala_Lumpur` |
| Application/server/database timezone | `UTC` |
| PostgreSQL timestamp type | `timestamptz` |
| Currency | `MYR` |
| Currency display | `RM 12.50` using `Intl.NumberFormat('en-MY')` |
| Money storage | Integer minor units (sen) in `BIGINT`; never floating point |

Store immutable checkout price snapshots with amount and ISO currency code. Evaluate venue-facing cancellation/showtime boundaries in `Asia/Kuala_Lumpur`, while persisting authoritative instants in UTC.

## 3. Performance and capacity targets

### Load profile

- 300 simultaneously active customers.
- Seat-map polling every 5 seconds with ±20% jitter.
- Approximately 60 polling requests per second.
- 70 RPS normal mixed peak.
- 100 RPS burst sustained for 10 minutes.
- Hidden tabs or repeated errors back off polling to 10–30 seconds.
- No WebSockets for MVP.

Suggested mixed load: 70% availability polling, 15% catalogue/seat reads, 10% hold/release writes, and 5% checkout/simulated-payment actions.

### API-boundary p95 targets

| Operation | p95 target |
|---|---:|
| Health/static cached response | ≤100 ms |
| Availability poll | ≤250 ms |
| Catalogue/seat-map read | ≤350 ms |
| Seat hold/release | ≤500 ms |
| Checkout/booking creation | ≤750 ms |
| Simulated payment callback | ≤1,000 ms |
| Database time per normal request | ≤100 ms |

Generic server errors must remain below 1% under the normal mixed peak. These are engineering objectives, not contractual SLAs.

### Availability and workers

- Monthly availability objective: 99.5%, single region/AZ acceptable for MVP.
- Worker lag: p95 ≤5 seconds and p99 ≤15 seconds.
- Alert when the oldest queued job exceeds 30 seconds for 5 minutes.
- Payment, hold-expiry, and retry jobs must be idempotent.

### Core contention gate

Send 100 concurrent hold requests for one available showtime-seat within approximately one second:

- exactly one request succeeds;
- 99 receive deterministic conflict;
- zero duplicate active holds or confirmed bookings;
- p95 response time ≤750 ms;
- all responses complete within 2 seconds;
- database invariants remain valid after retries and repeated seeded runs.

## 4. Recovery and backup

| Scope | RPO | RTO |
|---|---:|---:|
| PostgreSQL booking/account data | 15 minutes | 4 hours |
| Non-reproducible uploaded assets | 24 hours | 8 hours |
| Application code/config rebuilt from Git and automation | N/A | 4 hours |

RTO completes only after health checks, login, booking creation, worker startup, and QR behavior pass—not merely when PostgreSQL starts.

### Backup baseline

- Encrypted daily PostgreSQL base backup plus continuous WAL archiving at least every 5 minutes.
- Retain 7 daily, 4 weekly, and 3 monthly restore points, with a hard maximum age of 90 days.
- Use a separate backup bucket/account inaccessible to normal application credentials.
- Enable encryption, versioning, and 14-day immutability/object lock.
- Never back up plaintext `.env` files.
- Alert after 26 hours without a base backup, 15 minutes without WAL archival, checksum failure, or storage pressure.
- Weekly integrity/decryptability check.
- Monthly isolated point-in-time restore test.
- Quarterly timed disaster-recovery drill.
- Keep restore-test reports for 12 months.

## 5. Data retention and deletion

| Data | Approved MVP default |
|---|---|
| Accounts | After 24 months inactivity, notify; disable and anonymise/delete personal fields 30 days later. User-requested deletion disables immediately and targets anonymisation within 7 days, subject to documented incident/booking hold. |
| Bookings | Keep identifiable until 12 months after showtime ends, then anonymise; retain only non-identifying aggregates. |
| Simulated payments | Keep minimal reference, state, amount, and timestamps; never real card/bank credentials; anonymise with booking after 12 months. |
| Callback fields | Minimum parsed/redacted fields for 30 days; incident-related failures up to 90 days while incident remains open. |
| Idempotency records | 30 days; key hash, request fingerprint, result reference, and timestamps only. |
| QR tokens | Store hash only; expire at showtime end +4 hours; delete hash/validation metadata 7 days later; revoke on cancellation/reissue. |
| Audit logs | 180 days; no names, emails, QR values, callback bodies, or secrets. |
| Application/access logs | 30 days; selected redacted incident records may be held up to 180 days. |
| Backups | Maximum 90 days; never queried as a historical archive. |
| Deletion manifest | Encrypted internal subject ID and deletion status for 120 days, used to reapply deletions after restore. |

Retention jobs run daily, are idempotent, and emit aggregate counts without deleted personal values. After a restore, keep Seatly offline until the deletion manifest and retention jobs have been replayed and verified.

## 6. Release and revisit gates

Before release, verify layout-link integrity, direct-API enforcement, pair contention, keyboard/screen-reader journey, no medical-data collection, performance/load evidence, backup restoration, and retention-job behavior.

Review this baseline every six months and immediately when real payments, tax invoices, real operator contracts, additional accessibility data, legal advice, multi-region deployment, a security incident, failed restore, or materially higher traffic is introduced.
