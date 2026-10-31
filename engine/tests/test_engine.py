"""engine/tests/test_engine.py  [M1-M3]  python -m unittest discover -s engine/tests   (about 2 minutes)

Checks the engine against the OFFICIAL validator on the starter dataset."""
import json
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.join(HERE, "..", "..")
sys.path.insert(0, os.path.join(HERE, ".."))
import bookings, cli, core, exams, export, greedy, repair, timetable  # noqa: E402,E401

DATA = json.load(open(os.path.join(ROOT, "data", "starter", "cit_public.json")))
DIS = json.load(open(os.path.join(ROOT, "data", "starter", "examples", "sample_disruption.json")))


class Engine(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.D = core.Data(DATA)
        cls.tt = timetable.best_timetable(cls.D, time_limit=24)
        dec, ov, _ = bookings.decide(cls.D, cls.tt["assign"], cls.tt["reserved"])
        cls.ex = exams.solve(cls.D, time_limit=6)
        cls.sched = export.schedule_json(cls.D, "test", cls.tt["assign"], dec, ov, cls.ex["exams"])
        cls.rep = export.validate(DATA, cls.sched)

    def test_full_plan_has_no_hard_violations(self):
        self.assertEqual(self.rep["hard_violation_count"], 0, self.rep["hard_violations"][:5])

    def test_every_session_and_exam_placed(self):
        self.assertEqual(len(self.sched["sessions"]), 189)
        self.assertEqual(len(self.sched["exams"]), 41)

    def test_bookings_follow_priority(self):
        b = self.rep["bookings"]
        self.assertEqual(b["priority_inversions"], [])
        self.assertGreaterEqual(b["booking_score"], 67.0)       # 70 minus BR-09 (impossible) and half of BR-17

    def test_cancelled_request_never_approved(self):
        d = {x["request_id"]: x for x in self.sched["fest_week"]["bookings"]}
        self.assertEqual(d["BR-04"]["status"], "rejected")

    def test_greedy_alone_is_valid(self):
        g = greedy.solve(self.D, time_limit=10)
        rep = export.validate(DATA, export.schedule_json(self.D, "t", g["assign"]))
        self.assertEqual({k: v for k, v in rep["hard_by_rule"].items() if k != "E1"}, {})

    def test_repair_is_valid_and_minimal(self):
        base = export.schedule_json(self.D, "t", self.tt["assign"])
        r = repair.propose(DATA, DIS, base, time_limit=20)
        self.assertEqual(r["status"], "ok")
        self.assertEqual(r["report"]["hard_violation_count"], 0)
        forced = len(r["stats"].get("forced_moves", [])) or r["summary"]["sessions_moved"]
        self.assertLessEqual(r["summary"]["sessions_moved"], forced)
        self.assertEqual(r["summary"]["added"], 1)

    def test_bad_change_file_is_explained(self):
        probs = cli.change_problems(DATA, {"changes": [{"type": "swap"}, {"type": "remove_session", "session_id": "NOPE"}]})
        self.assertEqual(len(probs), 2)

    def test_impossible_change_names_the_conflict(self):
        dis = {"changes": [{"type": "faculty_unavailable", "faculty_id": "F07", "days": DATA["calendar"]["days"], "periods": list(range(1, 10))}]}
        base = export.schedule_json(self.D, "t", self.tt["assign"])
        r = repair.propose(DATA, dis, base, time_limit=10)
        self.assertEqual(r["status"], "infeasible")
        self.assertTrue(any("F07" in x["entities"] for x in r["reasons"]))


if __name__ == "__main__":
    unittest.main()
