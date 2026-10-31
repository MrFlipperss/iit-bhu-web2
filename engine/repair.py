"""engine/repair.py  [M3]  Minimum-movement repair after a disruption.

1. The change file is applied with the official validator's apply_disruption(), so the
   app and the judges' checker see exactly the same new data.
2. The same CP-SAT model as the timetable runs with the baseline as a hint and a cost
   of 1000 per moved session (day, start or room differs, exactly as the validator counts
   "sessions_moved"), then soft penalties.
3. Every difference is listed with a reason. Nothing is applied here: PHP stores the
   proposal and applies it only after an Admin approves it.
"""
from collections import defaultdict

import timetable
from core import Data, block, fmt_slot, official
from export import assign_of, schedule_json


def _change_index(dis):
    fac, room, added, removed = defaultdict(list), defaultdict(list), {}, {}
    for i, ch in enumerate(dis.get("changes", []), 1):
        t = ch["type"]
        if t == "faculty_unavailable":
            for d in ch["days"]:
                for p in ch["periods"]:
                    fac[(ch["faculty_id"], d, p)].append((i, ch.get("reason", "")))
        elif t == "room_unavailable":
            for d in ch["days"]:
                for p in ch["periods"]:
                    room[(ch["room_id"], d, p)].append((i, ch.get("reason", "")))
        elif t == "add_session":
            added[ch["session"]["id"]] = i
        elif t == "remove_session":
            removed[ch["session_id"]] = i
    return fac, room, added, removed


def propose(data, dis, baseline, team="CIT team", time_limit=25.0):
    after = official.apply_disruption(data, dis)
    D = Data(after)
    base = assign_of(baseline)
    res = timetable.best_timetable(D, time_limit=time_limit, baseline=base, reserve=False)
    if res["status"] != "ok":
        return {"status": res["status"], "reasons": res.get("reasons", []), "stats": res["stats"], "changes": []}
    new = res["assign"]
    fac_ch, room_ch, added, removed = _change_index(dis)

    occupant = {}
    for sid, (d, t, r) in new.items():
        for p in block(t, D.sess[sid]["duration"]):
            occupant[(r, d, p)] = sid

    changes = []
    for s in D.sessions:
        sid = s["id"]
        n = new[sid]
        if sid not in base:
            changes.append({"session_id": sid, "change_kind": "added", "from": None, "to": n,
                            "reason": f"New session (change {added.get(sid, '?')}) placed at {fmt_slot(n[0], n[1])} in {n[2]}"})
            continue
        b = base[sid]
        if tuple(b) == tuple(n):
            continue
        per = block(b[1], s["duration"])
        why = []
        for p in per:
            for i, reason in fac_ch.get((s["faculty_id"], b[0], p), []):
                why.append(f"{s['faculty_id']} unavailable {b[0]} (change {i}{': ' + reason if reason else ''})")
            for i, reason in room_ch.get((b[2], b[0], p), []):
                why.append(f"{b[2]} closed {b[0]} (change {i}{': ' + reason if reason else ''})")
        if not why:
            took = sorted({occupant.get((b[2], b[0], p)) for p in per} - {None, sid})
            why.append(f"Knock-on move: its old slot is now used by {', '.join(took)}" if took
                       else "Knock-on move so the faculty, group and room rules all still hold")
        changes.append({"session_id": sid, "change_kind": "room_only" if b[:2] == n[:2] else "moved",
                        "from": b, "to": n, "reason": "; ".join(dict.fromkeys(why))})
    for sid, b in base.items():
        if sid not in D.sess:
            changes.append({"session_id": sid, "change_kind": "removed", "from": b, "to": None,
                            "reason": f"Removed by change {removed.get(sid, '?')}"})

    sched = schedule_json(D, team, new)
    report = official.run(after, sched, baseline)
    moved = [c for c in changes if c["change_kind"] in ("moved", "room_only")]
    return {"status": "ok", "schedule": sched, "changes": changes, "dataset_after": after,
            "summary": {"sessions_moved": len(moved),
                        "time_changed": sum(1 for c in moved if c["change_kind"] == "moved"),
                        "room_only_changed": sum(1 for c in moved if c["change_kind"] == "room_only"),
                        "added": sum(1 for c in changes if c["change_kind"] == "added"),
                        "removed": sum(1 for c in changes if c["change_kind"] == "removed")},
            "report": report, "stats": res["stats"]}
