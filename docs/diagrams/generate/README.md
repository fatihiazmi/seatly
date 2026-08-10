# Diagram generators

The three `.drawio` files in the parent folder are **generated**, not hand-drawn.
Edit the script, re-run it, and commit the regenerated `.drawio`.

```bash
python3 erd.py
python3 state_machines.py
python3 booking_process.py
```

Each script prints `verify: clean` on success, or a list of problems.

## Why generated

draw.io's own edge routers do not avoid obstacles. `entityRelationEdgeStyle` is a
three-segment horizontal-first router, and `orthogonalEdgeStyle` without explicit
waypoints re-routes at render time — both will draw a connector straight through
the middle of a table. The previous hand-written versions of these files had
`page="1"` (which tiles a wide diagram into A4 fragments) and not a single edge
with fixed anchors, so every connector was at the mercy of that router.

These scripts compute every waypoint with a grid A* around the boxes, then check
the result.

## Dependency

`dgen.py` comes from the `drawio-diagrams` Claude Code skill and is not vendored
here. The scripts look for it at `~/.claude/skills/drawio-diagrams`; override with:

```bash
DGEN_PATH=/path/to/drawio-diagrams python3 erd.py
```

## Conventions worth keeping

- Positions on multiples of 20 — the router grid is 20px, and an off-grid box
  edge produces sub-pixel diagonal jogs.
- Column gutters of ~300px and row gaps of ~130px, or the router has nowhere to
  run and reports `UNROUTED`.
- Section captions are kept narrow. A full-width caption sits in the band long
  return edges use, and the checker treats it as an obstacle.
- `booking_process.py` puts the BPMN pool bands on a separate `bg` layer. Every
  message flow crosses a pool by definition, so the bands must not be obstacles.
- Never claim a diagram is done without a clean `verify` — the render is not
  visible from here, and valid XML says nothing about whether the picture reads.
