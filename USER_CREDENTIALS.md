# CIT Crunch Week — Master User Login Credentials & Evaluation Guide

> **Note for Judges & Evaluators:**
> All accounts are fully integrated with the database schema, role-based access control, the official Chilika Institute of Technology (CIT) Crunch Week dataset, and the timetable/venue scheduling engine.
> You can log into **any account** using the **Quick switcher dropdown** on the login page (`/index.html`) or by entering the username and password below.

## Global Credentials Summary

- **Default Password (all accounts):** `Kian@2407` *(or the custom `DEMO_PASSWORD` set in `.env`)*
- **Total Accounts in Database:** `62`
  - **Administrators:** `2` (Dean of Academics, Spectra Core Team)
  - **Department Coordinators:** `9` (CSE, ECE, EE, ME, CE, MA, PH, HSS, Training & Placement)
  - **Student Clubs:** `11` (All student organisations submitting festival venue requests)
  - **Faculty Members:** `40` (F01 through F40 with faculty profiles, departments, and leave permissions)

---

## 1. Administrators (Role: `admin`)

Admins hold approval authority (`can_approve = 1`), can run the scheduler engine, trigger disruption rescheduling, reset database states, and review booking requests.

| Username | Password | Display Name | Department | Faculty ID | Associated Requests | Key Evaluation Scenarios |
|---|---|---|---|---|---|---|
| `dean@cit.demo` | `Kian@2407` | Dean of Academics | ADMIN | F37 | BR-03 | Full administrative access. Owns BR-03 (cannot self-approve; reviewed by Spectra Core Team). |
| `spectra@cit.demo` | `Kian@2407` | Spectra Core Team | — | — | BR-01, BR-11a, BR-11b, BR-16, BR-20 | Admin team managing Spectra festival. Owns BR-01, BR-11a, BR-11b, BR-16, BR-20 (reviewed by Dean's Office). |

> [!IMPORTANT]
> **No Self-Approval Rule Enforcement:**
> An admin cannot approve their own booking request. When logged in as `dean@cit.demo`, the Dean's request **BR-03** shows a disabled approval button. Log in as `spectra@cit.demo` to approve BR-03. Conversely, the Dean reviews and approves Spectra's requests (**BR-01, BR-11a, BR-11b, BR-16, BR-20**).

---

## 2. Department Coordinators (Role: `department_coordinator`)

Department coordinators represent academic departments. They can submit department-level bookings, request guest lectures compulsory for student cohorts, and view department timetables.

| Username | Password | Display Name | Department | Requester Identity | Associated Requests | Compulsory Student Groups |
|---|---|---|---|---|---|---|
| `coord.ece@cit.demo` | `Kian@2407` | ECE Coordinator | ECE | ECE Department | BR-08 | ECE-3 |
| `coord.cse@cit.demo` | `Kian@2407` | CSE Coordinator | CSE | CSE Department | — | — |
| `coord.ee@cit.demo` | `Kian@2407` | EE Coordinator | EE | EE Department | — | — |
| `coord.me@cit.demo` | `Kian@2407` | ME Coordinator | ME | ME Department | — | — |
| `coord.ce@cit.demo` | `Kian@2407` | CE Coordinator | CE | Civil Engineering Department | BR-18 | CE-3 |
| `coord.ma@cit.demo` | `Kian@2407` | Mathematics Coordinator | MA | Mathematics Department | — | — |
| `coord.ph@cit.demo` | `Kian@2407` | Physics Coordinator | PH | Physics Department | — | — |
| `coord.hss@cit.demo` | `Kian@2407` | HSS Coordinator | HSS | HSS Department | — | — |
| `tnp@cit.demo` | `Kian@2407` | Training & Placement Officer | CSE | Training and Placement Cell | BR-13 | CSE-3 |

---

## 3. Student Club Representatives (Role: `club`)

Club representatives submit and track venue requests for the Spectra fest (Week 7). Each club's dashboard isolates their own booking requests and history.

| Username | Password | Club Name | Requests | Preferred Venues | Special Characteristics |
|---|---|---|---|---|---|
| `astronomy@cit.demo` | `Kian@2407` | Astronomy Club | BR-17 | OAT, outdoor | Owns BR-17 (Night sky watch at OAT). |
| `coding@cit.demo` | `Kian@2407` | Coding Club | BR-02 | lab_cs | Owns BR-02 (Spectra Code Sprint 8-hour hackathon). |
| `dance@cit.demo` | `Kian@2407` | Dance Club | BR-05a, BR-05b, BR-05c, BR-05d, BR-05e | OAT, outdoor, seminar_hall | Series: SER-DANCE |
| `dramatics@cit.demo` | `Kian@2407` | Dramatics Society | BR-07 | SH-1, auditorium, seminar_hall | Owns BR-07 (Stage play: Andha Yug in SH-1). |
| `ecell@cit.demo` | `Kian@2407` | E-Cell | BR-12 | auditorium, lecture_hall | Owns BR-12 (Founder talk guest lecture). |
| `gaming@cit.demo` | `Kian@2407` | Gaming Society | BR-09 | auditorium, outdoor | High attendance (1200) |
| `literary@cit.demo` | `Kian@2407` | Literary Society | BR-14 | LHC-5, lecture_hall, seminar_hall | Owns BR-14 (Spectra Open Mic in LHC-5). |
| `music@cit.demo` | `Kian@2407` | Music Club | BR-15 | auditorium, outdoor | Owns BR-15 (Spectra Jam Night, 300 participants). |
| `photo@cit.demo` | `Kian@2407` | Photography Club | BR-10 | seminar_hall | Needs wheelchair access |
| `quiz@cit.demo` | `Kian@2407` | Quiz Club | BR-06 | SH-1, auditorium, lecture_hall, seminar_hall | Owns BR-06 (Spectra Quiz Prelims, 140 participants in SH-1). |
| `robotics@cit.demo` | `Kian@2407` | Robotics Club | BR-04 | lab_elec, seminar_hall | Owns BR-04 (Line-follower robot workshop). |

---

## 4. Faculty Members (Role: `faculty`, 40 Accounts: `F01` to `F40`)

Every faculty member in the dataset has a personal account linked to their `faculty_id` and `department`. Faculty can:
1. **Report Leave / Unavailability:** Submit leave requests in `/request.html` that create disruption proposals (only for their own faculty ID).
2. **Personal Timetable:** View personal schedule in `/timetable.html?view=faculty&id=Fxx`, where personal unavailability reasons are revealed exclusively to the faculty member and administrators.
3. **AI Chat Assistant:** Ask questions like *'When is my next lecture?'* or *'What classes do I teach on Wednesday?'* in `/assistant.html`, and the assistant personalizes answers using their faculty ID.

| Username | Password | Faculty Name | Faculty ID | Department | Max Hrs/Day | Dataset Unavailability & Constraints |
|---|---|---|---|---|---|---|
| `f01@cit.demo` | `Kian@2407` | Dr. Ananya Mishra | `F01` | CSE | 4 | WED P6,7,8,9 (HOD meeting) |
| `f02@cit.demo` | `Kian@2407` | Dr. Rohit Sahoo | `F02` | CSE | 4 | Prefers avoiding Prefers not to teach at 8 AM |
| `f03@cit.demo` | `Kian@2407` | Dr. Kavya Iyer | `F03` | CSE | 4 | Standard availability |
| `f04@cit.demo` | `Kian@2407` | Dr. Sandeep Patnaik | `F04` | CSE | 4 | Standard availability |
| `f05@cit.demo` | `Kian@2407` | Dr. Meera Nair | `F05` | CSE | 4 | Prefers avoiding Prefers not to teach at 8 AM |
| `f06@cit.demo` | `Kian@2407` | Dr. Arjun Mehta | `F06` | CSE | 4 | Prefers avoiding Keeps Friday afternoons for research |
| `f07@cit.demo` | `Kian@2407` | Dr. Pooja Das | `F07` | CSE | 4 | Standard availability |
| `f08@cit.demo` | `Kian@2407` | Prof. Vikram Rao (Visiting) | `F08` | CSE | 4 | MON P1,2,3,4,5,6,7,8,9 (Visiting faculty: on campus Tue and Thu only); WED P1,2,3,4,5,6,7,8,9 (Visiting faculty: on campus Tue and Thu only); FRI P1,2,3,4,5,6,7,8,9 (Visiting faculty: on campus Tue and Thu only) |
| `f09@cit.demo` | `Kian@2407` | Dr. Ishita Banerjee | `F09` | ECE | 4 | WED P6,7,8,9 (HOD meeting) |
| `f10@cit.demo` | `Kian@2407` | Dr. Suresh Pradhan | `F10` | ECE | 4 | Prefers avoiding Prefers mornings |
| `f11@cit.demo` | `Kian@2407` | Dr. Neha Kulkarni | `F11` | ECE | 4 | Standard availability |
| `f12@cit.demo` | `Kian@2407` | Dr. Amit Behera | `F12` | ECE | 4 | Standard availability |
| `f13@cit.demo` | `Kian@2407` | Dr. Ritu Sharma | `F13` | ECE | 4 | Standard availability |
| `f14@cit.demo` | `Kian@2407` | Dr. Kunal Joshi | `F14` | EE | 4 | WED P6,7,8,9 (HOD meeting) |
| `f15@cit.demo` | `Kian@2407` | Dr. Sneha Mohanty | `F15` | EE | 4 | Standard availability |
| `f16@cit.demo` | `Kian@2407` | Dr. Rakesh Panda | `F16` | EE | 4 | Standard availability |
| `f17@cit.demo` | `Kian@2407` | Dr. Divya Menon | `F17` | EE | 4 | Prefers avoiding Avoid the 5 PM slot |
| `f18@cit.demo` | `Kian@2407` | Dr. Manoj Tripathy | `F18` | EE | 4 | Standard availability |
| `f19@cit.demo` | `Kian@2407` | Dr. Shalini Gupta | `F19` | MA | 4 | Prefers avoiding Avoid the 5 PM slot |
| `f20@cit.demo` | `Kian@2407` | Dr. Prakash Nayak | `F20` | MA | 4 | Standard availability |
| `f21@cit.demo` | `Kian@2407` | Dr. Lakshmi Reddy | `F21` | PH | 4 | FRI P1,2,3,4,5,6,7,8,9 (Research travel every Friday) |
| `f22@cit.demo` | `Kian@2407` | Dr. Harish Dash | `F22` | PH | 4 | Standard availability |
| `f23@cit.demo` | `Kian@2407` | Dr. Farah Khan | `F23` | PH | 4 | Standard availability |
| `f24@cit.demo` | `Kian@2407` | Dr. Gaurav Jena | `F24` | PH | 4 | Standard availability |
| `f25@cit.demo` | `Kian@2407` | Dr. Tanvi Desai | `F25` | ME | 4 | WED P6,7,8,9 (HOD meeting) |
| `f26@cit.demo` | `Kian@2407` | Dr. Abhishek Rout | `F26` | ME | 4 | Requires wheelchair accessible classroom |
| `f27@cit.demo` | `Kian@2407` | Dr. Sunita Bose | `F27` | ME | 4 | Standard availability |
| `f28@cit.demo` | `Kian@2407` | Dr. Nikhil Swain | `F28` | ME | 4 | Standard availability |
| `f29@cit.demo` | `Kian@2407` | Dr. Priya Raman | `F29` | ME | 4 | Standard availability |
| `f30@cit.demo` | `Kian@2407` | Dr. Debashish Sethi | `F30` | ME | 4 | Standard availability |
| `f31@cit.demo` | `Kian@2407` | Dr. Anjali Verma | `F31` | CE | 4 | WED P6,7,8,9 (HOD meeting) |
| `f32@cit.demo` | `Kian@2407` | Dr. Sameer Khuntia | `F32` | CE | 4 | Prefers avoiding No Monday 8 AM |
| `f33@cit.demo` | `Kian@2407` | Dr. Rekha Pillai | `F33` | CE | 4 | Standard availability |
| `f34@cit.demo` | `Kian@2407` | Dr. Varun Acharya | `F34` | CE | 4 | Standard availability |
| `f35@cit.demo` | `Kian@2407` | Dr. Bhavna Saxena | `F35` | CE | 4 | Standard availability |
| `f36@cit.demo` | `Kian@2407` | Dr. Ashok Mahapatra | `F36` | HSS | 4 | MON P1,2,3 (Teaches at partner school Monday mornings) |
| `f37@cit.demo` | `Kian@2407` | Prof. Shreya Kapoor (Dean) | `F37` | ADMIN | 4 | TUE P1,2,3,4,5,6,7,8,9 (Board meetings Tue/Thu); THU P1,2,3,4,5,6,7,8,9 (Board meetings Tue/Thu); MON P1,2,6,7,8,9 (Dean's office hours); WED P1,2,6,7,8,9 (Dean's office hours); FRI P1,2,6,7,8,9 (Dean's office hours) |
| `f38@cit.demo` | `Kian@2407` | Dr. Imran Qureshi | `F38` | HSS | 4 | Standard availability |
| `f39@cit.demo` | `Kian@2407` | Dr. Radhika Hegde | `F39` | HSS | 4 | Standard availability |
| `f40@cit.demo` | `Kian@2407` | Dr. Om Prakash Sahu | `F40` | HSS | 4 | Standard availability |

---

## 5. Quick Evaluation Scenarios for Judges

### Scenario A: Test Admin Controls and Cross-Approval (No Self-Approval)
1. Log in as **`dean@cit.demo`** (`Dean of Academics`).
2. Navigate to **Bookings**. Notice that request **BR-03** was submitted by the Dean's Office.
3. Observe that the approval action for BR-03 is protected (you cannot approve your own request).
4. Log out, then log in as **`spectra@cit.demo`** (`Spectra Core Team`).
5. Go to **Bookings** and approve **BR-03**. Notice how approval history records the reviewer.

### Scenario B: Test Faculty Leave Reporting & Timetable Disruption
1. Log in as **`f07@cit.demo`** (`Dr. Pooja Das`, CSE).
2. Go to **Submit Request** (`/request.html`).
3. Report unavailability for Monday (matching the practice disruption). Notice you can only report leave for your own faculty ID (`F07`).
4. Switch to an admin account to view the disruption proposal in **Reschedule** (`/reschedule.html`), generate minimum-movement repairs, and approve the revised timetable.

### Scenario C: Test AI Natural Language Assistant with Faculty Identity
1. Log in as **`f12@cit.demo`** (`Dr. Amit Behera`, ECE).
2. Open the **AI Assistant** (`/assistant.html`).
3. Type: `What is my schedule on Tuesday?` or `mera schedule batao`.
4. The AI assistant detects your identity as `F12` and replies with Dr. Amit Behera's specific lectures and lab sessions without asking for your faculty ID.

### Scenario D: Test Club Booking Requests & Isolation
1. Log in as **`coding@cit.demo`** (`Coding Club`).
2. Navigate to **Bookings**. You see **BR-02** (8-hour hackathon).
3. Submit a new venue request using plain English or Hinglish: *'Computer lab for 50 people on Friday 3 pm to 6 pm'*.
4. The system validates availability, suggests eligible labs (`lab_cs`), and attaches your club identity.

---

## 6. How to Seed or Re-initialize Credentials in the Database

Credentials are automatically generated whenever the database is initialized or reset. You can also re-seed them manually at any time:

### Option 1: Via the Web Interface (Easiest)
1. Log in as `dean@cit.demo`.
2. Open **Admin** (`/admin.html`).
3. Click **Reset everything** under *Reset demo data*.
4. All 62 accounts are instantly seeded with the configured `DEMO_PASSWORD`.

### Option 2: Via PHP CLI
```bash
php database/seed_users.php               # Uses Kian@2407 or DEMO_PASSWORD
php database/seed_users.php mypassword   # Sets a custom password for all accounts
```

### Option 3: Via MySQL / MariaDB SQL Script
```bash
mysql -u cit -p cit_scheduler < database/seed_all_users.sql
```

### Option 4: Full Database Reset from Starter Pack
```bash
php database/reset.php
```