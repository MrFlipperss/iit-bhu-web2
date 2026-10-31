"""engine/greedy.py  [M1]  Fast feasible timetable without OR-Tools.

Constructive placement (most constrained first) followed by a min-conflicts local search
until no hard rule is broken. Used in three ways:
  * to give CP-SAT a valid starting point (so the first solution is found in seconds)
  * as the whole solver when OR-Tools is not installed
  * for repair: start from the baseline and move only what conflicts

Hard rules modelled exactly as validator.py counts them: room/faculty/group double booking
(basket slot counted once per group), faculty daily limit, plus everything filtered out by
core.Data.candidates (time model, room fit, unavailability, fixed slots).
"""
import random
import time
from collections import Counter, defaultdict

from core import CAT_WEIGHT, LAST_TEACHING, block

HARD = 1000


class State:
    def __init__(self, D, blocks_room=None, blocks_group=None):
        self.D = D
        self.room = Counter()
        self.fac = Counter()
        self.grp = defaultdict(Counter)       # (g, d, p) -> Counter(key)
        self.hours = Counter()                # (f, d)
        self.assign = {}
        self.lect = Counter()                 # (g, d, course) -> lectures that day
        self.blocks_room = blocks_room or set()   # (r, d, p) kept free for bookings
        self.blocks_group = blocks_group or set() # (g, d, p) kept free for mandatory bookings

    def key(self, s):
        return s.get("basket_slot") or s["id"]

    def add(self, sid, d, t, r, sign=1):
        s = self.D.sess[sid]
        k = self.key(s)
        for p in block(t, s["duration"]):
            self.room[(r, d, p)] += sign
            self.fac[(s["faculty_id"], d, p)] += sign
            for g in s["groups"]:
                c = self.grp[(g, d, p)]
                c[k] += sign
                if c[k] <= 0:
                    del c[k]
        self.hours[(s["faculty_id"], d)] += sign * s["duration"]
        if s["kind"] == "lecture" and not s.get("basket_slot"):
            for g in s["groups"]:
                self.lect[(g, d, s["course_id"])] += sign
        if sign > 0:
            self.assign[sid] = (d, t, r)
        else:
            self.assign.pop(sid, None)

    def place_cost(self, sid, d, t, r, ignore_rooms=()):
        """Hard conflicts this session would add at (d, t, r), given everything else placed."""
        s = self.D.sess[sid]
        k = self.key(s)
        c = 0
        for p in block(t, s["duration"]):
            c += self.room[(r, d, p)] + ((r, d, p) in self.blocks_room)
            if (r, d, p) in ignore_rooms:
                c += 1
            c += self.fac[(s["faculty_id"], d, p)]
            for g in s["groups"]:
                cnt = self.grp[(g, d, p)]
                c += sum(1 for kk in cnt if kk != k) + ((g, d, p) in self.blocks_group)
        mx = self.D.fac[s["faculty_id"]].get("max_hours_per_day", 4)
        c += max(0, self.hours[(s["faculty_id"], d)] + s["duration"] - mx)
        return c

    def total_conflicts(self):
        D = self.D
        c = sum(v - 1 for v in self.room.values() if v > 1) + sum(v - 1 for v in self.fac.values() if v > 1)
        c += sum(max(0, len(v) - 1) for v in self.grp.values())
        c += sum(max(0, h - D.fac[f].get("max_hours_per_day", 4)) for (f, d), h in self.hours.items())
        c += sum(1 for (r, d, p) in self.blocks_room if self.room[(r, d, p)])
        c += sum(1 for (g, d, p) in self.blocks_group if self.grp[(g, d, p)])
        return c


def soft_local(D, s, d, t, r):
    per = block(t, s["duration"])
    c = 0
    for pref in D.fac[s["faculty_id"]].get("preferences", []):
        if pref.get("type") == "avoid" and d in pref.get("days", D.days):
            c += 2 * sum(1 for p in per if p in pref["periods"])
    if LAST_TEACHING in per:
        c += 1
    if D.rooms[r]["capacity"] and s["size"] / D.rooms[r]["capacity"] < 0.4:
        c += 1
    return c


class Unit:
    """A single session, or a whole basket (its sessions share day/start, distinct rooms)."""

    def __init__(self, D, sids):
        self.sids = sids
        cands = [D.candidates(D.sess[x]) for x in sids]
        times = set.intersection(*[{(d, t) for d, t, _ in c} for c in cands])
        self.rooms = [{(d, t): [r for d2, t2, r in c if (d2, t2) == (d, t)] for d, t in times} for c in cands]
        self.times = sorted(times)
        self.size = sum(len(c) for c in cands)


def soft_context(D, S, s, d, t):
    """Soft cost that depends on what is already placed: gaps (S2), long days (S3), repeated lectures (S4)."""
    per = block(t, s["duration"])
    c = 0
    for g in s["groups"]:
        occ = {p for p in range(1, LAST_TEACHING + 1) if S.grp.get((g, d, p))}
        if occ:
            before = (max(occ) - min(occ) + 1) - len(occ)
            new = occ | set(per)
            c += ((max(new) - min(new) + 1) - len(new)) - before          # S2: added gap periods
            c += 3 * max(0, len(new) - 6) - 3 * max(0, len(occ) - 6)       # S3
        if s["kind"] == "lecture":
            key = (g, d, s["course_id"])
            c += 3 * S.lect.get(key, 0)                                     # S4
    return c


def unit_options(D, S, u, rng, limit=None):
    """Yield (cost, placement) where placement = [(sid, d, t, r), ...]."""
    times = u.times if limit is None or len(u.times) <= limit else rng.sample(u.times, limit)
    for d, t in times:
        plan, cost, used = [], 0, set()
        sctx = soft_context(D, S, D.sess[u.sids[0]], d, t)
        for i, sid in enumerate(u.sids):
            best = None
            for r in u.rooms[i][(d, t)]:
                if r in used:
                    continue
                c = S.place_cost(sid, d, t, r) * HARD + soft_local(D, D.sess[sid], d, t, r)
                if i == 0 or not D.sess[sid].get("basket_slot"):
                    c += sctx
                if best is None or c < best[0]:
                    best = (c, r)
            if best is None:
                plan = None
                break
            used.add(best[1])
            plan.append((sid, d, t, best[1]))
            cost += best[0]
        if plan:
            yield cost, plan


def build_units(D):
    units, seen = [], set()
    for s in D.sessions:
        if s["id"] in seen:
            continue
        sids = D.baskets[s["basket_slot"]] if s.get("basket_slot") else [s["id"]]
        seen.update(sids)
        units.append(Unit(D, sids))
    return units


def reserve_blocks(D):
    """Pick a placement for each daytime booking request (priority order) on an empty week."""
    from timetable import reservable_requests
    order = D.raw["priority_order"]
    reqs = sorted(reservable_requests(D), key=lambda x: (order.index(D.effective_category(x[0])), x[0]["submitted_at"]))
    taken, rblocks, gblocks, chosen = set(), set(), set(), {}
    for req, spans, rooms in reqs:
        for d, a, b in spans:
            pick = None
            for r in sorted(rooms, key=lambda r: (r != req.get("requested_room"), D.rooms[r]["capacity"])):
                if not any((r, d, p) in taken or (d, p) in D.room_un[r] for p in range(a, b + 1)):
                    pick = r
                    break
            if pick:
                chosen[req["id"]] = [pick, d, a, b]
                for p in range(a, b + 1):
                    taken.add((pick, d, p))
                    if p <= LAST_TEACHING:
                        rblocks.add((pick, d, p))
                        for g in req["mandatory_for_groups"]:
                            gblocks.add((g, d, p))
                break
    return chosen, rblocks, gblocks


def solve(D, time_limit=8.0, seed=7, baseline=None, reserve=True):
    """Return {"status", "assign", "reserved", "stats"}; status "ok" means 0 hard conflicts."""
    t0 = time.time()
    rng = random.Random(seed)
    units = build_units(D)
    empty = [u.sids for u in units if not u.times]
    if empty:
        from timetable import diagnose
        return {"status": "infeasible", "assign": {}, "reserved": {},
                "reasons": diagnose(D, [s for g in empty for s in g]), "stats": {}}
    reserved, rb, gb = reserve_blocks(D) if reserve and not baseline else ({}, set(), set())
    S = State(D, rb, gb)
    unit_of = {sid: u for u in units for sid in u.sids}

    def place(u, plan):
        for sid, d, t, r in plan:
            S.add(sid, d, t, r)

    def remove(u):
        for sid in u.sids:
            if sid in S.assign:
                S.add(sid, *S.assign[sid], sign=-1)

    pending = []
    if baseline:
        for u in units:
            plan = [(sid, *baseline[sid]) for sid in u.sids if sid in baseline]
            ok = len(plan) == len(u.sids) and all(
                (plan[i][1], plan[i][2]) in u.rooms[i] and plan[i][3] in u.rooms[i][(plan[i][1], plan[i][2])]
                for i in range(len(plan))) and len({(p[1], p[2]) for p in plan}) == 1
            if ok:
                place(u, plan)
            else:
                pending.append(u)
    else:
        pending = sorted(units, key=lambda u: (u.size, -sum(D.sess[s]["duration"] for s in u.sids)))
    for u in pending:
        best = min(unit_options(D, S, u, rng), key=lambda x: x[0])
        place(u, best[1])

    moved_from_base = {u.sids[0] for u in pending} if baseline else set()
    conflicts = S.total_conflicts()
    it, tabu = 0, {}
    while conflicts > 0 and time.time() - t0 < time_limit:
        it += 1
        bad = [sid for sid, (d, t, r) in S.assign.items()
               if S.place_cost(sid, d, t, r) - _self_overlap(S, sid) > 0 or S.hours[(D.sess[sid]["faculty_id"], d)] > D.fac[D.sess[sid]["faculty_id"]].get("max_hours_per_day", 4)]
        if not bad:
            break
        u = unit_of[rng.choice(bad)]
        old = [(sid, *S.assign[sid]) for sid in u.sids]
        remove(u)
        opts = []
        for c, plan in unit_options(D, S, u, rng, limit=60):
            sig = (u.sids[0], plan[0][1], plan[0][2])
            if tabu.get(sig, 0) > it:
                c += HARD // 2
            if baseline and u.sids[0] not in moved_from_base and plan != old:
                c += 3                      # repair: prefer leaving untouched sessions alone
            opts.append((c + rng.random(), plan))
        _, plan = min(opts, key=lambda x: x[0])
        place(u, plan)
        tabu[(u.sids[0], old[0][1], old[0][2])] = it + 10
        if baseline and plan != old:
            moved_from_base.add(u.sids[0])
        conflicts = S.total_conflicts()

    status = "ok" if conflicts == 0 else "timeout"
    return {"status": status, "assign": {sid: list(v) for sid, v in S.assign.items()}, "reserved": reserved,
            "stats": {"seconds": round(time.time() - t0, 2), "iterations": it, "conflicts": conflicts, "solver": "greedy"},
            "reasons": [] if status == "ok" else [{"constraint": "search", "entities": [],
                                                   "detail": f"{conflicts} clashes left after {time_limit:.0f}s of search"}]}


def _self_overlap(S, sid):
    """place_cost counts the session against itself while it is placed; subtract that."""
    D = S.D
    s = D.sess[sid]
    d, t, r = S.assign[sid]
    mx = D.fac[s["faculty_id"]].get("max_hours_per_day", 4)
    n = 0
    for p in block(t, s["duration"]):
        n += 2          # its own room and faculty counts
    over_with = max(0, S.hours[(s["faculty_id"], d)] + s["duration"] - mx)
    over_without = max(0, S.hours[(s["faculty_id"], d)] - mx)
    return n + (over_with - over_without)
