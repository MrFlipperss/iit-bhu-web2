"""engine/cli.py  Single entry point for PHP.

    python engine/cli.py < request.json > response.json

Request:  {"command": "<name>", ...arguments}
Response: {"ok": true, ...result}  or  {"ok": false, "error": {"code": "...", "message": "..."}}

Commands
  ping                                   versions (deployment check)
  generate_all   dataset, team, time_limit, extra_requests?, start?, start_reserved?
                 timetable + bookings + exams + export + validation (start = improve an existing timetable)
  timetable      dataset, time_limit                          regular week only
  bookings       dataset, sessions, extra_requests?, reserved?, excluded? {id: reason}
                 decisions + overrides on an existing timetable (excluded = rejected by an Admin)
  exams          dataset                                      exam plan
  check_disruption dataset, disruption                         is the change file usable? (types, ids)
  apply_disruption dataset, disruption                         new dataset via the validator's apply_disruption
  repair         dataset, disruption, baseline (schedule.json), team, time_limit   minimum-move proposal
  validate       dataset, schedule, disruption?, baseline?     official validator report
  explain        dataset, schedule, session_id | request_id    why it is where it is
"""
import json
import os
import platform
import sys
import time
import traceback

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import core  # noqa: E402


class EngineError(Exception):
    def __init__(self, code, message, extra=None):
        super().__init__(message)
        self.code, self.message, self.extra = code, message, extra or {}


def need(req, *keys):
    for k in keys:
        if k not in req:
            raise EngineError("missing_argument", f"'{k}' is required for {req.get('command')}")
    return [req[k] for k in keys]


def cmd_ping(req):
    try:
        import ortools
        ov = ortools.__version__
    except Exception:  # noqa: BLE001
        ov = None
    return {"python": platform.python_version(), "ortools": ov, "validator": os.path.exists(os.path.join(HERE, "..", "validator", "validator.py"))}


def cmd_timetable(req):
    import timetable
    data, = need(req, "dataset")
    return timetable.best_timetable(core.Data(data), time_limit=float(req.get("time_limit", 24)), reserve=req.get("reserve", True))


def cmd_bookings(req):
    import bookings
    data, sessions = need(req, "dataset", "sessions")
    D = core.Data(data)
    dec, ov, det = bookings.decide(D, sessions, req.get("reserved"), req.get("extra_requests", []),
                                   excluded=req.get("excluded") or {})
    return {"decisions": dec, "session_overrides": ov, "details": det}


def cmd_exams(req):
    import exams
    data, = need(req, "dataset")
    return exams.solve(core.Data(data), time_limit=float(req.get("time_limit", 15)))


def cmd_generate_all(req):
    import bookings
    import exams
    import export
    import timetable
    data, = need(req, "dataset")
    D = core.Data(data)
    t0 = time.time()
    if req.get("start"):   # "Improve": continue from the current timetable (always at least as good)
        tt = timetable.solve(D, time_limit=float(req.get("time_limit", 30)), hint=req["start"],
                             hint_reserved=req.get("start_reserved"))
        current = {"status": "ok", "assign": req["start"], "reserved": req.get("start_reserved") or {}, "stats": {"kept": "current"}}
        if timetable.plan_score(D, current) <= timetable.plan_score(D, tt):
            tt = current              # never hand back something worse than what is active
    else:
        tt = timetable.best_timetable(D, time_limit=float(req.get("time_limit", 24)))
    if tt["status"] != "ok":
        raise EngineError("timetable_" + tt["status"], "No valid timetable found", {"reasons": tt.get("reasons", []), "stats": tt["stats"]})
    dec, ov, det = bookings.decide(D, tt["assign"], tt["reserved"], req.get("extra_requests", []),
                                   excluded=req.get("excluded") or {})
    ex = exams.solve(D, time_limit=float(req.get("exam_time_limit", 6)))
    if ex["status"] != "ok":
        raise EngineError("exams_" + ex["status"], "No valid exam plan found", {"reasons": ex.get("reasons", [])})
    sched = export.schedule_json(D, req.get("team", "CIT team"), tt["assign"], dec, ov, ex["exams"])
    report = export.validate(data, sched)
    return {"schedule": sched, "all_decisions": dec, "details": det, "reserved": tt["reserved"],
            "timetable_stats": tt["stats"], "exam_stats": ex["stats"], "report": report,
            "seconds": round(time.time() - t0, 2)}


def change_problems(data, dis):
    """Reasons a disruption file cannot be applied (empty list = OK)."""
    if not isinstance(dis, dict) or not isinstance(dis.get("changes"), list):
        return ["The file must be a JSON object with a \"changes\" list"]
    D = core.Data(data)
    out, known = [], {"faculty_unavailable", "room_unavailable", "add_faculty", "add_session", "remove_session"}
    fac, sess = set(D.fac), set(D.sess)
    for i, ch in enumerate(dis["changes"], 1):
        t = ch.get("type") if isinstance(ch, dict) else None
        if t not in known:
            out.append(f"Change {i}: unknown type {t!r} (allowed: {', '.join(sorted(known))})")
            continue
        days = ch.get("days", [])
        if t in ("faculty_unavailable", "room_unavailable"):
            if not days or any(d not in D.days for d in days) or not ch.get("periods") or any(not isinstance(p, int) or not 1 <= p <= core.MAX_PERIOD for p in ch["periods"]):
                out.append(f"Change {i}: needs days from {D.days} and periods 1-{core.MAX_PERIOD}")
        if t == "faculty_unavailable" and ch.get("faculty_id") not in fac:
            out.append(f"Change {i}: unknown faculty {ch.get('faculty_id')}")
        if t == "room_unavailable" and ch.get("room_id") not in D.rooms:
            out.append(f"Change {i}: unknown room {ch.get('room_id')}")
        if t == "add_faculty":
            f = ch.get("faculty") or {}
            miss = [k for k in ("id", "name", "department", "max_hours_per_day", "needs_accessible", "unavailable", "preferences") if k not in f]
            if miss:
                out.append(f"Change {i}: new faculty is missing {', '.join(miss)}")
            elif f["id"] in fac:
                out.append(f"Change {i}: faculty {f['id']} already exists")
            else:
                fac.add(f["id"])
        if t == "add_session":
            s = ch.get("session") or {}
            miss = [k for k in ("id", "course_id", "kind", "groups", "faculty_id", "size", "duration", "room_types", "needs_accessible") if k not in s]
            if miss:
                out.append(f"Change {i}: new session is missing {', '.join(miss)}")
                continue
            if s["id"] in sess:
                out.append(f"Change {i}: session {s['id']} already exists")
            if s["faculty_id"] not in fac:
                out.append(f"Change {i}: session {s['id']} names unknown faculty {s['faculty_id']}")
            if s["course_id"] not in D.courses:
                out.append(f"Change {i}: session {s['id']} names unknown course {s['course_id']}")
            bad = [g for g in s["groups"] if g not in D.groups]
            if bad:
                out.append(f"Change {i}: session {s['id']} names unknown groups {', '.join(bad)}")
            sess.add(s["id"])
        if t == "remove_session":
            if ch.get("session_id") not in sess:
                out.append(f"Change {i}: unknown session {ch.get('session_id')}")
            else:
                sess.discard(ch["session_id"])
    return out


def cmd_check_disruption(req):
    data, dis = need(req, "dataset", "disruption")
    return {"problems": change_problems(data, dis)}


def cmd_apply_disruption(req):
    data, dis = need(req, "dataset", "disruption")
    probs = change_problems(data, dis)
    if probs:
        raise EngineError("invalid_disruption", "The change file cannot be applied", {"problems": probs})
    return {"dataset": core.official.apply_disruption(data, dis)}


def cmd_repair(req):
    import repair
    data, dis, baseline = need(req, "dataset", "disruption", "baseline")
    probs = change_problems(data, dis)
    if probs:
        raise EngineError("invalid_disruption", "The change file cannot be applied", {"problems": probs})
    return repair.propose(data, dis, baseline, team=req.get("team", baseline.get("team", "CIT team")),
                          time_limit=float(req.get("time_limit", 25)))


def cmd_validate(req):
    import export
    data, sched = need(req, "dataset", "schedule")
    return {"report": export.validate(data, sched, req.get("disruption"), req.get("baseline"))}


def cmd_explain(req):
    import explain
    data, sched = need(req, "dataset", "schedule")
    D = core.Data(data)
    if req.get("session_id"):
        return explain.session(D, sched, req["session_id"])
    if req.get("request_id"):
        return explain.request(D, sched, req["request_id"])
    raise EngineError("missing_argument", "explain needs session_id or request_id")


COMMANDS = {name[4:]: fn for name, fn in globals().items() if name.startswith("cmd_")}


def main():
    try:
        req = json.load(sys.stdin)
        fn = COMMANDS.get(req.get("command"))
        if fn is None:
            raise EngineError("unknown_command", f"Unknown command {req.get('command')!r}; known: {', '.join(sorted(COMMANDS))}")
        out = {"ok": True}
        out.update(fn(req))
    except EngineError as e:
        out = {"ok": False, "error": {"code": e.code, "message": e.message, **e.extra}}
    except json.JSONDecodeError as e:
        out = {"ok": False, "error": {"code": "bad_json", "message": str(e)}}
    except Exception as e:  # noqa: BLE001
        traceback.print_exc(file=sys.stderr)
        out = {"ok": False, "error": {"code": "engine_error", "message": f"{type(e).__name__}: {e}"}}
    json.dump(out, sys.stdout, ensure_ascii=False)


if __name__ == "__main__":
    main()
