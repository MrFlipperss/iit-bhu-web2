"""engine/export.py  [M1]  Builds schedule.json exactly in the README format, plus validation."""
import copy

from core import official


def schedule_json(D, team, assign, decisions=None, overrides=None, exams=None):
    """Sessions and decisions in dataset order, so diffs between versions stay readable."""
    doc = {"team": team, "dataset_id": D.raw["dataset_id"],
           "sessions": [{"session_id": s["id"], "day": assign[s["id"]][0], "start_period": assign[s["id"]][1],
                         "room_id": assign[s["id"]][2]} for s in D.sessions if s["id"] in assign]}
    if decisions is not None:
        known = set(D.requests)
        doc["fest_week"] = {"bookings": [copy.deepcopy(x) for x in decisions if x["request_id"] in known],
                            "session_overrides": copy.deepcopy(overrides or [])}
    if exams is not None:
        doc["exams"] = copy.deepcopy(exams)
    return doc


def validate(data, schedule, disruption=None, baseline=None):
    """Run the official validator exactly as `validator.py --data ... [--disruption --baseline]` would."""
    if disruption:
        data = official.apply_disruption(data, disruption)
    return official.run(data, schedule, baseline)


def assign_of(schedule):
    return {e["session_id"]: [e["day"], e["start_period"], e["room_id"]] for e in schedule.get("sessions", [])}
