"""engine/explain.py  [M1]  Deterministic answers to "why is this here?".

The facts come from here; the LLM may only rephrase them.
"""
from collections import defaultdict

from core import LAST_TEACHING, block, fmt_slot
from export import assign_of


def _local_penalty(D, s, d, t, r):
    per = block(t, s["duration"])
    c, parts = 0, []
    for pref in D.fac[s["faculty_id"]].get("preferences", []):
        if pref.get("type") == "avoid" and d in pref.get("days", D.days):
            k = 2 * sum(1 for p in per if p in pref["periods"])
            if k:
                c += k
                parts.append(f"{s['faculty_id']} prefers to avoid it (+{k})")
    if LAST_TEACHING in per:
        c += 1
        parts.append("uses the 5 pm period (+1)")
    if D.rooms[r]["capacity"] and s["size"] / D.rooms[r]["capacity"] < 0.4:
        c += 1
        parts.append(f"{r} would be under 40% full (+1)")
    return c, parts


def session(D, sched, sid):
    if sid not in D.sess:
        return {"found": False, "text": f"There is no session {sid}."}
    s = D.sess[sid]
    assign = assign_of(sched)
    cur = assign.get(sid)
    others = {k: v for k, v in assign.items() if k != sid and k in D.sess}
    fac_busy, grp_busy, room_busy, fac_hours = {}, {}, defaultdict(set), defaultdict(int)
    for o, (d, t, r) in others.items():
        so = D.sess[o]
        for p in block(t, so["duration"]):
            if so["faculty_id"] == s["faculty_id"]:
                fac_busy[(d, p)] = o
            for g in so["groups"]:
                if g in s["groups"] and not (s.get("basket_slot") and s.get("basket_slot") == so.get("basket_slot")):
                    grp_busy[(d, p)] = (g, o)
            room_busy[(d, p)].add(r)
        if so["faculty_id"] == s["faculty_id"]:
            fac_hours[d] += so["duration"]
    rooms = D.valid_rooms(s)
    rows = []
    for d in D.days:
        for t in range(1, LAST_TEACHING + 1):
            per = block(t, s["duration"])
            row = {"day": d, "start_period": t}
            if cur and cur[0] == d and cur[1] == t:
                row["status"] = "current"
            elif not D.start_ok(s, t):
                row["status"], row["why"] = "blocked", ("labs start only at P2 or P6" if s["kind"] == "lab" and t not in D.lab_starts
                                                        else "would run past P9 or across lunch")
            elif s.get("fixed_slot") and (s["fixed_slot"]["day"], s["fixed_slot"]["start_period"]) != (d, t):
                row["status"], row["why"] = "blocked", "this session is fixed to another slot"
            elif any((d, p) in D.fac_un[s["faculty_id"]] for p in per):
                row["status"], row["why"] = "blocked", f"{s['faculty_id']} is unavailable"
            elif any((d, p) in fac_busy for p in per):
                o = next(fac_busy[(d, p)] for p in per if (d, p) in fac_busy)
                row["status"], row["why"] = "blocked", f"{s['faculty_id']} teaches {o} then"
            elif any((d, p) in grp_busy for p in per):
                g, o = next(grp_busy[(d, p)] for p in per if (d, p) in grp_busy)
                row["status"], row["why"] = "blocked", f"{g} has {o} then"
            elif fac_hours[d] + s["duration"] > D.fac[s["faculty_id"]].get("max_hours_per_day", 4) and not (cur and cur[0] == d):
                row["status"], row["why"] = "blocked", f"{s['faculty_id']} would exceed {D.fac[s['faculty_id']].get('max_hours_per_day', 4)} hours that day"
            else:
                free = [r for r in rooms if not any(r in room_busy[(d, p)] or (d, p) in D.room_un[r] for p in per)]
                if not free:
                    row["status"], row["why"] = "blocked", "no suitable room is free"
                else:
                    best = min(free, key=lambda r: _local_penalty(D, s, d, t, r)[0])
                    pen, parts = _local_penalty(D, s, d, t, best)
                    row.update(status="possible", room=best, penalty=pen, penalty_parts=parts)
            rows.append(row)
    possible = [r for r in rows if r["status"] == "possible"]
    blocked = [r for r in rows if r["status"] == "blocked"]
    counts = defaultdict(int)
    for r in blocked:
        counts[r["why"].split(" then")[0] if " teaches " in r["why"] or " has " in r["why"] else r["why"]] += 1
    text = []
    if cur:
        pen, parts = _local_penalty(D, s, cur[0], cur[1], cur[2])
        text.append(f"{sid} ({s['kind']}, {s['size']} students, {s['faculty_id']}) is at {fmt_slot(cur[0], cur[1], cur[1] + s['duration'] - 1)} in {cur[2]}.")
        text.append(f"Rooms that fit it: {', '.join(rooms)}.")
        text.append(f"{len(blocked)} of {len(rows)} start times are ruled out by hard rules; {len(possible)} others are open"
                    + (f", and none has a lower local penalty than {pen}." if possible and min(p['penalty'] for p in possible) >= pen
                       else "; moving it there could change other sessions too (the solver optimises the whole week)."))
        if parts:
            text.append("Its current slot costs: " + "; ".join(parts) + ".")
    return {"found": True, "session_id": sid, "current": cur, "rooms_that_fit": rooms, "grid": rows, "text": " ".join(text)}


def request(D, sched, rid):
    req = D.requests.get(rid)
    if req is None:
        return {"found": False, "text": f"There is no request {rid}."}
    dec = next((b for b in sched.get("fest_week", {}).get("bookings", []) if b["request_id"] == rid), None)
    rooms = []
    for r in D.rooms:
        probs = D.request_room_problems(req, r)
        if r in req["room_types"] or D.rooms[r]["type"] in req["room_types"]:
            rooms.append({"room_id": r, "suitable": not probs, "problems": probs})
    cat = D.effective_category(req)
    text = f"{rid} ({req['title']}) is treated as {cat.replace('_', ' ')}"
    if cat != req["requested_category"]:
        text += f" because {req['role'].replace('_', ' ')} requests are capped there"
    text += "."
    if dec:
        text += f" Decision: {dec['status'].replace('_', ' ')}. {dec.get('reason', '')}."
    return {"found": True, "request_id": rid, "effective_category": cat, "decision": dec, "rooms": rooms, "text": text}
