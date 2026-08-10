#!/usr/bin/env python3
"""Seatly state machines — regenerated to match DOMAIN_STATE_MODELS.md v1.1."""
import os
import sys
import pathlib

# dgen.py ships with the drawio-diagrams skill, not with this repo.
sys.path.insert(0, os.environ.get(
    "DGEN_PATH", os.path.expanduser("~/.claude/skills/drawio-diagrams")))
from dgen import Page, mxfile, verify  # noqa: E402

DIAGRAMS = pathlib.Path(__file__).resolve().parent.parent

OUT = str(DIAGRAMS / "seatly-state-machines.drawio")

LIVE = "#ffffff"          # non-terminal state
TERM = "#d5e8d4"          # terminal state
TERM_S = "#82b366"
LIVE_S = "#4d4d4d"
GATED = "#d79b00"         # exception / reconciliation path

BW, BH = 300, 80
AX = [80, 540, 1000, 1460]        # left block slots
BX = [2020, 2480, 2940, 3400]     # right block slots

p = Page("Seatly State Machines", "sm")
p.free_text("t", (80, 40), 2000, 40,
            "Seatly — Authoritative State Machines  (DOMAIN_STATE_MODELS.md v1.1)")
p.free_text("st", (80, 80), 3400, 30,
            "PostgreSQL commit order decides every race. Dashed amber transitions are exception and "
            "reconciliation paths. PD-01–PD-10 resolved 2026-08-10 — ADR-015/016/017.", fs=13, bold=False)


def header(bid, x, y, text):
    # kept narrow: a wide caption sits in the band the router uses for
    # long return edges, and reads as an obstacle to the checker
    p.free_text(bid, (x, y - 56), 620, 34, text, fs=18)


def state(bid, label, x, y, terminal=False):
    p.box(bid, label, (x, y), BW, BH,
          fill=TERM if terminal else LIVE,
          stroke=TERM_S if terminal else LIVE_S,
          fs=14, style_extra="fontStyle=1;")


def t(a, b, label, gated=False, sides=None):
    p.edge(a, b, "none", "block", label, sides=sides,
           dashed=gated, colour=GATED if gated else "#4d4d4d")


BELOW = ("S", "S")   # long return edges run under their row, clear of captions


# ===== Showtime (ST-01 … ST-06) ============================================
Y = 220
header("h_st", AX[0], Y, "Showtime — §4")
state("st_draft", "DRAFT", AX[0], Y)
state("st_pub", "PUBLISHED", AX[1], Y)
state("st_can", "CANCELLED", AX[2], Y, terminal=True)
t("st_draft", "st_pub", "ST-03 publish → materialise inventory")
t("st_pub", "st_can", "ST-04 cancel \u2014 ADMIN, db_now < starts_at,\nimpact preview confirmed", gated=True)
p.note("n_st", (AX[3], Y - 10), 380, 100,
       "No ENDED state (RC-03). On sale is derived:\n"
       "PUBLISHED AND db_now < starts_at",
       fill="#f5f5f5")

# ===== Inventory (INV-01 … INV-11) =========================================
Y = 640
header("h_inv", AX[0], Y, "Showtime-seat inventory — §5")
state("inv_av", "AVAILABLE", AX[0], Y)
state("inv_hd", "HELD", AX[1], Y)
state("inv_cf", "CONFIRMED", AX[2], Y)
state("inv_bl", "BLOCKED", AX[3], Y)
t("inv_av", "inv_hd", "INV-02 acquire hold")
t("inv_hd", "inv_cf", "INV-03 confirm — clears hold_id")
t("inv_hd", "inv_av", "INV-04/05 expire or release", sides=BELOW)
t("inv_cf", "inv_av", "INV-06 eligible cancellation", sides=BELOW)
t("inv_av", "inv_bl", "INV-07/09 operator or showtime block")
t("inv_bl", "inv_av", "INV-08 unblock", sides=BELOW)
t("inv_hd", "inv_bl", "INV-10 showtime cancelled", gated=True)
t("inv_cf", "inv_bl", "INV-11 showtime cancelled — clears booking_id", gated=True)

# ===== Hold (HLD-01 … HLD-07) ==============================================
Y = 1060
header("h_hld", AX[0], Y, "Hold — §6")
state("hld_ac", "ACTIVE", AX[0], Y)
state("hld_cf", "CONFIRMED", AX[1], Y, terminal=True)
state("hld_ex", "EXPIRED", AX[2], Y, terminal=True)
state("hld_rl", "RELEASED", AX[3], Y, terminal=True)
t("hld_ac", "hld_cf", "HLD-02 booking commits before expiry")
t("hld_ac", "hld_ex", "HLD-03 db_now >= expires_at")
t("hld_ac", "hld_rl", "HLD-04/06 abandon or showtime cancel;\nHLD-05 third payment failure only", gated=True)

# ===== Booking (BKG-01 … BKG-05) ===========================================
Y = 1480
header("h_bkg", AX[0], Y, "Booking — §8")
state("bkg_cf", "CONFIRMED", AX[0], Y)
state("bkg_cc", "CUSTOMER_CANCELLED", AX[1], Y, terminal=True)
state("bkg_ac", "ADMIN_CANCELLED", AX[2], Y, terminal=True)
state("bkg_sc", "SHOWTIME_CANCELLED", AX[3], Y, terminal=True)
t("bkg_cf", "bkg_cc", "BKG-02 owner + snapshotted cutoff", gated=True)
t("bkg_cf", "bkg_ac", "BKG-03 ADMIN, bookings.cancel", gated=True)
t("bkg_cf", "bkg_sc", "BKG-04 showtime cancellation", gated=True)

# ===== Payment attempt (PAY-01 … PAY-15) ===================================
Y1, Y2 = 220, 540
header("h_pay", BX[0], Y1, "Payment attempt — §7")
state("pay_cr", "CREATED", BX[0], Y1)
state("pay_pd", "PENDING", BX[1], Y1)
state("pay_sc", "SUCCEEDED", BX[2], Y1, terminal=True)
state("pay_fl", "FAILED", BX[3], Y1, terminal=True)
state("pay_un", "UNRESOLVED", BX[0], Y2, terminal=True)
state("pay_to", "TIMED_OUT", BX[1], Y2)
state("pay_rc", "RECONCILIATION_REQUIRED", BX[2], Y2)
state("pay_ls", "LATE_SUCCESS_REFUND_REQUIRED", BX[3], Y2, terminal=True)
t("pay_cr", "pay_pd", "PAY-02 initiation durable")
t("pay_pd", "pay_sc", "PAY-03 valid success + confirmation commits")
t("pay_pd", "pay_fl", "PAY-04 valid failure \u2014 hold survives\nunless this is the third", gated=True)
t("pay_pd", "pay_to", "PAY-05 hold expiry, no final callback", gated=True)
t("pay_pd", "pay_rc", "PAY-08 showtime cancelled", gated=True)
t("pay_to", "pay_ls", "PAY-06 late success", gated=True)
t("pay_cr", "pay_fl", "PAY-05b expiry, never submitted \u2014 no case", gated=True, sides=BELOW)
t("pay_un", "pay_ls", "PAY-11b late success reopens the case", gated=True)
t("pay_to", "pay_fl", "PAY-07 late failure", gated=True)
t("pay_to", "pay_un", "PAY-11 24h window closes", gated=True)
t("pay_rc", "pay_ls", "PAY-09 valid success", gated=True)
t("pay_rc", "pay_fl", "PAY-10 valid failure", gated=True)
t("pay_fl", "pay_ls", "PAY-14 later success — never a booking", gated=True)
t("pay_sc", "pay_sc", "PAY-13 later failure recorded; booking stands")

# ===== Cancellation (CAN-01 … CAN-05) ======================================
Y = 940
header("h_can", BX[0], Y, "Cancellation decision — §9  (table: cancellations)")
state("can_rq", "REQUESTED", BX[0], Y)
state("can_ap", "APPLIED", BX[1], Y, terminal=True)
state("can_rj", "REJECTED", BX[2], Y, terminal=True)
t("can_rq", "can_ap", "CAN-02 eligible; target transition commits")
t("can_rq", "can_rj", "CAN-03 ineligible or unauthorised")
t("can_ap", "can_ap", "CAN-04 retry returns original")
t("can_rj", "can_rj", "CAN-05 retry returns original")
p.note("n_can", (BX[3], Y - 10), 380, 120,
       "One table for all three types (RC-06):\n"
       "CUSTOMER_BOOKING, ADMIN_BOOKING, SHOWTIME.\n"
       "Reused key with a different target or\n"
       "fingerprint is an idempotency conflict.",
       fill="#f5f5f5")

# ===== Outbox (OBX-01 … OBX-08) ============================================
Y = 1360
header("h_obx", BX[0], Y, "Transactional outbox — §11")
state("obx_pd", "PENDING", BX[0], Y)
state("obx_dg", "DELIVERING", BX[1], Y)
state("obx_dv", "DELIVERED", BX[2], Y, terminal=True)
state("obx_dl", "DEAD_LETTER", BX[3], Y)
t("obx_pd", "obx_dg", "OBX-02 claim lease, SKIP LOCKED")
t("obx_dg", "obx_dv", "OBX-03 destination acknowledges")
t("obx_dg", "obx_pd", "OBX-04/05 retry or lease expiry", sides=BELOW)
t("obx_dg", "obx_dl", "OBX-06 exhausted or non-retryable")
t("obx_dl", "obx_pd", "OBX-07 audited replay", sides=BELOW)

# ===== Reconciliation case (ADR-015) =======================================
Y = 1780
header("h_rec", BX[0], Y, "Reconciliation case — ADR-015")
state("rec_op", "OPEN", BX[0], Y)
state("rec_rs", "RESOLVED", BX[1], Y, terminal=True)
state("rec_un", "UNRESOLVED", BX[2], Y)
t("rec_op", "rec_rs", "a valid final outcome arrives")
t("rec_op", "rec_un", "24h closure job", gated=True)
t("rec_un", "rec_op", "PAY-11b late success reopens", gated=True, sides=BELOW)
p.note("n_rec", (BX[3], Y - 10), 380, 130,
       "Opened by PAY-05/06/08/09/11b/13 and by\n"
       "showtime cancellation. PAY-05b opens none —\n"
       "initiation was never sent, so nothing is\n"
       "ambiguous. Nothing in MVP resolves a case\n"
       "by hand; operator scope is inspection only.",
       fill="#f5f5f5")

p.note("n_lock", (80, 2200), 1680, 260,
       "Global lock order — every competing action uses it\n\n"
       "  1. showtime row\n"
       "  2. showtime_seats, ordered by seat_id ASC\n"
       "  3. holds, then payments, then bookings, by id ASC\n"
       "  4. cancellation / reconciliation rows\n"
       "  5. audit and outbox rows last, in the same transaction\n\n"
       "Never acquire the showtime lock after a seat, hold, payment or booking lock.\n"
       "Retry the whole transaction on deadlock, never a partial mutation, and never\n"
       "under a new command identity.",
       fill="#fff2cc", stroke="#d6b656")

p.note("n_key", (2020, 2200), 1680, 260,
       "Reading this diagram\n\n"
       "  white box      non-terminal state\n"
       "  green box      terminal state\n"
       "  solid arrow    normal path\n"
       "  dashed amber   exception, cancellation or reconciliation path\n\n"
       "A worker tick, HTTP request or callback receipt is only a request to attempt\n"
       "a transition — never proof that it is still eligible. Guards are re-evaluated\n"
       "after locks are held, using PostgreSQL time.",
       fill="#f5f5f5", stroke="#999999")

problems = p.wire()
if problems:
    print("ROUTER:", *problems, sep="\n  ")
open(OUT, "w").write(mxfile([p.xml()]))
print("verify:", verify(OUT) or "clean")
