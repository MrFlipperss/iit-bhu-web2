"""engine/exams.py  [M2]  Midsem exam schedule (MON-SAT x AM/PM).

Hard: every exam once; rooms that allow exams; seats = sum of half capacities >= size;
a room hosts one exam per slot; a group sits one exam per slot; groups that need step-free
access get accessible rooms (dropped only if that alone makes the plan impossible).
Soft (validator weight): 5 points for each extra exam a group has on the same day.
Then: fewest rooms in use, so each exam is in as few halls as possible.
"""
import os
import time
from collections import defaultdict

try:
    from ortools.sat.python import cp_model
except ImportError:
    cp_model = None


def solve(D, time_limit=15.0, accessible=True):
    t0 = time.time()
    exams = D.raw.get("exams", [])
    if not exams:
        return {"status": "ok", "exams": [], "stats": {"seconds": 0}}
    slots = [(d, s) for d in D.exam_days for s in D.exam_sessions]
    rooms = [r for r in D.rooms.values() if "exam" in r["allowed_uses"]]
    greedy_plan = solve_greedy(D, exams, slots, rooms, t0)
    if cp_model is None:
        return greedy_plan
    m = cp_model.CpModel()
    y, a = {}, {}
    for e in exams:
        need_acc = accessible and any(D.groups.get(g, {}).get("needs_accessible") for g in e["groups"])
        ok_rooms = [r for r in rooms if r["accessible"] or not need_acc]
        if sum(r["capacity"] // 2 for r in ok_rooms) < e["size"]:
            if accessible:
                return solve(D, time_limit, accessible=False)
            return {"status": "infeasible", "exams": [], "stats": {},
                    "reasons": [{"constraint": "H5", "entities": [e["id"]],
                                 "detail": f"{e['id']}: {e['size']} students but only {sum(r['capacity'] // 2 for r in ok_rooms)} exam seats exist"}]}
        for sl in slots:
            y[(e["id"], sl)] = m.NewBoolVar("")
            for r in ok_rooms:
                a[(e["id"], r["id"], sl)] = m.NewBoolVar("")
        m.AddExactlyOne(y[(e["id"], sl)] for sl in slots)
        for sl in slots:
            seats = [r["capacity"] // 2 * a[(e["id"], r["id"], sl)] for r in ok_rooms]
            m.Add(sum(seats) >= e["size"] * y[(e["id"], sl)])
            for r in ok_rooms:
                m.AddImplication(a[(e["id"], r["id"], sl)], y[(e["id"], sl)])
    room_slot = defaultdict(list)
    for (eid, rid, sl), v in a.items():
        room_slot[(rid, sl)].append(v)
    for terms in room_slot.values():
        m.AddAtMostOne(terms)
    grp_slot, grp_day = defaultdict(list), defaultdict(list)
    for e in exams:
        for sl in slots:
            for g in e["groups"]:
                grp_slot[(g, sl)].append(y[(e["id"], sl)])
                grp_day[(g, sl[0])].append(y[(e["id"], sl)])
    for terms in grp_slot.values():
        m.AddAtMostOne(terms)
    cost = []
    for terms in grp_day.values():
        if len(terms) > 1:
            ex = m.NewIntVar(0, len(terms), "")
            m.Add(ex >= sum(terms) - 1)
            cost.append(5 * 100 * ex)
    cost += list(a.values())                      # fewer rooms
    m.Minimize(sum(cost))
    if greedy_plan["status"] == "ok":             # greedy plan as the starting point
        gp = {x["exam_id"]: x for x in greedy_plan["exams"]}
        for (eid, sl), v in y.items():
            m.AddHint(v, 1 if (gp[eid]["day"], gp[eid]["session"]) == sl else 0)
        for (eid, rid, sl), v in a.items():
            m.AddHint(v, 1 if (gp[eid]["day"], gp[eid]["session"]) == sl and rid in gp[eid]["rooms"] else 0)
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = float(time_limit)
    solver.parameters.num_search_workers = max(2, min(8, os.cpu_count() or 2))
    solver.parameters.random_seed = 7
    st = solver.Solve(m)
    if st not in (cp_model.OPTIMAL, cp_model.FEASIBLE) and st != cp_model.INFEASIBLE and greedy_plan["status"] == "ok":
        greedy_plan["stats"]["fallback"] = "greedy"
        return greedy_plan
    if st not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        if accessible:
            return solve(D, time_limit, accessible=False)
        return {"status": "infeasible" if st == cp_model.INFEASIBLE else "timeout", "exams": [], "stats": {},
                "reasons": [{"constraint": "E1", "entities": [], "detail": "Exams cannot all fit into the 12 exam slots with the rooms available"}]}
    out = []
    for e in exams:
        sl = next(sl for sl in slots if solver.Value(y[(e["id"], sl)]))
        rs = sorted((rid for (eid, rid, s2), v in a.items() if eid == e["id"] and s2 == sl and solver.Value(v)),
                    key=lambda rid: -D.rooms[rid]["capacity"])
        out.append({"exam_id": e["id"], "day": sl[0], "session": sl[1], "rooms": rs})
    same_day = sum(max(0, sum(solver.Value(v) for v in t) - 1) for t in grp_day.values())
    return {"status": "ok", "exams": out,
            "stats": {"seconds": round(time.time() - t0, 2), "solver_status": solver.StatusName(st),
                      "same_day_extra": same_day, "accessible_enforced": accessible,
                      "rooms_used": sum(len(x["rooms"]) for x in out)}}


def solve_greedy(D, exams, slots, rooms, t0):
    """No OR-Tools: biggest exams first, into the slot with the fewest same-day clashes."""
    used_rooms, grp_slot, grp_day, out = {}, set(), {}, {}
    for e in sorted(exams, key=lambda e: -e["size"]):
        best = None
        for sl in slots:
            if any((g, sl) in grp_slot for g in e["groups"]):
                continue
            free = sorted((r for r in rooms if r["id"] not in used_rooms.get(sl, set())), key=lambda r: -r["capacity"])
            pick, seats = [], 0
            for r in free:
                if seats >= e["size"]:
                    break
                pick.append(r["id"])
                seats += r["capacity"] // 2
            if seats < e["size"]:
                continue
            clash = sum(grp_day.get((g, sl[0]), 0) for g in e["groups"])
            key = (clash, len(pick), slots.index(sl))
            if best is None or key < best[0]:
                best = (key, sl, pick)
        if best is None:
            return {"status": "infeasible", "exams": [], "stats": {},
                    "reasons": [{"constraint": "E1", "entities": [e["id"]], "detail": f"No free slot and rooms for {e['id']}"}]}
        _, sl, pick = best
        used_rooms.setdefault(sl, set()).update(pick)
        for g in e["groups"]:
            grp_slot.add((g, sl))
            grp_day[(g, sl[0])] = grp_day.get((g, sl[0]), 0) + 1
        out[e["id"]] = {"exam_id": e["id"], "day": sl[0], "session": sl[1], "rooms": pick}
    return {"status": "ok", "exams": [out[e["id"]] for e in exams],
            "stats": {"seconds": round(time.time() - t0, 2), "solver": "greedy"}}
