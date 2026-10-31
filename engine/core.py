"""engine/core.py  [M1]  Shared dataset model for every engine module.

Loads a dataset dict (cit_public.json format), applies the starter-pack time model and
exposes the candidate placements of each session. Hard checks are NOT re-implemented
here: final validation always goes through the untouched official validator.py, which is
imported from ../validator as a library.
"""
import os
import sys
from collections import defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "validator"))
import validator as official  # noqa: E402  (the official file, never modified)

LAST_TEACHING = official.LAST_TEACHING      # 9
MAX_PERIOD = official.MAX_PERIOD            # 13
CAT_WEIGHT = official.CAT_WEIGHT            # booking score per category


def block(start, dur):
    return list(range(start, start + dur))


class Data:
    """Indexed view of one dataset version."""

    def __init__(self, data):
        self.raw = data
        cal = data["calendar"]
        self.days = list(cal["days"])
        self.lunch = cal["lunch"]["after_period"]
        self.lab_starts = set(cal["lab_start_periods"])
        self.event_only = set(cal["event_only_periods"])
        self.teaching_periods = [p["id"] for p in cal["periods"] if p["teaching"]]
        self.exam_days = list(cal["exam_week"]["days"])
        self.exam_sessions = [s["id"] for s in cal["exam_week"]["sessions"]]
        self.rooms = {r["id"]: r for r in data["rooms"]}
        self.fac = {f["id"]: f for f in data["faculty"]}
        self.groups = {g["id"]: g for g in data["student_groups"]}
        self.courses = {c["id"]: c for c in data["courses"]}
        self.sessions = list(data["sessions"])
        self.sess = {s["id"]: s for s in self.sessions}
        self.requests = {b["id"]: b for b in data["booking_requests"]}
        self.cancelled = {c["request_id"] for c in data.get("cancellations", [])}
        self.fac_un = defaultdict(set)
        for f in data["faculty"]:
            for u in f.get("unavailable", []):
                for p in u["periods"]:
                    self.fac_un[f["id"]].add((u["day"], p))
        self.room_un = defaultdict(set)
        self.room_un_reason = {}
        for u in data.get("room_unavailability", []):
            for p in u["periods"]:
                self.room_un[u["room_id"]].add((u["day"], p))
                self.room_un_reason[(u["room_id"], u["day"], p)] = u.get("reason", "")
        self.baskets = defaultdict(list)
        for s in self.sessions:
            if s.get("basket_slot"):
                self.baskets[s["basket_slot"]].append(s["id"])

    # ------------------------------------------------------------- sessions
    def start_ok(self, s, start):
        """Time-model rules only (P1-P9, lunch, lab starts, fixed slot ignored here)."""
        per = block(start, s["duration"])
        if start < 1 or per[-1] > LAST_TEACHING:
            return False
        if per[0] <= self.lunch < per[-1]:
            return False
        if s["kind"] == "lab" and start not in self.lab_starts:
            return False
        return True

    def valid_starts(self, s):
        fx = s.get("fixed_slot")
        out = []
        for d in self.days:
            for t in range(1, LAST_TEACHING + 1):
                if fx and (fx["day"] != d or fx["start_period"] != t):
                    continue
                if self.start_ok(s, t) and not any((d, p) in self.fac_un[s["faculty_id"]] for p in block(t, s["duration"])):
                    out.append((d, t))
        return out

    def room_fits(self, s, r):
        if r["type"] not in s["room_types"] or r["capacity"] < s["size"]:
            return False
        if s.get("needs_accessible") and not r["accessible"]:
            return False
        if self.fac.get(s["faculty_id"], {}).get("needs_accessible") and not r["accessible"]:
            return False
        return s["kind"] in r.get("allowed_uses", [s["kind"]])

    def valid_rooms(self, s):
        return [rid for rid, r in self.rooms.items() if self.room_fits(s, r)]

    def candidates(self, s):
        """All (day, start, room) placements that pass every per-session hard rule."""
        rooms = self.valid_rooms(s)
        out = []
        for d, t in self.valid_starts(s):
            per = block(t, s["duration"])
            for rid in rooms:
                if not any((d, p) in self.room_un[rid] for p in per):
                    out.append((d, t, rid))
        return out

    # ------------------------------------------------------------- bookings
    def effective_category(self, req):
        return official.effective_category(self.raw, req)

    def room_ok_for_request(self, req, rid):
        r = self.rooms[rid]
        feats = set(r["features"]) | ({"accessible"} if r["accessible"] else set())
        return ("event" in r["allowed_uses"] and r["type"] in req["room_types"]
                and r["capacity"] >= req["attendance"] and not (set(req["needs"]) - feats))

    def request_room_problems(self, req, rid):
        """Human-readable reasons a room does not suit a request (for alternatives/explanations)."""
        r = self.rooms.get(rid)
        if r is None:
            return [f"{rid} does not exist"]
        out = []
        if "event" not in r["allowed_uses"]:
            out.append(f"{rid} cannot host events")
        if r["type"] not in req["room_types"]:
            out.append(f"{rid} is a {r['type'].replace('_', ' ')}, not one of the requested room types")
        if r["capacity"] < req["attendance"]:
            out.append(f"{rid} seats {r['capacity']}, the request needs {req['attendance']}")
        feats = set(r["features"]) | ({"accessible"} if r["accessible"] else set())
        miss = sorted(set(req["needs"]) - feats)
        if miss:
            out.append(f"{rid} has no {', '.join(m.replace('_', ' ') for m in miss)}")
        return out

    def in_window_placements(self, req):
        """(day, a, b) inclusive spans that fit fully inside one requested window and P1-P13."""
        dur = req["duration_periods"]
        out = []
        for w in req["windows"]:
            if w["day"] not in self.days:
                continue
            for a in range(w["from_period"], min(w["to_period"], MAX_PERIOD) - dur + 2):
                if a >= 1:
                    out.append((w["day"], a, a + dur - 1))
        return sorted(set(out), key=lambda x: (self.days.index(x[0]), x[1]))


def fmt_slot(day, start, end=None):
    return f"{day} P{start}" if end is None or end == start else f"{day} P{start}-P{end}"
