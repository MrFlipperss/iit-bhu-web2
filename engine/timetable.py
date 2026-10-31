"""engine/timetable.py  [M1]  CP-SAT timetable model (also used by repair.py).

Hard rules (all of H1-H12, H14): one placement per session; no room, faculty or group
double-booking (combined groups count for every group; one basket slot counts once);
basket sessions share a time; room type, capacity and accessibility; faculty and room
unavailability; P1-P9 only, no session across lunch, labs start at P2/P6; faculty daily
limit; fixed slots.

Objective (lower is better), in this order of weight:
  1. repair mode: number of sessions whose (day, start, room) differs from the baseline
  2. Spectra reservations: keep rooms / mandatory groups free for daytime booking requests
  3. the validator's soft penalties S1-S6, with the validator's own weights
"""
import os
import time
from collections import defaultdict

try:
    from ortools.sat.python import cp_model
except ImportError:          # deployment without OR-Tools: best_timetable() uses greedy only
    cp_model = None

from core import CAT_WEIGHT, LAST_TEACHING, MAX_PERIOD, block, official

MOVE_WEIGHT = 1000        # one moved session costs more than any soft improvement
TIME_WEIGHT = 60          # among equal move counts, prefer a new room over a new time (less disruption for people)
BOOKING_WEIGHT = 40       # per booking-score point (club 2 -> 80, institute 5 -> 200)


def reservable_requests(D):
    """Requests whose every in-window placement touches teaching periods (they compete with classes)."""
    out = []
    for rid, req in D.requests.items():
        if rid in D.cancelled:
            continue
        spans = D.in_window_placements(req)
        rooms = [r for r in D.rooms if D.room_ok_for_request(req, r)]
        if spans and rooms and all(a <= LAST_TEACHING for _, a, _b in spans):
            out.append((req, spans, rooms))
    return out


def solve(D, time_limit=30.0, baseline=None, reserve=True, workers=None, seed=7, log=False, soft=True, hint=None, hint_reserved=None):
    t0 = time.time()
    if cp_model is None:
        import greedy
        return greedy.solve(D, time_limit=time_limit, baseline=baseline, reserve=reserve)
    m = cp_model.CpModel()
    x, cand, empty = {}, {}, []
    for s in D.sessions:
        c = D.candidates(s)
        cand[s["id"]] = c
        if not c:
            empty.append(s["id"])
    if empty:
        return {"status": "infeasible", "assign": {}, "reserved": {},
                "reasons": diagnose(D, empty), "stats": {"seconds": round(time.time() - t0, 2)}}

    # basket slots: all sessions of a basket at the same (day, start)
    basket_of = {sid: s["basket_slot"] for s in D.sessions for sid in [s["id"]] if s.get("basket_slot")}
    basket_starts = {}
    for k, sids in D.baskets.items():
        common = set.intersection(*[{(d, t) for d, t, _ in cand[sid]} for sid in sids])
        basket_starts[k] = sorted(common)
    for s in D.sessions:
        k = basket_of.get(s["id"])
        for d, t, r in cand[s["id"]]:
            if k and (d, t) not in basket_starts[k]:
                continue
            x[(s["id"], d, t, r)] = m.NewBoolVar(f"x_{s['id']}_{d}{t}_{r}")

    by_sid = defaultdict(list)
    for key, v in x.items():
        by_sid[key[0]].append((key, v))
    for s in D.sessions:
        if not by_sid[s["id"]]:
            return {"status": "infeasible", "assign": {}, "reserved": {},
                    "reasons": diagnose(D, [s["id"]], basket=True), "stats": {}}
        m.AddExactlyOne(v for _, v in by_sid[s["id"]])

    bvar = {}
    for k, starts in basket_starts.items():
        for d, t in starts:
            b = bvar[(k, d, t)] = m.NewBoolVar(f"b_{k}_{d}{t}")
            for sid in D.baskets[k]:
                m.Add(sum(v for (key, v) in by_sid[sid] if key[1] == d and key[2] == t) == b)

    room_cov, fac_cov, grp_cov = defaultdict(list), defaultdict(list), defaultdict(list)
    fac_day = defaultdict(list)
    for (sid, d, t, r), v in x.items():
        s = D.sess[sid]
        for p in block(t, s["duration"]):
            room_cov[(r, d, p)].append(v)
            fac_cov[(s["faculty_id"], d, p)].append(v)
            if sid not in basket_of:
                for g in s["groups"]:
                    grp_cov[(g, d, p)].append(v)
        fac_day[(s["faculty_id"], d)].append(s["duration"] * v)
    basket_groups = {k: sorted({g for sid in sids for g in D.sess[sid]["groups"]}) for k, sids in D.baskets.items()}
    basket_dur = {k: D.sess[sids[0]]["duration"] for k, sids in D.baskets.items()}
    for (k, d, t), b in bvar.items():
        for p in block(t, basket_dur[k]):
            for g in basket_groups[k]:
                grp_cov[(g, d, p)].append(b)

    # Spectra reservations (soft): placements z that need rooms / groups during teaching time
    z, z_room, z_grp = {}, defaultdict(list), defaultdict(list)
    placed = []
    if reserve:
        for req, spans, rooms in reservable_requests(D):
            opts = []
            for d, a, bb in spans:
                for r in rooms:
                    if any((d, p) in D.room_un[r] for p in range(a, bb + 1)):
                        continue
                    v = m.NewBoolVar(f"z_{req['id']}_{d}{a}_{r}")
                    z[(req["id"], d, a, bb, r)] = v
                    opts.append(v)
                    for p in range(a, bb + 1):
                        z_room[(r, d, p)].append(v)
                        for g in req["mandatory_for_groups"]:
                            z_grp[(g, d, p)].append(v)
            if opts:
                pl = m.NewBoolVar(f"placed_{req['id']}")
                m.Add(sum(opts) == pl)
                placed.append((CAT_WEIGHT.get(D.effective_category(req), 1), pl))

    for key in set(room_cov) | set(z_room):
        terms = room_cov.get(key, []) + z_room.get(key, [])
        if len(terms) > 1:
            m.AddAtMostOne(terms)
    for terms in fac_cov.values():
        if len(terms) > 1:
            m.AddAtMostOne(terms)
    for key in set(grp_cov) | set(z_grp):
        terms = grp_cov.get(key, []) + z_grp.get(key, [])
        if len(terms) > 1:
            m.AddAtMostOne(terms)
    for (f, d), terms in fac_day.items():
        m.Add(sum(terms) <= D.fac[f].get("max_hours_per_day", 4))

    # ----------------------------------------------------------- soft penalties
    cost = []
    for (sid, d, t, r), v in x.items() if soft else []:
        s = D.sess[sid]
        per = block(t, s["duration"])
        c = 0
        for pref in D.fac[s["faculty_id"]].get("preferences", []):
            if pref.get("type") == "avoid" and d in pref.get("days", D.days):
                c += 2 * sum(1 for p in per if p in pref["periods"])           # S1
        if D.rooms[r]["capacity"] and s["size"] / D.rooms[r]["capacity"] < 0.4:
            c += 1                                                              # S5
        if LAST_TEACHING in per:
            c += 1                                                              # S6
        if c:
            cost.append(c * v)

    groups = list(D.groups) if soft else []
    for g in groups:
        for d in D.days:
            # S2 gaps: occupied flags, then "something before and after but free now"
            occ = []
            for p in range(1, LAST_TEACHING + 1):
                terms = grp_cov.get((g, d, p), [])
                o = m.NewBoolVar(f"o_{g}_{d}{p}")
                m.Add(sum(terms) == o) if terms else m.Add(o == 0)
                occ.append(o)
            for i in range(1, LAST_TEACHING - 1):
                before, after = m.NewBoolVar(""), m.NewBoolVar("")
                m.AddMaxEquality(before, occ[:i])
                m.AddMaxEquality(after, occ[i + 1:])
                gap = m.NewBoolVar(f"gap_{g}_{d}{i + 1}")
                m.Add(gap >= before + after - 1 - occ[i])
                cost.append(gap)
            # S3: more than 6 hours in a day (basket counted once)
            hours = [D.sess[sid]["duration"] * v for (sid, dd, t, r), v in x.items()
                     if dd == d and sid not in basket_of and g in D.sess[sid]["groups"]]
            hours += [basket_dur[k] * b for (k, dd, t), b in bvar.items() if dd == d and g in basket_groups[k]]
            if hours:
                ex = m.NewIntVar(0, 9, f"s3_{g}_{d}")
                m.Add(ex >= sum(hours) - 6)
                cost.append(3 * ex)
    # S4: the same course lectured twice in a day to a group
    lec = defaultdict(list)
    for (sid, d, t, r), v in x.items():
        s = D.sess[sid]
        if s["kind"] == "lecture" and sid not in basket_of:
            for g in s["groups"]:
                lec[(g, d, s["course_id"])].append(v)
        elif sid in basket_of:
            lec[("basket", d, s["course_id"])].append(v)
    for key, terms in lec.items() if soft else []:
        if len(terms) > 1:
            ex = m.NewIntVar(0, len(terms), "")
            m.Add(ex >= sum(terms) - 1)
            cost.append(3 * ex)

    # ----------------------------------------------------------- repair: minimum movement
    forced_moves = []
    if baseline:
        for sid, v_list in by_sid.items():
            base = baseline.get(sid)
            if base is None:
                continue
            key = (sid, base[0], base[1], base[2])
            if key in x:
                cost.append(MOVE_WEIGHT * (1 - x[key]))
            else:
                forced_moves.append(sid)
            same_time = [v for (k2, v) in v_list if k2[1] == base[0] and k2[2] == base[1]]
            cost.append(TIME_WEIGHT * (1 - sum(same_time)))
            if hint is None:
                for k2, v in v_list:
                    m.AddHint(v, 1 if k2 == key else 0)

    if hint:   # a valid timetable from greedy.py: CP-SAT starts from it and only improves
        for (sid, d, t, r), v in x.items():
            m.AddHint(v, 1 if list(hint.get(sid, ())) == [d, t, r] else 0)
        for (k, d, t), b in bvar.items():
            first = D.baskets[k][0]
            m.AddHint(b, 1 if list(hint.get(first, ()))[:2] == [d, t] else 0)
        for (rid, d, a, bb, r), v in z.items():
            m.AddHint(v, 1 if (hint_reserved or {}).get(rid) == [r, d, a, bb] else 0)

    m.Minimize(sum(cost) - sum(BOOKING_WEIGHT * w * pl for w, pl in placed))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = float(time_limit)
    solver.parameters.num_search_workers = workers or max(2, min(8, os.cpu_count() or 2))
    solver.parameters.random_seed = seed
    solver.parameters.log_search_progress = log
    st = solver.Solve(m)
    stats = {"seconds": round(time.time() - t0, 2), "variables": len(x) + len(z) + len(bvar),
             "solver_status": solver.StatusName(st), "objective": solver.ObjectiveValue() if st in (cp_model.OPTIMAL, cp_model.FEASIBLE) else None,
             "forced_moves": forced_moves}
    if st not in (cp_model.OPTIMAL, cp_model.FEASIBLE) and hint and st != cp_model.INFEASIBLE:
        stats["fallback"] = "greedy"     # CP-SAT found nothing better in time: keep the valid start
        return {"status": "ok", "assign": {k: list(v) for k, v in hint.items()}, "reserved": hint_reserved or {}, "stats": stats}
    if st not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        status = "infeasible" if st == cp_model.INFEASIBLE else "timeout"
        return {"status": status, "assign": {}, "reserved": {}, "stats": stats,
                "reasons": diagnose(D, []) if status == "infeasible" else
                [{"constraint": "time_limit", "entities": [], "detail": f"No timetable found within {time_limit:.0f}s; try again with a longer limit."}]}
    assign = {sid: [d, t, r] for (sid, d, t, r), v in x.items() if solver.Value(v)}
    reserved = {}
    for (rid, d, a, bb, r), v in z.items():
        if solver.Value(v):
            reserved[rid] = [r, d, a, bb]
    return {"status": "ok", "assign": assign, "reserved": reserved, "stats": stats}


def diagnose(D, empty_sids, basket=False):
    """Explain why no timetable exists: name the constraint and the entities involved."""
    out = []
    for sid in empty_sids:
        s = D.sess[sid]
        rooms = D.valid_rooms(s)
        starts = D.valid_starts(s)
        if basket:
            out.append({"constraint": "H11", "entities": [sid, s.get("basket_slot")],
                        "detail": f"{sid}: no time is open for every session in basket {s.get('basket_slot')}"})
        elif not rooms:
            out.append({"constraint": "H5/H6/H10", "entities": [sid],
                        "detail": f"{sid}: no room is a {'/'.join(s['room_types'])} seating {s['size']}"
                                  + (" with step-free access" if s.get("needs_accessible") else "")})
        elif not starts:
            out.append({"constraint": "H7/H9/H14", "entities": [sid, s["faculty_id"]],
                        "detail": f"{sid}: {s['faculty_id']} is unavailable at every allowed start time"
                                  + (f" (fixed at {s['fixed_slot']['day']} P{s['fixed_slot']['start_period']})" if s.get("fixed_slot") else "")})
        else:
            out.append({"constraint": "H8", "entities": [sid] + rooms,
                        "detail": f"{sid}: every suitable room ({', '.join(rooms)}) is closed whenever {s['faculty_id']} can teach"})
    # load checks: more hours than free periods
    load = defaultdict(int)
    for s in D.sessions:
        load[s["faculty_id"]] += s["duration"]
    for f, h in load.items():
        mx = D.fac[f].get("max_hours_per_day", 4)
        cap = sum(min(mx, sum(1 for p in D.teaching_periods if (d, p) not in D.fac_un[f])) for d in D.days)
        if h > cap:
            out.append({"constraint": "H7/H12", "entities": [f],
                        "detail": f"{f} has {h} teaching hours but only {cap} usable hours a week (daily limit {mx})"})
    for g in D.groups:
        h = sum(s["duration"] for s in D.sessions if g in s["groups"] and not s.get("basket_slot"))
        h += sum(D.sess[sids[0]]["duration"] for k, sids in D.baskets.items() if g in D.sess[sids[0]]["groups"])
        if h > len(D.days) * len(D.teaching_periods):
            out.append({"constraint": "H3", "entities": [g], "detail": f"Group {g} needs {h} hours, more than the week holds"})
    if not out:
        out.append({"constraint": "combined", "entities": [],
                    "detail": "Each session can be placed on its own, but not all together: the remaining rooms, faculty and groups overlap too much. Try relaxing the newest change."})
    return out


def plan_score(D, res):
    """Lower is better: the validator's own soft score, minus the booking weight of kept-free requests."""
    if res.get("status") != "ok":
        return float("inf")
    assign = {sid: tuple(v) for sid, v in res["assign"].items()}
    _, soft = official.soft_score(official.Ctx(D.raw), assign)
    reserved = sum(CAT_WEIGHT.get(D.effective_category(D.requests[r]), 1) for r in res.get("reserved", {}) if r in D.requests)
    return soft - BOOKING_WEIGHT * reserved


def best_timetable(D, time_limit=24.0, baseline=None, reserve=True, seed=7):
    """Several fast greedy starts (about 1 s each), then CP-SAT tries to improve the best one.
    Returns whichever plan scores better on the validator's soft score (with reservations).
    In repair mode the CP-SAT result is kept when it moves fewer sessions. Works without OR-Tools."""
    import greedy
    t0 = time.time()
    starts = 1 if baseline else 4
    best = None
    for k in range(starts):
        g = greedy.solve(D, time_limit=min(10.0, time_limit / 3), seed=seed + k, baseline=baseline, reserve=reserve)
        if g["status"] == "infeasible":
            return g
        if g["status"] == "ok" and (best is None or plan_score(D, g) < plan_score(D, best)):
            best = g
        if time.time() - t0 > time_limit / 3:
            break
    if cp_model is None:
        return best or g
    remaining = max(3.0, time_limit - (time.time() - t0))
    res = solve(D, time_limit=remaining, baseline=baseline, reserve=reserve, seed=seed,
                hint=best["assign"] if best else None, hint_reserved=best.get("reserved") if best else None)
    if best:
        res.setdefault("stats", {})["greedy"] = best["stats"]
        if baseline:
            moved = lambda r: sum(1 for sid, v in r["assign"].items() if sid in baseline and list(baseline[sid]) != list(v))
            if res["status"] != "ok" or moved(best) < moved(res):
                best["stats"]["chosen"] = "greedy"
                return best
        elif plan_score(D, best) < plan_score(D, res):
            best["stats"]["chosen"] = "greedy"
            best["stats"]["cpsat_status"] = res.get("stats", {}).get("solver_status")
            return best
    res["stats"]["chosen"] = "cpsat"
    return res
