# Implementation guide for the four members

The code comes as six zips. Each one unzips into the **same repository root** and adds its own folders, and no two
module zips contain the same file. Install them in this order:

| Zip | Owner | Adds |
|---|---|---|
| `00-foundation.zip` | M4 sets it up, everyone pulls it | database, shared PHP (`config`, `db`, `bootstrap`, `python` bridge, `audit`, import/reset/seed), engine core (`core.py`, `cli.py`, `export.py`), the official validator, starter data, auth and meta endpoints, login page, shared CSS/JS, Docker, Firebase config, docs |
| `01-m1-timetable.zip` | M1 | `engine/timetable.py`, `greedy.py`, `explain.py`, `includes/schedule_store.php`, `api/schedule/*`, timetable page |
| `02-m2-bookings-exams.zip` | M2 | `engine/bookings.py`, `exams.py`, `includes/booking_store.php`, `api/bookings/*`, `api/exams/*`, bookings, new-request and exams pages |
| `03-m3-disruptions.zip` | M3 | `engine/repair.py`, `includes/disruption_store.php`, `change_parser.php`, `api/disruptions/*`, `api/changes/*`, reschedule page, `prompts/parse_change.txt` |
| `04-m4-ai-dashboard-admin.zip` | M4 | `includes/ai.php`, `booking_parser.php`, `rag.php`, `chat.php`, `api/ai/*`, `api/dashboard/*`, `api/admin/*`, `rag/`, `knowledge/`, prompts, dashboard, assistant and admin pages, `AI_USAGE.md`, `THIRD_PARTY.md` |
| `05-tests.zip` | everyone | `engine/tests/test_engine.py`, `tests/api_smoke.py` (both need every module) |

`cit-campus-scheduler-full.zip` holds everything at once, for anyone who just wants to run it.

**Hard dependencies between modules** (so the order matters):
- **Generate needs M1 + M2.** `engine/cli.py generate_all` imports `bookings.py` and `exams.py`.
- **M3's repair needs M1.** It reuses `timetable.py` and `greedy.py`.
- **M3's change parser needs M4's `booking_parser.php`.** M3 can test uploads of change files without it.
- **The dashboard and admin pages need M1's `schedule_store.php`.**
- **The new-request page calls M4's `/api/ai/parse_booking.php`.** Without it the manual form still works.

---

## Step 0: Everyone, in the first hour

1. **M4:** create the GitHub repo after the hackathon starts. Unzip `00-foundation.zip` into it and commit:
   `git add -A && git commit -m "Foundation"`, then push.
2. **Everyone:** clone the repo. Then:
   ```bash
   cp .env.example .env               # set DEMO_PASSWORD (8+ chars). With Docker, leave DB_* as they are
   docker compose up --build          # app on http://localhost:8080, MySQL on localhost:3307
   ```
   Without Docker: install MySQL 8, PHP 8.2+ (pdo_mysql, curl, mbstring) and Python 3.9+, then:
   ```bash
   python3 -m venv .venv && .venv/bin/pip install ortools==9.15.6755
   # in .env: DB_HOST/DB_USER/DB_PASSWORD for your MySQL, PYTHON_BIN=.venv/bin/python
   php database/reset.php
   php -S localhost:8000 -t public dev/router.php
   ```
3. **Check:** `curl localhost:8080/api/health.php` should show `database: ok` and an `ortools` version. Logging in as `dean@cit.demo` should work.
4. **Each member:** create your branch (`feature/timetable-exams`, `feature/venue-bookings`, `feature/disruption-reschedule`, `feature/ai-dashboard-roles`), unzip **your** module zip into it, and work there.

**Shared rules**
- **Contracts.** Endpoints return `{"ok": true, "data": ...}` or `{"ok": false, "error": {"code", "message", ...}}`.
- **CSRF.** Every POST sends the `X-CSRF-Token` header (`CIT.api()` in `public/assets/js/app.js` does this).
- **Engine calls.** PHP talks to Python only through `cit_engine([...])` in `includes/python.php`: JSON on stdin and stdout, a fixed script and no shell.
- **Validation.** The official `validator/validator.py` is the judge. Never edit it; the engine imports it.
- **Schema.** `database/schema.sql` has one section per member. Announce schema changes in the team chat, then run `CIT_ALLOW_DESTRUCTIVE_TESTS=1 php database/tests/test_db.php`.

---

## M1: Timetable and the shared engine (`01-m1-timetable.zip`)

**Files:**
- `engine/timetable.py` (CP-SAT model and `best_timetable`)
- `engine/greedy.py` (fast valid start, also the no-OR-Tools fallback)
- `engine/explain.py`
- `includes/schedule_store.php`
- `api/schedule/{generate,get,versions,export,validate,explain}.php`
- `public/timetable.html`, `public/assets/js/pages/timetable.js`

**Tables (M1 section of `schema.sql`):** `schedule_versions`, `schedule_entries`, `exam_entries`, `exam_entry_rooms`, `validation_runs`, and the reference mirror (`rooms`, `sessions`…).

**Endpoints**

| Endpoint | Who | In | Out |
|---|---|---|---|
| `POST /api/schedule/generate.php` | Admin | `{improve?: bool}` | `{version_id, activated, hard_violations, soft_total, soft_penalties, bookings, seconds}` |
| `GET /api/schedule/get.php?view=group\|faculty\|room&id=..&week=regular\|fest` | any | | `{version, entries[], events[], closures[], unavailable[]}` |
| `GET /api/schedule/versions.php` / `POST {version_id}` | any / Admin | | list / activate (only with 0 hard violations) |
| `GET /api/schedule/export.php?kind=schedule\|after&version=` | any | | downloads the JSON file |
| `POST /api/schedule/export.php` | Admin | `{kind: schedule\|after, version?}` | saves the export in `exports/` (CSRF protected) |
| `POST /api/admin/settings.php` | Admin | `{priority_order: [bookable categories...]}` | creates a dataset version, recalculates active bookings, and marks pending repairs stale |
| `GET\|POST /api/schedule/validate.php` | any / Admin | | latest or new validator report |
| `GET /api/schedule/explain.php?session_id=\|request_id=` | any | | `{text, grid[], rooms_that_fit}` |

**Steps**
1. Unzip, then test the engine alone. This needs `bookings.py` and `exams.py` from M2 for `generate_all`, so use the `timetable` command:
   ```bash
   python3 -c "import json;print(json.dumps({'command':'timetable','dataset':json.load(open('data/starter/cit_public.json'))}))" \
     | .venv/bin/python engine/cli.py | head -c 300
   ```
   Expect `"status": "ok"` within about 26 seconds.
2. Read `engine/timetable.py` top to bottom. Every hard rule H1–H12/H14 and the soft penalties S1–S6 are labelled, and you must be able to explain them in the demo.
3. When M2's zip is merged, log in as Admin and click **Generate** on the Overview. Then open Timetable and check CSE-2, F07 and room AUD (Spectra week).
4. Run the validator on the export (Admin → Exports): 0 hard violations.
5. **Tuning (only after everything works):** time budgets are in `engine/cli.py` (`time_limit` 24 s, exams 6 s). Keep generate under 45 s in total because of Firebase's 60-second cut.

**Test:** `python -m unittest discover -s engine/tests` (after `05-tests.zip`).

---

## M2: Spectra bookings and exams (`02-m2-bookings-exams.zip`)

**Files:**
- `engine/bookings.py` (priority-ordered decisions, displacement, alternatives, reasons)
- `engine/exams.py` (CP-SAT plus greedy fallback)
- `includes/booking_store.php`
- `api/bookings/{list,get,create,cancel,review,availability}.php`, `api/exams/get.php`
- `bookings.html`, `request.html`, `exams.html` and their JS

**Tables:** `booking_requests`, `booking_request_windows`, `cancellations`, `booking_decisions`, `session_overrides`, `priority_categories`, `role_category_caps`.

**Endpoints**

| Endpoint | Who | In | Out |
|---|---|---|---|
| `GET /api/bookings/list.php?status=&q=` | any (own) / Admin (all) | | requests with decision |
| `GET /api/bookings/get.php?id=` | owner / Admin | | request, windows, decision, history |
| `POST /api/bookings/create.php` | any | `{title, attendance, duration_periods, requested_category, windows[{day,from_period,to_period}], room_types[], needs[], requested_room?, mandatory_for_groups[], repeat?, raw_text?}` | `{request_ids[], decisions[]}` |
| `POST /api/bookings/cancel.php` | owner / Admin | `{id, note?}` | request |
| `POST /api/bookings/review.php` | Admin or matching department coordinator, never on own request | `{id, action: confirm\|reject, reason?}` | request |
| `GET /api/changes/sessions.php` | Department coordinator | | currently scheduled sessions in their department |
| `POST /api/changes/request.php` | Faculty / department coordinator | Faculty `{text, preview?: bool, changes?: [...]}` or `{action: extra\|move, session_id, reason, day?, period?}`; coordinator `{session_id, reason}` | pending repair proposal for Admin review |
| `GET /api/bookings/availability.php?day&from&to&attendance&types&needs` | any | | rooms with fits / free / taken_by |
| `GET /api/exams/get.php?group=` | any | | exams with rooms and seats |

**Rules implemented (check each in the demo):**
- **Role caps.** The effective category comes from the validator's own `effective_category()`.
- **Order and ties.** Requests are processed by rank, then `submitted_at`, so a request is only ever beaten by one that outranks it (0 inversions).
- **Cancellations.** A cancelled request is rejected with the cancellation note.
- **Fit inside a window.** The full span must fit inside one window. `end_period` is inclusive.
- **Room checks.** Type, capacity, needs and accessibility; the room must be free of classes and bookings and not closed; mandatory groups must be free.
- **Displacement.** Only institute events displace classes, and the class is moved validly (`session_overrides`).
- **Alternatives.** Otherwise the nearest free slot is offered as an alternative (half score), or the request is rejected with the reason.

**Steps**
1. Unzip, Admin → **Generate**, then open Spectra bookings. Expect 22 approved, 1 alternative (BR-17) and 2 rejected (BR-04 cancelled, BR-09 1,200 people).
2. Log in as `dramatics@cit.demo` and use New request, both the manual form and "Fill the form". The decision appears instantly.
3. As `spectra@cit.demo`, try to confirm BR-01: it is blocked as self-approval. As `dean@cit.demo`, confirm it.
4. Check the Exams page: 41 exams, rooms at half capacity, no group with two exams in one session.

---

## M3: Disruptions and minimum-movement repair (`03-m3-disruptions.zip`)

**Files:**
- `engine/repair.py`
- `includes/disruption_store.php`, `includes/change_parser.php`
- `api/disruptions/{upload,list,proposal,decide}.php`, `api/changes/request.php`
- `reschedule.html` and its JS, `public/samples/sample_disruption.json`, `prompts/parse_change.txt`

**Tables:** `disruptions`, `disruption_changes`, `reschedule_proposals`, `schedule_changes`, `change_requests`.

**Endpoints**

| Endpoint | Who | In | Out |
|---|---|---|---|
| `POST /api/disruptions/upload.php` | Admin | `{disruption: {...}}` or `{text: "<json>"}` | `{proposal_id, status: pending\|infeasible, summary\|reasons}` |
| `GET /api/disruptions/list.php` | any | | proposals |
| `GET /api/disruptions/proposal.php?id=` | any | | proposal, change file, diff with reasons, validation |
| `POST /api/disruptions/decide.php` | Admin | `{id, action: approve\|reject, comment?}` | proposal |
| `POST /api/changes/request.php` | Faculty (own ID only) | `{text, preview?: true}` or `{text, changes[]}` | parsed changes, or a proposal |

**How it works**
1. The change file is checked: only the five types, and every ID must exist. Bad files get a 422 with one reason per change.
2. `apply_disruption()` from the **official validator** builds the new data.
3. The same CP-SAT model runs with a cost of 1,000 per moved session, which is exactly the validator's `sessions_moved`. A room-only move is preferred over a time move.
4. The proposal is stored as a draft version with a diff. **Nothing changes until approval.**
5. On approval, the proposal is checked again with the validator (`--disruption --baseline` semantics). The new dataset version becomes current, the repaired timetable becomes active, exams carry over and Spectra decisions are re-made.
6. If no valid repair exists, the proposal says so and names the rule and the entities involved.

**Steps**
1. Unzip, Generate, then Reschedule → **Load the sample** → **Propose a repair**. Expect 0 hard violations and only the sessions the changes touch moved.
2. **Approve and apply**, then download `schedule_after.json` and run the validator with `--disruption` and `--baseline`.
3. Make your own change files to rehearse for judging: a room closure on another day, `remove_session`, `add_faculty` + `add_session` with a `fixed_slot`, and an impossible one (a faculty member unavailable all week) to show the infeasible report.
4. As `f12@cit.demo`, write "I can't teach on Wednesday morning", then Check, then Send. As Admin, approve or reject it.

---

## M4: Platform, AI, RAG, dashboard, admin (`04-m4-ai-dashboard-admin.zip`, plus the foundation)

**Files:**
- `includes/ai.php` (LLM and embeddings client), `booking_parser.php` (English/Hinglish rules, plus LLM merge and room checks), `rag.php`, `chat.php`
- `api/ai/{parse_booking,chat}.php`, `api/dashboard/summary.php`, `api/admin/{reset,import,settings,audit}.php`
- `rag/ingest.php`, `knowledge/*.md`, `prompts/*`
- `dashboard.html`, `assistant.html`, `admin.html` and their JS

**Tables:** `users`, `roles`, `app_settings`, `audit_logs`, `ai_interactions`, `kb_chunks`, `dataset_imports`, `dataset_versions`, `approval_history`, `php_sessions`.

**Steps**
1. Unzip, then run `php rag/ingest.php` (or Admin → Rebuild knowledge index). Expect about 170 passages.
2. **Without any API key,** try these in New request and the Assistant (see the examples on the page):
   - "seminar hall kal shaam 6 baje, 2 ghante, 100 log, mic chahiye"
   - "Is LH-1 free on Tuesday at 10 am?"
   - "CSE-2 ka timetable batao"
   - "What is the booking priority order?"
3. **Optional LLM:** set `LLM_PROVIDER`, `LLM_BASE_URL`, `LLM_MODEL`, `LLM_API_KEY` (and `EMBED_MODEL` for vector RAG) in `.env`, then rebuild the knowledge index. The parser result then says "Read by the AI parser". If the provider fails, it falls back by itself.
4. Deployment: follow `docs/DEPLOY_FIREBASE.md`. Do it **early** (by hour 4–6), then redeploy as modules merge.
5. Fill in the README: the live URL, the demo password and the video link.

---

## Integration order and checkpoints

| When | Do | Check |
|---|---|---|
| Hour 1 | Foundation merged to `main`; everyone runs it locally | `/api/health.php` ok, login works |
| Hour 2–4 | M1 + M2 merged (Generate needs both) | Generate gives 0 hard violations; bookings 67/70 |
| Hour 4–6 | **First deployment** to Cloud Run + Firebase | Same checks on the live URL, Generate under 60 s |
| Hour 6–10 | M3 merged | Sample disruption approved; `schedule_after.json` has 0 hard violations |
| Hour 10–14 | M4 merged | Assistant and parser work with and without an LLM key |
| Hour 14–19 | `05-tests.zip`: all three test suites green; everyone rehearses their part | `api_smoke.py` against the live URL: 47 passed |
| Hour 19 | **Feature freeze** | |
| Hour 19–23 | Exports from the deployed app, README, video (see `DEMO_SCRIPT.md`) | Validator on both exports |
| Before the deadline | Final commit and push; reset the live app | Nothing pushed after the deadline |

Run the end-to-end suite against any server:
`python3 tests/api_smoke.py https://<your-app> <DEMO_PASSWORD> --reset`
