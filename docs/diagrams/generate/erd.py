#!/usr/bin/env python3
"""Seatly logical ERD — regenerated to match LOGICAL_ERD.md v1.1."""
import os
import sys
import pathlib

# dgen.py ships with the drawio-diagrams skill, not with this repo.
sys.path.insert(0, os.environ.get(
    "DGEN_PATH", os.path.expanduser("~/.claude/skills/drawio-diagrams")))
from dgen import Page, mxfile, verify  # noqa: E402

DIAGRAMS = pathlib.Path(__file__).resolve().parent.parent

OUT = str(DIAGRAMS / "seatly-logical-erd.drawio")

IDENT = ("#dae8fc", "#6c8ebf")   # identity / authorization
CATLG = ("#d5e8d4", "#82b366")   # catalogue and fixed layout
INVEN = ("#fff2cc", "#d6b656")   # showtimes, inventory, holds
MONEY = ("#e1d5e7", "#9673a6")   # payments, bookings
RELBL = ("#f8cecc", "#b85450")   # reliability and traceability

COL = [80, 780, 1480, 2180]
ROW = [140, 640, 1140, 1640, 2140, 2640]

p = Page("Seatly Logical ERD", "erd")
p.free_text("t", (80, 40), 1600, 40,
            "Seatly — Logical ERD  (LOGICAL_ERD.md v1.1)")
p.free_text("st", (80, 80), 2400, 30,
            "PostgreSQL authoritative · timestamptz in UTC · MYR integer sen · "
            "RC-02…RC-08 and ADR-015/016/017 applied 2026-08-10", fs=13, bold=False)

# ---- row 0 : identity ------------------------------------------------------
p.table("users", "users", (COL[0], ROW[0]), IDENT, [
    "PK  id : bigint",
    "UQ  public_id : uuid",
    "UQ  lower(email)",
    "    status : ACTIVE|DISABLED|ANONYMISED",
    "    last_active_at : timestamptz",
])
p.table("role_user", "role_user", (COL[1], ROW[0]), IDENT, [
    "PK/FK  user_id",
    "PK/FK  role_id",
])
p.table("roles", "roles", (COL[2], ROW[0]), IDENT, [
    "PK  id : smallint",
    "UQ  code : CUSTOMER|OPERATOR|ADMIN",
    "    ADMIN adds cancel + block/unblock",
])
p.table("idempotency_keys", "idempotency_keys", (COL[3], ROW[0]), RELBL, [
    "PK  id : bigint",
    "UQ  scope + principal + key_hash",
    "    request_fingerprint : char(64)",
    "    status : IN_PROGRESS|COMPLETED|FAILED",
    "    result_type / result_id",
    "    expires_at  (30-day retention)",
])

# ---- row 1 : catalogue and fixed layout ------------------------------------
p.table("venues", "venues", (COL[0], ROW[1]), CATLG, [
    "PK  id : bigint",
    "UQ  public_id : uuid",
    "    timezone : Asia/Kuala_Lumpur",
    "    is_active : boolean",
])
p.table("halls", "halls", (COL[1], ROW[1]), CATLG, [
    "PK  id : bigint",
    "FK  venue_id",
    "UQ  (venue_id, code)",
    "    layout_version : integer",
    "    is_active : boolean",
])
p.table("seats", "seats", (COL[2], ROW[1]), CATLG, [
    "PK  id : bigint",
    "FK  hall_id",
    "UQ  (hall_id, row_label, seat_number)",
    "UQ  (hall_id, sort_order)",
    "    seat_type : STANDARD|ACCESSIBLE|COMPANION",
    "    is_active : boolean",
])
p.table("seat_companion_links", "seat_companion_links", (COL[3], ROW[1]), CATLG, [
    "PK/FK  accessible_seat_id",
    "UQ/FK  companion_seat_id",
    "CK     accessible <> companion",
    "TRG    same hall, types, active, adjacent",
])

# ---- row 2 : showtimes and authoritative inventory -------------------------
p.table("movies", "movies", (COL[0], ROW[2]), CATLG, [
    "PK  id : bigint",
    "UQ  public_id : uuid",
    "    title",
    "    runtime_minutes : smallint",
    "    is_active : boolean",
])
p.table("showtimes", "showtimes", (COL[1], ROW[2]), INVEN, [
    "PK  id : bigint",
    "FK  movie_id",
    "FK  hall_id",
    "    status : DRAFT|PUBLISHED|CANCELLED",
    "    starts_at / ends_at : timestamptz",
    "    price_minor : bigint / currency",
    "    cancellation_cutoff_minutes : 120",
    "EXCL  no overlap per hall (PUBLISHED)",
    "    version : integer",
])
p.table("showtime_seats", "showtime_seats", (COL[2], ROW[2]), INVEN, [
    "PK  (showtime_id, seat_id)",
    "    status : AVAILABLE|HELD|CONFIRMED|BLOCKED",
    "FK  hold_id     — current owner only",
    "FK  booking_id  — current owner only",
    "    hold_expires_at : timestamptz",
    "    price_minor / currency",
    "    blocked_reason_code",
    "    version : integer",
])
p.table("outbox_events", "outbox_events", (COL[3], ROW[2]), RELBL, [
    "PK  id : uuid",
    "UQ  event_id",
    "    aggregate_type / id / version",
    "    event_type / payload : jsonb",
    "    status : PENDING|DELIVERING|",
    "             DELIVERED|DEAD_LETTER",
    "    available_at / attempt_count",
])

# ---- row 3 : holds and payment ---------------------------------------------
p.table("holds", "holds", (COL[0], ROW[3]), INVEN, [
    "PK  id : bigint",
    "FK  user_id",
    "FK  showtime_id",
    "    status : ACTIVE|CONFIRMED|EXPIRED|RELEASED",
    "CK  seat_count between 1 and 8",
    "CK  expires_at = created_at + 5 min",
    "    accessible_acknowledged + version",
])
p.table("hold_items", "hold_items", (COL[1], ROW[3]), INVEN, [
    "PK  (hold_id, seat_id)",
    "FK  (hold_id, showtime_id)",
    "FK  (showtime_id, seat_id)",
    "    seat label / row / number snapshot",
    "    seat_type snapshot",
    "    price_minor / currency",
])
p.table("payments", "payments", (COL[2], ROW[3]), MONEY, [
    "PK  id : bigint",
    "FK  hold_id",
    "UQ  (hold_id, attempt_number)",
    "UQ  (provider, provider_payment_id)",
    "CK  attempt_number between 1 and 3",
    "    status : 8 values — see ERD 2.5",
    "    amount_minor / currency",
    "    reconciliation_reason_code",
])
p.table("payment_callback_events", "payment_callback_events", (COL[3], ROW[3]), MONEY, [
    "PK  id : bigint",
    "UQ  (provider, provider_event_id)",
    "FK  payment_id  (nullable)",
    "    signature_valid / payload_hash",
    "    disposition : 17 values (RC-07)",
    "    received_at / processed_at",
    "    correlation_id : uuid",
])

# ---- row 4 : bookings, cancellation, audit ---------------------------------
p.table("bookings", "bookings", (COL[0], ROW[4]), MONEY, [
    "PK  id : bigint",
    "UQ  reference",
    "UQ  hold_id",
    "UQ  payment_id",
    "FK  user_id / showtime_id",
    "    status : CONFIRMED|CUSTOMER_CANCELLED|",
    "             ADMIN_CANCELLED|SHOWTIME_CANCELLED",
    "    cancellation_cutoff_minutes_snapshot",
    "    immutable snapshots + qr_token_hash",
])
p.table("booking_items", "booking_items", (COL[1], ROW[4]), MONEY, [
    "PK  (booking_id, seat_id)",
    "FK  (booking_id, showtime_id)",
    "FK  (showtime_id, seat_id)",
    "    seat label / row / number snapshot",
    "    seat_type snapshot",
    "    price_minor / currency",
])
p.table("cancellations", "cancellations", (COL[2], ROW[4]), RELBL, [
    "PK  id : bigint",
    "    type : CUSTOMER_BOOKING|ADMIN_BOOKING|SHOWTIME",
    "FK  booking_id   (nullable)",
    "FK  showtime_id  (nullable)",
    "    state : REQUESTED|APPLIED|REJECTED",
    "    reason_code / request_fingerprint",
    "UQ  one APPLIED per booking, per showtime",
])
p.table("audit_events", "audit_events", (COL[3], ROW[4]), RELBL, [
    "PK  id : bigint",
    "UQ  event_id : uuid",
    "    actor_type / actor_user_id",
    "    action / subject_type / subject_id",
    "    correlation_id / result / reason_code",
    "    metadata : jsonb, redacted",
    "    append-only, 180-day retention",
])

p.table("reconciliation_cases", "reconciliation_cases", (COL[0], ROW[5]), RELBL, [
    "PK  id : bigint",
    "UQ  public_id : uuid",
    "FK  payment_id   (nullable)",
    "FK  booking_id   (nullable)",
    "CK  at least one target present",
    "    state : OPEN|RESOLVED|UNRESOLVED",
    "    reason_code / opened_at / closed_at",
    "UQ  one OPEN case per payment",
])

ONE, MANY, ZOM = "ERmandOne", "ERoneToMany", "ERzeroToMany"

for child, parent, sa, ea, label in [
    ("role_user", "users", MANY, ONE, "granted to"),
    ("role_user", "roles", MANY, ONE, "grants"),
    ("halls", "venues", MANY, ONE, "in"),
    ("seats", "halls", MANY, ONE, "in"),
    ("seat_companion_links", "seats", ZOM, ONE, "accessible side"),
    ("showtimes", "movies", MANY, ONE, "shows"),
    ("showtimes", "halls", MANY, ONE, "runs in"),
    ("showtime_seats", "showtimes", MANY, ONE, "materialised for"),
    ("showtime_seats", "seats", MANY, ONE, "is seat"),
    ("holds", "users", MANY, ONE, "held by"),
    ("holds", "showtimes", MANY, ONE, "for"),
    ("hold_items", "holds", MANY, ONE, "line of"),
    ("hold_items", "showtime_seats", MANY, ONE, "snapshot of"),
    ("payments", "holds", MANY, ONE, "pays for"),
    ("payment_callback_events", "payments", ZOM, ONE, "inbox for"),
    ("bookings", "holds", ZOM, ONE, "one per hold"),
    ("bookings", "payments", ZOM, ONE, "one per payment"),
    ("bookings", "showtimes", MANY, ONE, "for"),
    ("booking_items", "bookings", MANY, ONE, "line of"),
    ("booking_items", "showtime_seats", MANY, ONE, "snapshot of"),
    ("cancellations", "bookings", ZOM, ONE, "targets booking"),
    ("cancellations", "showtimes", ZOM, ONE, "targets showtime"),
    ("reconciliation_cases", "payments", ZOM, ONE, "money in doubt"),
    ("reconciliation_cases", "bookings", ZOM, ONE, "refund owed"),
]:
    p.edge(child, parent, sa, ea, label)

# second companion foreign key, drawn separately from the accessible one
p.edge("seat_companion_links", "seats", ZOM, ONE, "companion side",
       colour="#82b366")

p.note("legend", (80, 3140), 2440, 250,
       "Reading this diagram\n\n"
       "• showtime_seats is the only current seat-allocation authority. hold_id and booking_id "
       "name the CURRENT owner: confirmation clears hold_id, blocking clears both (RC-02).\n"
       "• History is never read from a live pointer — hold_items, booking_items, bookings.hold_id "
       "and audit_events preserve it.\n"
       "• Every requested seat locks in seat_id order behind the showtime lock; a hold is all-or-none.\n"
       "• payment_callback_events deduplicates on (provider, provider_event_id); a callback is recorded "
       "even when it cannot change the payment.\n"
       "• cancellations covers customer, admin and showtime cancellation in one idempotent decision "
       "record (RC-06).\n"
       "• reconciliation_cases is the only place a money problem can live when the payment is "
       "and must stay SUCCEEDED — a contradicted success, or a booking killed by showtime "
       "cancellation.\n"
       "• The cancellation cutoff is snapshotted onto the booking, so changing the policy cannot "
       "re-term a booking already sold.\n"
       "• Not drawn, to keep the graph legible: users ← cancellations.requested_by_user_id and "
       "users ← audit_events.actor_user_id, both ON DELETE SET NULL.\n"
       "• Laravel jobs / failed_jobs are infrastructure tables and are out of the logical model.",
       fill="#f5f5f5", stroke="#666666")

problems = p.wire()
if problems:
    print("ROUTER:", *problems, sep="\n  ")
open(OUT, "w").write(mxfile([p.xml()]))
print("verify:", verify(OUT) or "clean")
