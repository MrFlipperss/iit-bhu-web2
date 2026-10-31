# CIT Campus Scheduling & Venue Management

One web platform for Chilika Institute of Technology's Crunch Week. It builds a clash-free weekly timetable
for 189 sessions, plans 41 midsem exams, decides all Spectra (Week 7) room requests by the published priority
rules, and repairs the timetable after faculty leave, room closures or new sessions while moving as few
sessions as possible. Requests can be typed in plain English or Hinglish, and every decision comes with its reason.

**Live app:** [web-hackathon-b7b1a.web.app](https://web-hackathon-b7b1a.web.app/) (target from the 31 Oct 2026 report; current deployment/API status is unverified)  ·  **Walkthrough:** [five-minute demo script](docs/DEMO_SCRIPT.md) (recording link pending)

## Results on the starter dataset (official `validator.py`)

| Check | Result |
|---|---|
| `schedule.json` hard violations | 0 |
| Soft penalty | about 20 after Generate (CPU dependent; **Improve** lowers it further) |
| Spectra booking score | 67 / 70: BR-09 cannot fit (1,200 people, largest venue 800) and BR-17's window ends past P13, so it can only be an alternative |
| Priority inversions | 0 |
| Exam soft penalty | 0 |
| `schedule_after.json` (sample disruption) | 0 hard violations; only the sessions the changes touch are moved, and room-only moves are preferred |

## Demo accounts

Password for all accounts: `<DEMO_PASSWORD you set>`

| Account | Role | Notes |
|---|---|---|
| `dean@cit.demo` | Admin | Office of the Dean of Academics |
| `spectra@cit.demo` | Admin | Spectra Core Team. Owns BR-01 and others, so the Dean must review those (no self-approval) |
| `coord.ece@cit.demo` | Department coordinator | ECE Department |
| `f12@cit.demo` | Faculty | Dr. Amit Behera (F12): can report unavailability and request an extra or moved session |
| `dramatics@cit.demo` | Club | Dramatics Society |

Admin → **Reset demo data** restores the starter pack. Then use **Generate** on the Overview.

## Architecture

```
Browser (HTML, CSS, vanilla JS)          Firebase Hosting serves public/ and forwards /api/** to Cloud Run
        │ fetch, JSON, cookie "__session", CSRF header
        ▼
PHP 8.3 on Apache (Cloud Run)            routing, sessions, roles, validation, audit, orchestration
   │                │                     │
   ▼                ▼                     ▼
MySQL 8          engine/cli.py          LLM + embeddings API (optional)
(versions,       Python 3 + OR-Tools     parse text, route intents, phrase RAG answers;
 bookings,       timetable, exams,       never decides validity, priority or approval
 proposals,      bookings, repair,
 RAG chunks,     explain; imports the
 sessions)       untouched validator.py
```

- **Engine.** A fast constructive search plus min-conflicts repair finds a valid timetable in about a second. CP-SAT (OR-Tools) then improves it within the remaining budget, and the better result by the validator's own soft score is kept. Hard rules match the validator exactly. Repair uses the same model with a cost of 1,000 per moved session, which is the validator's definition of a move.
- **Bookings.** Requests are decided in strict priority order (role-capped category, then `submitted_at`), so priority inversions cannot happen. Admin can change the four event-category priorities without resetting the dataset; the starter-pack discrepancy and scoring rationale are documented in [docs/STARTER_PACK_VERIFIED_DELTA.md](docs/STARTER_PACK_VERIFIED_DELTA.md). Only an approved institute event may displace a class, and the class is moved, never dropped.
- **Validator.** `validator/validator.py` is the official file, unmodified. Every generated version, export and approval is checked with it, and the reports are stored in `validation_runs`.
- **AI.** Plain text goes to an editable form with visible assumptions and follow-up questions. A built-in English/Hinglish parser works with no API key. The chat answers live questions from SQL with the user's permissions, and rules questions through RAG with cited sources.
- **RAG.** Sources are `knowledge/*.md`, the starter README and entity cards. Chunks are stored in MySQL (`kb_chunks`) and searched by cosine similarity when embeddings are configured, or MySQL FULLTEXT otherwise. Live facts never come from embeddings.

## Run it locally

**Docker (recommended):**
```bash
cp .env.example .env            # set DEMO_PASSWORD (8+ characters), optionally the LLM keys
docker compose up --build       # http://localhost:8080 . The first start builds the database
```

**Without Docker** (PHP 8.2+ with pdo_mysql and curl, MySQL 8, Python 3.9+):
```bash
python3 -m venv .venv && .venv/bin/pip install ortools==9.15.6755
cp .env.example .env            # DB_*, DEMO_PASSWORD, PYTHON_BIN=.venv/bin/python
php database/reset.php && php rag/ingest.php
php -S localhost:8000 -t public dev/router.php
```

## Exports

Admin → **Exports** downloads `schedule.json` and, after an approved repair, `schedule_after.json`. Both are built by
the app from the stored timetable. Commit them to `exports/` and check them with:
```bash
python validator/validator.py --data data/starter/cit_public.json --schedule exports/schedule.json
python validator/validator.py --data data/starter/cit_public.json --schedule exports/schedule_after.json \
       --disruption data/starter/examples/sample_disruption.json --baseline exports/schedule.json
```

## Tests

```bash
CIT_ALLOW_DESTRUCTIVE_TESTS=1 php database/tests/test_db.php      # schema, lossless import, constraints (wipes the DB)
.venv/bin/python -m unittest discover -s engine/tests              # engine against the official validator
python3 tests/api_smoke.py http://localhost:8000 <DEMO_PASSWORD> --reset   # 47 end-to-end checks over HTTP
```

## Deployment

Firebase Hosting serves the pages, Cloud Run runs this Docker image, and the database is Cloud SQL (MySQL 8) or any
managed MySQL 8. The full steps are in [docs/DEPLOY_FIREBASE.md](docs/DEPLOY_FIREBASE.md).

## Repository layout

```
public/      pages, CSS, browser JS (the only web root)
api/         PHP endpoints (one file per endpoint, mapped to /api)
includes/    PHP libraries: db, auth, Python bridge, stores, AI, RAG, chat
engine/      Python engine: timetable, greedy, bookings, exams, repair, explain, cli
validator/   official validator.py (untouched)
data/starter the starter pack (canonical input)
database/    schema.sql, reset, tests
knowledge/   RAG documents        prompts/  LLM prompts        rag/  ingest CLI
docker/      Apache, PHP, entrypoint      docs/  guides and contracts
```

## Known limitations

- Firebase Hosting ends requests to Cloud Run after 60 seconds, so Generate runs within about 35 seconds. Soft penalties depend on the CPU; **Improve** spends another pass from the current timetable and never makes it worse.
- An Admin's manual rejection of a booking hands the room to the next request. If that request has lower priority, the validator reports a priority inversion. The UI warns about this before rejecting.
- Without an LLM key, chat answers to rules questions quote the matching passages instead of summarising them, and intent detection uses keywords (English and common Hinglish).
- "Today" for "kal" is an Admin setting (default MON), because the dataset has weekdays but no calendar dates.
- Week-7 bookings cover MON–FRI, P1–P13, as in the dataset.

See `AI_USAGE.md` and `THIRD_PARTY.md` for AI and library disclosures.
