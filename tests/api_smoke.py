#!/usr/bin/env python3
"""tests/api_smoke.py  End-to-end check of the running app over HTTP (standard library only).

    python3 tests/api_smoke.py http://127.0.0.1:8000 <DEMO_PASSWORD> [--reset]

Walks the demo story: login -> generate -> timetable/exams -> AI parse -> booking -> permissions
-> disruption proposal -> approval -> exports, and runs the official validator on the exports.
--reset first rebuilds the demo data (admin reset).
"""
import http.cookiejar
import json
import os
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request

BASE = sys.argv[1].rstrip("/")
PW = sys.argv[2]
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
passed = failed = 0


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = ""

    def call(self, path, body=None, raw=False, timeout=120):
        data = None if body is None else json.dumps(body).encode()
        req = urllib.request.Request(BASE + "/api/" + path, data=data, method="GET" if body is None else "POST")
        req.add_header("Content-Type", "application/json")
        if self.csrf:
            req.add_header("X-CSRF-Token", self.csrf)
        try:
            with self.op.open(req, timeout=timeout) as r:
                txt = r.read().decode()
                return (r.status, txt) if raw else (r.status, json.loads(txt))
        except urllib.error.HTTPError as e:
            txt = e.read().decode()
            return e.code, json.loads(txt) if txt.startswith("{") else txt

    def login(self, user):
        st, j = self.call("auth/login.php", {"username": user, "password": PW})
        assert st == 200, j
        self.csrf = j["data"]["csrf"]
        return j["data"]["user"]


def check(name, ok, detail=""):
    global passed, failed
    passed, failed = passed + bool(ok), failed + (not ok)
    print(("  ok    " if ok else "  FAIL  ") + name + ("" if ok or not detail else f"  -> {detail}"))


def validator(*args):
    p = subprocess.run([sys.executable, "-I", os.path.join(ROOT, "validator", "validator.py"), "--data",
                        os.path.join(ROOT, "data", "starter", "cit_public.json"), *args, "--quiet"], capture_output=True, text=True)
    return p.stdout


admin = Client()
admin.login("dean@cit.demo")
if "--reset" in sys.argv:
    st, j = admin.call("admin/reset.php", {})
    check("admin reset", st == 200 and j["ok"], j)

print("Permissions")
anon = Client()
st, j = anon.call("dashboard/summary.php")
check("anonymous user gets 401", st == 401)
club = Client()
club.login("dramatics@cit.demo")
st, j = club.call("schedule/generate.php", {})
check("club cannot generate (403)", st == 403, j)
st, j = Client().call("auth/login.php", {"username": "dean@cit.demo", "password": "wrong"})
check("wrong password rejected", st == 401)
nocsrf = Client(); nocsrf.login("dean@cit.demo"); nocsrf.csrf = "x"
st, j = nocsrf.call("schedule/generate.php", {})
check("POST without a valid CSRF token rejected (403 csrf)", st == 403 and j["error"]["code"] == "csrf")

print("Generate")
st, j = admin.call("schedule/generate.php", {}, timeout=90)
check("generate succeeds", st == 200 and j["ok"], j)
g = j.get("data", {})
check(f"0 hard violations (soft {g.get('soft_total')}, {g.get('seconds')}s)", g.get("hard_violations") == 0, g.get("violations"))
check("generation under 55 s (Firebase limit is 60)", (g.get("seconds") or 99) < 55)
check("no priority inversions", g.get("bookings", {}).get("priority_inversions") == [])

st, j = admin.call("schedule/get.php?view=group&id=CSE-2")
check("CSE-2 timetable has entries", st == 200 and len(j["data"]["entries"]) > 5)
st, j = admin.call("schedule/get.php?view=room&id=AUD&week=fest")
check("AUD Spectra-week view lists events", st == 200 and len(j["data"]["events"]) >= 3)
st, j = admin.call("exams/get.php")
check("41 exams scheduled", st == 200 and len(j["data"]["exams"]) == 41)
st, j = admin.call("schedule/explain.php?session_id=CS203-L1")
check("explain a session", st == 200 and "CS203-L1" in j["data"]["text"], j)

print("Export straight after generation + official validator")
st, txt = admin.call("schedule/export.php?save=1", raw=True)
tmp = tempfile.mkdtemp()
sched = os.path.join(tmp, "schedule.json")
open(sched, "w").write(txt)
out = validator("--schedule", sched)
check("exported schedule.json: 0 hard violations", "Hard violations: 0" in out, out)
check("exported schedule.json: 0 priority inversions", "priority inversions 0" in out, out)

print("AI parsing (rules or LLM)")
st, j = club.call("ai/parse_booking.php", {"text": "seminar hall kal shaam 6 baje, 2 ghante, 100 log, mic chahiye"})
f = j["data"]["fields"]
check("Hinglish: 100 people, 2 periods, P10-P11, pa_system, seminar hall",
      f["attendance"] == 100 and f["duration_periods"] == 2 and f["windows"][0]["from_period"] == 10 and f["windows"][0]["to_period"] == 11
      and "pa_system" in f["needs"] and f["room_types"] == ["seminar_hall"], f)
check("'kal' resolved to a weekday, shown as an assumption", any("kal" in a for a in j["data"]["assumptions"]), j["data"]["assumptions"])
st, j = club.call("ai/parse_booking.php", {"text": "I want SH-2 on Friday at 10 am for a wheelchair-accessible poster session, 60 people."})
check("SH-2 accessibility conflict flagged, SH-1 suggested", any("SH-2 is not wheelchair accessible" in w for w in j["data"]["warnings"])
      and any(s["room_id"] == "SH-1" for s in j["data"]["suggested_rooms"]), j["data"]["warnings"])
st, j = club.call("ai/parse_booking.php", {"text": "Need somewhere for the quiz finals after lunch on Thursday, maybe 150 people."})
check("vague request: window P6-P9 with visible assumptions", j["data"]["fields"]["windows"][0] == {"day": "THU", "from_period": 6, "to_period": 9}
      and j["data"]["assumptions"], j["data"])
st, j = club.call("ai/parse_booking.php", {"text": "need a room on thursday evening"})
check("missing attendance -> follow-up question", bool(j["data"]["follow_up"]), j["data"])

print("Bookings")
st, j = club.call("bookings/create.php", {"title": "Rehearsal", "attendance": 100, "duration_periods": 2, "requested_category": "institute_event",
                                          "windows": [{"day": "TUE", "from_period": 10, "to_period": 12}], "room_types": ["seminar_hall"], "needs": ["pa_system"]}, timeout=90)
check("club request decided at once", st == 200 and j["data"]["decisions"][0] is not None, j)
app_id = j["data"]["request_ids"][0] if st == 200 else None
d = j["data"]["decisions"][0] if st == 200 else {}
check(f"club claiming institute_event is capped to club_event ({d.get('status')})", d.get("effective_category") == "club_event", d)
st, j = club.call("bookings/get.php?id=BR-01")
check("club cannot read the Dean's request (403)", st == 403)
spectra = Client(); spectra.login("spectra@cit.demo")
st, j = spectra.call("bookings/review.php", {"id": "BR-01", "action": "confirm"})
check("Spectra Core Team cannot approve its own BR-01 (self-approval blocked)", st == 403 and j["error"]["code"] == "self_approval", j)
st, j = admin.call("bookings/review.php", {"id": "BR-01", "action": "confirm"})
check("the Dean (another admin) can confirm BR-01", st == 200 and j["data"]["decision"]["review_state"] == "confirmed", j)
st, j = admin.call("bookings/review.php", {"id": "BR-06", "action": "reject", "reason": "Hall reserved for jury"}, timeout=90)
check("admin rejects BR-06 with a reason", st == 200 and j["data"]["decision"]["status"] == "rejected", j)
st, j = admin.call("bookings/get.php?id=BR-07")
check("freed SH-1 goes to BR-07 (next in priority)", j["data"]["decision"]["room_id"] == "SH-1", j["data"]["decision"])
st, j = admin.call("schedule/validate.php")
inv = j["data"]["run"]["report"]["bookings"]["priority_inversions"]
check("manual rejection shows up as a validator priority inversion (expected, warned in the UI)", len(inv) == 1, inv)
st, j = club.call("bookings/cancel.php", {"id": app_id, "note": "Moved online"}, timeout=90)
check("requester cancels own request", st == 200 and j["data"]["state"] == "cancelled", j)
st, j = admin.call("bookings/availability.php?day=THU&from=10&to=12&attendance=100&types=seminar_hall,auditorium")
check("availability lists rooms with free/taken", st == 200 and any(not r["free"] for r in j["data"]["rooms"]), j)

print("Chat")
for q, intent in [("Is LH-1 free on Tuesday at 10 am?", "availability"), ("CSE-2 ka timetable batao", "timetable"),
                  ("why is BR-07 in AUD?", "explain"), ("what is the booking priority order?", "policy"),
                  ("status of my bookings", "booking_status"), ("book a seminar hall kal shaam 6 baje 100 log", "booking_request")]:
    st, j = club.call("ai/chat.php", {"message": q}, timeout=60)
    check(f"'{q}' -> {intent}", st == 200 and j["data"]["intent"] == intent and j["data"]["reply"], (j.get("data") or j))
st, j = club.call("ai/chat.php", {"message": "what is the booking priority order?"})
check("policy answer cites a source", j["data"]["sources"], j["data"])

print("Disruption")
st, j = admin.call("disruptions/upload.php", {"disruption": {"changes": [{"type": "swap_rooms"}]}})
check("bad change file rejected with reasons", st == 422 and j["error"]["problems"], j)
dis = json.load(open(os.path.join(ROOT, "data", "starter", "examples", "sample_disruption.json")))
st, j = admin.call("disruptions/upload.php", {"disruption": dis}, timeout=90)
check("sample disruption -> pending proposal", st == 200 and j["data"]["status"] == "pending", j)
pid = j["data"].get("proposal_id")
st, j = admin.call(f"disruptions/proposal.php?id={pid}")
check(f"diff lists moved sessions with reasons ({j['data']['sessions_moved']} moved)", all(c["reason"] for c in j["data"]["changes"]) and j["data"]["changes"], j)
st, j = club.call("disruptions/decide.php", {"id": pid, "action": "approve"})
check("club cannot approve a proposal (403)", st == 403)
st, j = admin.call("disruptions/decide.php", {"id": pid, "action": "approve"}, timeout=90)
check("admin approves -> applied", st == 200 and j["data"]["status"] == "approved", j)
st, j = admin.call("schedule/versions.php")
new_vid = j["data"][0]["id"]
st, txt = admin.call(f"schedule/export.php?kind=after&version={new_vid}&save=1", raw=True)
after = os.path.join(tmp, "schedule_after.json")
open(after, "w").write(txt)
out = validator("--schedule", after, "--disruption", os.path.join(ROOT, "data", "starter", "examples", "sample_disruption.json"), "--baseline", sched)
check("schedule_after.json: 0 hard violations", "Hard violations: 0" in out, out)
print("   ", [l for l in out.splitlines() if l.startswith("Stability")][0] if "Stability" in out else out)

print("Faculty change request")
fac = Client(); fac.login("f12@cit.demo")
st, j = fac.call("changes/request.php", {"text": "I can't teach on Wednesday morning", "preview": True})
check("preview parses WED P1-P5 for F12", st == 200 and j["data"]["changes"] and j["data"]["changes"][0]["periods"] == [1, 2, 3, 4, 5], j)
st, j = fac.call("changes/request.php", {"text": "x", "changes": [{"type": "faculty_unavailable", "faculty_id": "F01", "days": ["MON"], "periods": [1]}]})
check("faculty cannot report someone else's unavailability (403)", st == 403, j)
st, j = fac.call("changes/request.php", {"text": "I can't teach on Wednesday morning"}, timeout=90)
check("change request -> proposal for an Admin", st == 200 and j["data"]["status"] in ("pending", "infeasible"), j)

st, j = admin.call("dashboard/summary.php")
check("dashboard summary", st == 200 and j["data"]["validation"]["hard_violation_count"] == 0 and j["data"]["utilization"], j.get("error"))
print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
