"""
dev/seed_users.py
Standalone Python script to generate / verify user credentials from the dataset.
Can export SQL, JSON, or CSV of all user accounts.
"""
import json
import os

DATASET_PATH = os.path.join(os.path.dirname(__file__), "..", "data", "cit_public.json")

def load_users():
    with open(DATASET_PATH, "r", encoding="utf-8") as fp:
        d = json.load(fp)

    users = [
        # ADMINS
        {
            "username": "dean@cit.demo",
            "display_name": "Dean of Academics",
            "role": "admin",
            "requester_name": "Office of the Dean of Academics",
            "faculty_id": "F37",
            "department": "ADMIN",
            "notes": "Full administrative access. Owns BR-03 (cannot self-approve; reviewed by Spectra Core Team)."
        },
        {
            "username": "spectra@cit.demo",
            "display_name": "Spectra Core Team",
            "role": "admin",
            "requester_name": "Spectra Core Team",
            "faculty_id": None,
            "department": None,
            "notes": "Admin team managing Spectra festival. Owns BR-01, BR-11a, BR-11b, BR-16, BR-20 (reviewed by Dean's Office)."
        },

        # COORDINATORS
        {
            "username": "coord.ece@cit.demo",
            "display_name": "ECE Coordinator",
            "role": "department_coordinator",
            "requester_name": "ECE Department",
            "faculty_id": None,
            "department": "ECE",
            "notes": "ECE Department coordinator. Owns BR-08 (ECE alumni seminar)."
        },
        {
            "username": "coord.cse@cit.demo",
            "display_name": "CSE Coordinator",
            "role": "department_coordinator",
            "requester_name": "CSE Department",
            "faculty_id": None,
            "department": "CSE",
            "notes": "Computer Science & Engineering Department coordinator."
        },
        {
            "username": "coord.ee@cit.demo",
            "display_name": "EE Coordinator",
            "role": "department_coordinator",
            "requester_name": "EE Department",
            "faculty_id": None,
            "department": "EE",
            "notes": "Electrical Engineering Department coordinator."
        },
        {
            "username": "coord.me@cit.demo",
            "display_name": "ME Coordinator",
            "role": "department_coordinator",
            "requester_name": "ME Department",
            "faculty_id": None,
            "department": "ME",
            "notes": "Mechanical Engineering Department coordinator."
        },
        {
            "username": "coord.ce@cit.demo",
            "display_name": "CE Coordinator",
            "role": "department_coordinator",
            "requester_name": "Civil Engineering Department",
            "faculty_id": None,
            "department": "CE",
            "notes": "Civil Engineering Department coordinator. Owns BR-18 (Bridge-building contest)."
        },
        {
            "username": "coord.ma@cit.demo",
            "display_name": "Mathematics Coordinator",
            "role": "department_coordinator",
            "requester_name": "Mathematics Department",
            "faculty_id": None,
            "department": "MA",
            "notes": "Mathematics Department coordinator."
        },
        {
            "username": "coord.ph@cit.demo",
            "display_name": "Physics Coordinator",
            "role": "department_coordinator",
            "requester_name": "Physics Department",
            "faculty_id": None,
            "department": "PH",
            "notes": "Physics Department coordinator."
        },
        {
            "username": "coord.hss@cit.demo",
            "display_name": "HSS Coordinator",
            "role": "department_coordinator",
            "requester_name": "HSS Department",
            "faculty_id": None,
            "department": "HSS",
            "notes": "Humanities & Social Sciences Department coordinator."
        },
        {
            "username": "tnp@cit.demo",
            "display_name": "Training & Placement Officer",
            "role": "department_coordinator",
            "requester_name": "Training and Placement Cell",
            "faculty_id": None,
            "department": "CSE",
            "notes": "Training & Placement Cell. Owns BR-13 (Pre-placement talk for CSE-3)."
        },

        # CLUBS
        {
            "username": "astronomy@cit.demo",
            "display_name": "Astronomy Club",
            "role": "club",
            "requester_name": "Astronomy Club",
            "faculty_id": None,
            "department": None,
            "notes": "Owns BR-17 (Night sky watch at OAT)."
        },
        {
            "username": "coding@cit.demo",
            "display_name": "Coding Club",
            "role": "club",
            "requester_name": "Coding Club",
            "faculty_id": None,
            "department": None,
            "notes": "Owns BR-02 (Spectra Code Sprint 8-hour hackathon)."
        },
        {
            "username": "dance@cit.demo",
            "display_name": "Dance Club",
            "role": "club",
            "requester_name": "Dance Club",
            "faculty_id": None,
            "department": None,
            "notes": "Owns series BR-05a..e (Daily rehearsals at OAT)."
        },
        {
            "username": "dramatics@cit.demo",
            "display_name": "Dramatics Society",
            "role": "club",
            "requester_name": "Dramatics Society",
            "faculty_id": None,
            "department": None,
            "notes": "Owns BR-07 (Stage play: Andha Yug in SH-1)."
        },
        {
            "username": "ecell@cit.demo",
            "display_name": "E-Cell",
            "role": "club",
            "requester_name": "E-Cell",
            "faculty_id": None,
            "department": None,
            "notes": "Owns BR-12 (Founder talk guest lecture)."
        },
        {
            "username": "gaming@cit.demo",
            "display_name": "Gaming Society",
            "role": "club",
            "requester_name": "Gaming Society",
            "faculty_id": None,
            "department": None,
            "notes": "Owns BR-09 (Valorant LAN finals screening, 1200 participants)."
        },
        {
            "username": "literary@cit.demo",
            "display_name": "Literary Society",
            "role": "club",
            "requester_name": "Literary Society",
            "faculty_id": None,
            "department": None,
            "notes": "Owns BR-14 (Spectra Open Mic in LHC-5)."
        },
        {
            "username": "music@cit.demo",
            "display_name": "Music Club",
            "role": "club",
            "requester_name": "Music Club",
            "faculty_id": None,
            "department": None,
            "notes": "Owns BR-15 (Spectra Jam Night, 300 participants)."
        },
        {
            "username": "photo@cit.demo",
            "display_name": "Photography Club",
            "role": "club",
            "requester_name": "Photography Club",
            "faculty_id": None,
            "department": None,
            "notes": "Owns BR-10 (Spectra photo exhibition, accessible venue needed)."
        },
        {
            "username": "quiz@cit.demo",
            "display_name": "Quiz Club",
            "role": "club",
            "requester_name": "Quiz Club",
            "faculty_id": None,
            "department": None,
            "notes": "Owns BR-06 (Spectra Quiz Prelims, 140 participants in SH-1)."
        },
        {
            "username": "robotics@cit.demo",
            "display_name": "Robotics Club",
            "role": "club",
            "requester_name": "Robotics Club",
            "faculty_id": None,
            "department": None,
            "notes": "Owns BR-04 (Line-follower robot workshop)."
        },
    ]

    for f in d["faculty"]:
        fid = f["id"]
        username = f"{fid.lower()}@cit.demo"
        name = f["name"]
        dept = f["department"]
        if fid == "F12":
            req = "Dr. Amit Behera (F12)"
            notes = "ECE Faculty. Owns BR-19 (Industry talk on chip design). Can report faculty unavailability."
        elif fid == "F37":
            req = "Office of the Dean of Academics"
            notes = "Dean of Academics. Can access faculty view or Dean administration."
        elif fid == "F07":
            req = f"{name} ({fid})"
            notes = "CSE Faculty. Leaves on Monday as featured in sample disruption."
        elif fid == "F06":
            req = f"{name} ({fid})"
            notes = "CSE Faculty. Assigned tutorial CS202-T2 in sample disruption."
        elif fid == "F08":
            req = f"{name} ({fid})"
            notes = "Visiting Faculty (on campus Tue & Thu only)."
        else:
            req = f"{name} ({fid})"
            notes = f"{dept} Faculty member. Can report unavailability and query personal schedule in AI chat."

        users.append({
            "username": username,
            "display_name": name,
            "role": "faculty",
            "requester_name": req,
            "faculty_id": fid,
            "department": dept,
            "notes": notes
        })

    return users

if __name__ == "__main__":
    u_list = load_users()
    print(f"Loaded {len(u_list)} users.")
    by_role = {}
    for u in u_list:
        by_role.setdefault(u["role"], []).append(u)
    for r, items in by_role.items():
        print(f"  {r}: {len(items)} users")
