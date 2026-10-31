"""engine/bookings.py  [M2]  Spectra-week booking decisions.

Deterministic, in strict priority order: effective category (role-capped) first, earlier
submitted_at breaks ties. A request is only ever beaten by a request that outranks it, so
the validator's "priority inversion" list stays empty by construction.

For each request:
  1. cancelled            -> rejected (never approved)
  2. fits inside a window -> approved (reserved placement first, then the requested room,
                             then the smallest room that fits)
  3. institute event      -> may displace regular sessions, which are moved (session_overrides)
  4. otherwise            -> alternative_offered at the nearest free slot, or rejected with
                             the specific reason
"""
from collections import defaultdict

from core import LAST_TEACHING, MAX_PERIOD, block, fmt_slot


class Week:
    """Week-7 occupancy: the regular timetable plus overrides plus bookings decided so far."""

    def __init__(self, D, assign):
        self.D = D
        self.assign = {sid: tuple(v) for sid, v in assign.items()}
        self.overrides = {}
        self.booked = defaultdict(list)      # (room, day, p) -> [request ids]
        self.group_blocks = defaultdict(list)  # (group, day, p) -> [request ids] (mandatory groups)
        self._rebuild()

    def _rebuild(self):
        D = self.D
        self.room_sess = defaultdict(list)
        self.fac_sess = defaultdict(list)
        self.grp_sess = defaultdict(set)
        self.fac_hours = defaultdict(int)
        for sid, (d, t, r) in self.current().items():
            s = D.sess[sid]
            for p in block(t, s["duration"]):
                self.room_sess[(r, d, p)].append(sid)
                self.fac_sess[(s["faculty_id"], d, p)].append(sid)
                for g in s["groups"]:
                    self.grp_sess[(g, d, p)].add(s.get("basket_slot") or sid)
            self.fac_hours[(s["faculty_id"], d)] += s["duration"]

    def current(self):
        out = dict(self.assign)
        out.update(self.overrides)
        return out

    def span_blockers(self, req, room, d, a, b):
        """Everything stopping req from using room on d P a..b."""
        D = self.D
        out = {"unavailable": [], "sessions": set(), "bookings": set(), "groups": set()}
        for p in range(a, b + 1):
            if (d, p) in D.room_un[room]:
                out["unavailable"].append(p)
            out["sessions"].update(self.room_sess.get((room, d, p), []))
            out["bookings"].update(self.booked.get((room, d, p), []))
            for g in req["mandatory_for_groups"]:
                for key in self.grp_sess.get((g, d, p), set()):
                    out["groups"].add((g, key))
        return out

    def free(self, req, room, d, a, b):
        bl = self.span_blockers(req, room, d, a, b)
        return not (bl["unavailable"] or bl["sessions"] or bl["bookings"] or bl["groups"])

    def book(self, req, room, d, a, b):
        for p in range(a, b + 1):
            self.booked[(room, d, p)].append(req["id"])
            for g in req["mandatory_for_groups"]:
                self.group_blocks[(g, d, p)].append(req["id"])

    def can_move(self, sid, d, t, r, ignore=()):
        """Is the Week-7 move of sid to (d, t, r) valid against every hard rule?"""
        D = self.D
        s = D.sess[sid]
        if s.get("basket_slot") or s.get("fixed_slot"):
            return False
        if (d, t, r) not in set(D.candidates(s)):
            return False
        per = block(t, s["duration"])
        cur_d = self.current()[sid][0]
        for p in per:
            if [x for x in self.room_sess.get((r, d, p), []) if x != sid and x not in ignore]:
                return False
            if self.booked.get((r, d, p)):
                return False
            if [x for x in self.fac_sess.get((s["faculty_id"], d, p), []) if x != sid]:
                return False
            for g in s["groups"]:
                if [k for k in self.grp_sess.get((g, d, p), set()) if k != sid] or self.group_blocks.get((g, d, p)):
                    return False
        hours = self.fac_hours[(s["faculty_id"], d)] + (0 if cur_d == d else s["duration"])
        return hours <= D.fac[s["faculty_id"]].get("max_hours_per_day", 4)

    def move(self, sid, d, t, r, req_id):
        self.overrides[sid] = (d, t, r)
        self.override_by = getattr(self, "override_by", {})
        self.override_by[sid] = req_id
        self._rebuild()


def ordered_requests(D, extra_requests=()):
    order = D.raw["priority_order"]
    reqs = list(D.requests.values()) + list(extra_requests)
    return sorted(reqs, key=lambda r: (order.index(D.effective_category(r)), r["submitted_at"], r["id"]))


def room_order(D, req, rooms, reserved=None, d=None, a=None):
    """Reserved room first, then the requested room, then the tightest fit."""
    def key(r):
        return (0 if reserved and reserved[0] == r and reserved[1] == d and reserved[2] == a else 1,
                0 if r == req.get("requested_room") else 1,
                D.rooms[r]["capacity"], r)
    return sorted(rooms, key=key)


def decide(D, assign, reserved=None, extra_requests=(), allow_displacement=True, excluded=None):
    """Return (decisions, overrides, details). decisions follow the export format exactly."""
    reserved = reserved or {}
    W = Week(D, assign)
    decisions, details = {}, {}
    rank = {c: i for i, c in enumerate(D.raw["priority_order"])}
    institute_rank = rank["institute_event"]
    all_ids = [r["id"] for r in list(D.requests.values()) + list(extra_requests)]

    for req in ordered_requests(D, extra_requests):
        rid = req["id"]
        cat = D.effective_category(req)
        info = {"effective_category": cat, "capped": cat != req["requested_category"], "notes": []}
        if info["capped"]:
            info["notes"].append(f"{req['role'].replace('_', ' ')} requests are capped at {cat.replace('_', ' ')} "
                                 f"(asked for {req['requested_category'].replace('_', ' ')})")
        if excluded and rid in excluded:
            decisions[rid] = {"request_id": rid, "status": "rejected", "reason": excluded[rid] or "Rejected by an Admin"}
            details[rid] = info
            continue
        if rid in D.cancelled:
            c = next(c for c in D.raw["cancellations"] if c["request_id"] == rid)
            decisions[rid] = {"request_id": rid, "status": "rejected",
                              "reason": f"Cancelled by {c.get('by', 'the requester')} on {c['cancelled_at'][:10]}"
                                        + (f": {c['note']}" if c.get("note") else "")}
            details[rid] = info
            continue

        rooms = [r for r in D.rooms if D.room_ok_for_request(req, r)]
        wish = req.get("requested_room")
        if wish:
            probs = D.request_room_problems(req, wish)
            if probs:
                info["notes"].append(f"Requested {wish} not used: {'; '.join(probs)}")
        if not rooms:
            decisions[rid] = {"request_id": rid, "status": "rejected", "reason": no_room_reason(D, req)}
            details[rid] = info
            continue

        spans = D.in_window_placements(req)
        choice = None
        res = reserved.get(rid)
        if res and res[0] in rooms and tuple(res[1:]) in spans and W.free(req, *res):
            choice = tuple(res)          # the space the timetable solver kept free for it
        for d, a, b in spans if choice is None else []:
            r = next((r for r in room_order(D, req, rooms) if W.free(req, r, d, a, b)), None)
            if r:
                choice = (r, d, a, b)
                break

        moved = []
        if choice is None and allow_displacement and rank[cat] <= institute_rank:
            choice, moved = try_displace(D, W, req, spans, rooms)

        if choice:
            r, d, a, b = choice
            W.book(req, r, d, a, b)
            note = ""
            if wish and wish != r and not D.request_room_problems(req, wish):
                taker = W.span_blockers(req, wish, d, a, b)
                who = sorted(taker["bookings"]) or sorted(taker["sessions"])
                note = f"; {wish} was taken" + (f" by {', '.join(who)}" if who else "")
            elif wish and wish != r:
                note = f"; requested {wish} not used ({'; '.join(D.request_room_problems(req, wish))})"
            decisions[rid] = {"request_id": rid, "status": "approved", "day": d, "start_period": a, "end_period": b,
                              "room_id": r, "reason": f"Approved in {r}, {fmt_slot(d, a, b)}{note}"}
            if moved:
                decisions[rid]["reason"] += f"; moved {', '.join(moved)} for this institute event"
            details[rid] = info
            continue

        why = window_failure(D, W, req, spans, rooms)
        alt = find_alternative(D, W, req, rooms)
        if alt:
            r, d, a, b = alt
            W.book(req, r, d, a, b)
            decisions[rid] = {"request_id": rid, "status": "alternative_offered", "day": d, "start_period": a,
                              "end_period": b, "room_id": r, "reason": f"{why}. Offered {r}, {fmt_slot(d, a, b)} instead"}
        else:
            decisions[rid] = {"request_id": rid, "status": "rejected", "reason": f"{why}, and no other slot that week fits"}
        details[rid] = info

    overrides = [{"session_id": sid, "day": v[0], "start_period": v[1], "room_id": v[2],
                  "displaced_by": W.override_by[sid]} for sid, v in sorted(W.overrides.items())]
    ordered = [decisions[i] for i in all_ids if i in decisions]
    return ordered, overrides, details


def no_room_reason(D, req):
    ok_type = [r for r in D.rooms.values() if r["type"] in req["room_types"] and "event" in r["allowed_uses"]]
    if not ok_type:
        return f"No bookable {'/'.join(t.replace('_', ' ') for t in req['room_types'])} exists"
    biggest = max(ok_type, key=lambda r: r["capacity"])
    if biggest["capacity"] < req["attendance"]:
        return f"No venue seats {req['attendance']:,}; the largest {biggest['type'].replace('_', ' ')} ({biggest['id']}) seats {biggest['capacity']}"
    needs = set(req["needs"])
    return (f"No {'/'.join(t.replace('_', ' ') for t in req['room_types'])} seating {req['attendance']} has "
            f"{', '.join(sorted(n.replace('_', ' ') for n in needs))}")


def window_failure(D, W, req, spans, rooms):
    if not spans:
        ws = ", ".join(fmt_slot(w["day"], w["from_period"], w["to_period"]) for w in req["windows"])
        return f"A {req['duration_periods']}-period event does not fit in the requested window ({ws}; the day ends at P{MAX_PERIOD})"
    blockers = defaultdict(set)
    for d, a, b in spans:
        for r in rooms:
            bl = W.span_blockers(req, r, d, a, b)
            for k in ("sessions", "bookings"):
                blockers[k].update(bl[k])
            blockers["groups"].update(g for g, _ in bl["groups"])
            if bl["unavailable"]:
                blockers["unavailable"].add(r)
    parts = []
    if blockers["bookings"]:
        parts.append(f"suitable rooms are taken by higher-priority requests ({', '.join(sorted(blockers['bookings']))})")
    if blockers["groups"]:
        parts.append(f"{', '.join(sorted(blockers['groups']))} have class then and attendance is mandatory")
    if blockers["sessions"] and not parts:
        parts.append("suitable rooms have regular classes then")
    if blockers["unavailable"]:
        parts.append(f"{', '.join(sorted(blockers['unavailable']))} closed")
    return ("No suitable room is free in the requested window: " + "; ".join(parts)) if parts else "No suitable room is free in the requested window"


def find_alternative(D, W, req, rooms):
    """Nearest free span outside the window: same days first, evenings preferred, then other days."""
    dur = req["duration_periods"]
    win_days = [w["day"] for w in req["windows"] if w["day"] in D.days]
    centre = {w["day"]: (w["from_period"] + min(w["to_period"], MAX_PERIOD)) / 2 for w in req["windows"]}
    best = None
    for d in D.days:
        for a in range(1, MAX_PERIOD - dur + 2):
            b = a + dur - 1
            day_cost = 0 if d in win_days else 10 + abs(D.days.index(d) - min((D.days.index(x) for x in win_days), default=0))
            time_cost = abs((a + b) / 2 - centre.get(d, centre[win_days[0]] if win_days else 11))
            cost = day_cost * 100 + time_cost * 2 + (0 if a > LAST_TEACHING else 5)
            if best and cost >= best[0]:
                continue
            for r in room_order(D, req, rooms):
                if W.free(req, r, d, a, b):
                    best = (cost, (r, d, a, b))
                    break
    return best[1] if best else None


def try_displace(D, W, req, spans, rooms):
    """Institute events only: move the regular sessions in the way to another valid Week-7 slot."""
    for d, a, b in spans:
        for r in room_order(D, req, rooms):
            bl = W.span_blockers(req, r, d, a, b)
            if bl["unavailable"] or bl["bookings"]:
                continue
            victims = set(bl["sessions"]) | {k for _, k in bl["groups"]}
            if any(v not in D.sess for v in victims):
                continue   # a basket slot blocks a mandatory group; baskets are never split
            plan = []
            for sid in sorted(victims):
                spot = None
                for (d2, t2, r2) in D.candidates(D.sess[sid]):
                    per = block(t2, D.sess[sid]["duration"])
                    if d2 == d and r2 == r and set(per) & set(range(a, b + 1)):
                        continue
                    if any(d2 == d and p in range(a, b + 1) and g in req["mandatory_for_groups"]
                           for p in per for g in D.sess[sid]["groups"]):
                        continue
                    if W.can_move(sid, d2, t2, r2):
                        spot = (d2, t2, r2)
                        break
                if spot is None:
                    break
                plan.append((sid, spot))
                W.move(sid, *spot, req["id"])
            if len(plan) == len(victims) and W.free(req, r, d, a, b):
                return (r, d, a, b), [sid for sid, _ in plan]
            for sid, _ in plan:      # undo a partial plan
                W.overrides.pop(sid, None)
                W.override_by.pop(sid, None)
            W._rebuild()
    return None, []
