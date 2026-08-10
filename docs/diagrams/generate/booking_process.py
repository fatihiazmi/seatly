#!/usr/bin/env python3
"""Seatly booking BPMN — regenerated for RC-01 (public browsing, login at hold).

Pool bands live on their own background layer. Every message flow has to cross
them by definition, so they must not act as obstacles — putting them on a layer
other than "1" keeps them out of both the router and the checker.
"""
import os
import sys
import pathlib
from xml.sax.saxutils import escape

# dgen.py ships with the drawio-diagrams skill, not with this repo.
sys.path.insert(0, os.environ.get(
    "DGEN_PATH", os.path.expanduser("~/.claude/skills/drawio-diagrams")))
from dgen import Page, mxfile, verify  # noqa: E402

DIAGRAMS = pathlib.Path(__file__).resolve().parent.parent

OUT = str(DIAGRAMS / "seatly-booking-process.drawio")

p = Page("Seatly Booking BPMN", "bpmn")

LANE_X, LANE_W = 60, 4560
lane_cells = []


def lane(lid, label, y, h, fill, stroke):
    lane_cells.append(
        f'        <mxCell id="{lid}" value="{escape(label)}" '
        f'style="swimlane;horizontal=0;startSize=40;html=1;rounded=0;'
        f'fillColor={fill};strokeColor={stroke};fontStyle=1;fontSize=15;'
        f'swimlaneFillColor=none;" vertex="1" parent="bg">\n'
        f'          <mxGeometry x="{LANE_X}" y="{y}" width="{LANE_W}" '
        f'height="{h}" as="geometry" />\n'
        f'        </mxCell>')


p.free_text("t", (60, 40), 2200, 40,
            "Seatly — End-to-End Customer Booking  (BPMN 2.0 analytical model)")
p.free_text("st", (60, 82), 3600, 30,
            "PostgreSQL is authoritative. Browsing is public; authentication begins at the hold "
            "request (ADR-014). 5-minute hold, max 8 seats, no extension.", fs=13, bold=False)

lane("lane_customer",  "Customer",                           140,  400, "#dae8fc", "#6c8ebf")
lane("lane_service",   "Seatly — Customer Service",          560,  170, "#e1d5e7", "#9673a6")
lane("lane_inventory", "Seatly — Inventory and Booking",      750,  400, "#d5e8d4", "#82b366")
lane("lane_payment",   "Seatly — Payment and Reconciliation", 1170, 400, "#fff2cc", "#d6b656")
lane("lane_provider",  "Simulated Payment Provider",         1590, 400, "#f8cecc", "#b85450")
lane("lane_admin",     "Cinema Operator / Admin",            2010, 170, "#f5f5f5", "#666666")

EVENT = "shape=ellipse;fontSize=11;"
GATE = "shape=rhombus;fontSize=12;"
WAIT = "shape=hexagon;perimeter=hexagonPerimeter2;fixedSize=1;"
BOLD = "fontStyle=1;"
END = EVENT + "strokeWidth=3;"

CUS, SVC, INV, PAY, PRV, ADM = ("#6c8ebf", "#9673a6", "#82b366",
                                "#d6b656", "#b85450", "#666666")


def task(bid, label, x, y, w, stroke, fill="#ffffff", extra=""):
    p.box(bid, label, (x, y), w, 90, fill=fill, stroke=stroke, fs=12,
          style_extra=extra)


# ---- customer lane ---------------------------------------------------------
R1, R2 = 180, 400
task("c_start", "Start", 180, R1, 100, CUS, extra=EVENT)
task("c_browse", "Browse movies, venue,\ndate and showtime\n(no account needed)", 380, R1, 220, CUS)
task("c_select", "Select seats on the\naccessible seat map", 700, R1, 200, CUS)
task("c_login", "Log in or register\n— required from here on", 1000, R1, 240, CUS, "#dae8fc")
task("c_hold", "Request seat hold", 1340, R1, 200, CUS)
task("c_result", "Receive hold outcome", 1640, R1, 200, CUS)
task("c_gw_hold", "Held?", 1940, R1, 140, CUS, extra=GATE)
task("c_review", "Review seats, expiry\nand price snapshot", 2180, R1, 220, CUS)
task("c_pay", "Initiate checkout", 2500, R1, 200, CUS)
task("c_pending", "Payment pending", 2800, R1, 200, CUS)
task("c_wait", "Await outcome /\nrequest status", 3100, R1, 220, CUS, extra=WAIT)
task("c_final", "Confirmed?", 3420, R1, 140, CUS, extra=GATE)
task("c_confirm", "View booking reference\nand QR", 3660, R1, 240, "#82b366", "#d5e8d4")
task("c_end_ok", "End", 4000, R1, 100, CUS, extra=END)

task("c_reselect", "Conflict: refresh map\nand select again", 1940, R2, 240, "#d79b00", "#ffe6cc")
task("c_noncon", "View failed / expired /\ncancelled / in review", 3660, R2, 240, "#b85450", "#f8cecc")
task("c_end_no", "End", 4000, R2, 100, CUS, extra=END)

# ---- customer service lane -------------------------------------------------
R3 = 590
task("s_browse", "Return catalogue and\naccessible seat map\n(unauthenticated read)", 380, R3, 240, SVC)
task("s_hold_out", "Send held or\nconflict outcome", 1640, R3, 220, SVC)
task("s_pending", "Send pending\nacknowledgement", 2800, R3, 200, SVC)
task("s_status", "Authorise and return\ncurrent status", 3100, R3, 220, SVC)
task("s_outcome", "Send authoritative\nbooking outcome", 3420, R3, 240, SVC)

# ---- inventory and booking lane -------------------------------------------
R4, R5 = 790, 1010
task("s_validate_hold", "Validate owner, showtime,\nseat limit, accessible rules", 1340, R4, 220, INV)
task("s_atomic_hold", "Atomic all-or-none hold\n(showtime lock, then seat_id order)",
     1660, R4, 240, INV, extra=BOLD)
task("s_gw_hold", "Commit?", 2000, R4, 140, INV, extra=GATE)
task("s_snapshot", "Create price snapshot\nand authoritative expiry", 2240, R4, 220, INV)
task("s_wait_checkout", "Await checkout\nor hold expiry", 2560, R4, 220, INV, extra=WAIT)
task("s_atomic_confirm", "Revalidate and atomically\nconfirm booking", 3660, R4, 240, INV, extra=BOLD)
task("s_gw_confirm", "Committed?", 4000, R4, 140, INV, extra=GATE)
task("s_qr", "Create or recover reference\nand QR linkage", 4240, R4, 220, "#82b366", "#d5e8d4")

task("s_hold_timer", "Hold expires\n5 min, DB clock", 2560, R5, 200, INV, extra=EVENT)
task("s_validate_co", "Validate hold, ownership,\nshowtime and retry key", 2880, R5, 220, INV)
task("s_cancel_event", "Showtime cancellation subprocess:\nprevent any later confirmation\n(PD-08/09, gated)",
     4240, R5, 240, "#b85450", "#f8cecc")

# ---- payment and reconciliation lane --------------------------------------
R6, R7 = 1210, 1430
task("s_create_payment", "Create or recover\nidempotent payment attempt", 2880, R6, 220, PAY)
task("s_send_payment", "Send simulated\npayment initiation", 3200, R6, 220, PAY)
task("s_wait_payment", "Await callback,\ntimeout or hold expiry", 3520, R6, 220, PAY, extra=WAIT)
task("s_callback", "Validate, correlate and\ndeduplicate callback", 3840, R6, 220, PAY)
task("s_gw_callback", "Outcome?", 4160, R6, 140, PAY, extra=GATE)

task("s_failure", "Record failure; apply\napproved hold policy (PD-06)", 2880, R7, 240,
     "#b85450", "#f8cecc")
task("s_late", "Record timeout / late / contradictory\n→ reconciliation (PD-02/04/05)",
     3220, R7, 280, "#d79b00", "#ffe6cc")
task("s_deadline", "Deadline = hold expiry\n(PD-01, gated)", 3600, R7, 220, "#d6b656",
     extra=EVENT)

# ---- provider lane ---------------------------------------------------------
R8, R9 = 1630, 1850
task("p_receive", "Receive or recover\npayment attempt", 3200, R8, 220, PRV)
task("p_scenario", "Choose\nscenario", 3520, R8, 160, PRV, extra=GATE)
task("p_callback", "Send success / failure / duplicate /\ndelayed / contradictory callback",
     3780, R8, 280, PRV)
task("p_none", "No callback\n(timeout scenario)", 3780, R9, 280, "#b85450", "#f8cecc")

# ---- admin lane ------------------------------------------------------------
R10 = 2040
task("a_start", "Start", 3200, R10, 100, ADM, extra=EVENT)
task("a_review", "Authorise and review\ncancellation impact", 3360, R10, 240, ADM)
task("a_cancel", "Submit authoritative showtime\ncancellation (PD-08, gated)", 3700, R10, 260,
     "#b85450", "#f8cecc")
task("a_end", "End", 4060, R10, 100, ADM, extra=END)


def seq(a, b, label="", colour="#333333", sides=None):
    p.edge(a, b, "none", "block", label, colour=colour, sides=sides)


def msg(a, b, label, sides=None):
    p.edge(a, b, "none", "open", label, dashed=True, colour="#666666", sides=sides)


for a, b, lbl in [
    ("c_start", "c_browse", ""), ("c_browse", "c_select", ""),
    ("c_select", "c_login", ""), ("c_login", "c_hold", ""),
    ("c_hold", "c_result", ""), ("c_result", "c_gw_hold", ""),
    ("c_gw_hold", "c_review", "Yes"), ("c_gw_hold", "c_reselect", "No"),
    ("c_review", "c_pay", ""), ("c_pay", "c_pending", ""),
    ("c_pending", "c_wait", ""), ("c_wait", "c_final", ""),
    ("c_final", "c_confirm", "Yes"), ("c_final", "c_noncon", "No"),
    ("c_confirm", "c_end_ok", ""), ("c_noncon", "c_end_no", ""),
]:
    seq(a, b, lbl, colour="#6c8ebf")
seq("c_reselect", "c_select", "retry", colour="#d79b00", sides=("W", "S"))

for a, b, lbl in [
    ("s_validate_hold", "s_atomic_hold", ""), ("s_atomic_hold", "s_gw_hold", ""),
    ("s_gw_hold", "s_snapshot", "Committed"), ("s_snapshot", "s_wait_checkout", ""),
    ("s_wait_checkout", "s_hold_timer", "Expiry"),
    ("s_wait_checkout", "s_validate_co", "Checkout"),
    ("s_atomic_confirm", "s_gw_confirm", ""), ("s_gw_confirm", "s_qr", "Yes"),
]:
    seq(a, b, lbl, colour="#2d7600")

for a, b, lbl in [
    ("s_create_payment", "s_send_payment", ""), ("s_send_payment", "s_wait_payment", ""),
    ("s_wait_payment", "s_callback", "Callback"), ("s_callback", "s_gw_callback", ""),
    ("s_wait_payment", "s_deadline", "Timeout"),
]:
    seq(a, b, lbl, colour="#b08a00")
seq("s_gw_callback", "s_atomic_confirm", "Valid timely success", colour="#2d7600")
seq("s_gw_callback", "s_failure", "Explicit failure", colour="#b85450")
seq("s_gw_callback", "s_late", "Late / invalid / contradictory", colour="#d79b00")

seq("p_receive", "p_scenario", "", colour="#b85450")
seq("p_scenario", "p_callback", "Callback scenarios", colour="#b85450")
seq("p_scenario", "p_none", "No callback", colour="#b85450")

seq("a_start", "a_review", "", colour="#666666")
seq("a_review", "a_cancel", "", colour="#666666")
seq("a_cancel", "a_end", "", colour="#666666")

msg("c_browse", "s_browse", "Browse request / result")
msg("c_hold", "s_validate_hold", "Hold request")
msg("s_hold_out", "c_result", "Held / conflict")
msg("c_pay", "s_validate_co", "Checkout")
msg("s_send_payment", "p_receive", "Payment initiation")
msg("p_callback", "s_callback", "Async callback")
msg("s_pending", "c_pending", "Pending")
msg("s_outcome", "c_final", "Outcome")
msg("c_wait", "s_status", "Status request / result")
msg("a_cancel", "s_cancel_event", "Cancellation event")

seq("s_validate_co", "s_create_payment", "Validated checkout", colour="#666666")
seq("s_qr", "s_outcome", "Send customer result", colour="#666666")
seq("s_failure", "s_outcome", "Failure result", colour="#666666")
seq("s_late", "s_outcome", "Review result", colour="#666666")

p.note("legend", (60, 2260), 2100, 200,
       "BPMN legend\n\n"
       "  ellipse        start, intermediate and end event\n"
       "  rounded box    task\n"
       "  diamond        exclusive gateway\n"
       "  hexagon        event-based wait\n"
       "  dashed arrow   message flow between pools\n"
       "  solid arrow    sequence flow or internal handoff\n"
       "  red / amber    gated on PD-01–PD-10, not implementable yet",
       fill="#f5f5f5", stroke="#666666")

p.note("open_note", (2260, 2260), 2360, 200,
       "Not executable yet\n\n"
       "Payment timeout, contradictory-outcome precedence, hold release after explicit failure, "
       "late-success remedy, the exact cancellation boundary and the reconciliation window are all "
       "still Proposed (PD-01–PD-10).\n\n"
       "The amber and red elements show where those decisions land. They are drawn so the shape of the "
       "process is reviewable — not so it can be built. Refinement to an executable model requires the "
       "approvals first.",
       fill="#fff2cc", stroke="#d6b656")

problems = p.wire()
if problems:
    print("ROUTER:", *problems, sep="\n  ")

page = p.xml()
# declare the background layer ahead of the default layer, so the pool bands
# render behind everything and stay out of the obstacle set
page = page.replace(
    '        <mxCell id="0" />\n',
    '        <mxCell id="0" />\n        <mxCell id="bg" parent="0" />\n', 1)
page = page.replace('      </root>\n', "\n".join(lane_cells) + "\n      </root>\n", 1)

open(OUT, "w").write(mxfile([page]))
print("verify:", verify(OUT, ignore_overlap_prefixes=("lane_",)) or "clean")
