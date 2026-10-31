# Database (MySQL 8.0)

## Run it

```bash
cp .env.example .env        # fill DB_* and DEMO_PASSWORD
mysql -u root -e "CREATE DATABASE cit_scheduler CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
                  CREATE USER 'cit'@'%' IDENTIFIED BY '<pw>'; GRANT ALL ON cit_scheduler.* TO 'cit'@'%';"
php database/reset.php                                   # schema + starter dataset + demo users
CIT_ALLOW_DESTRUCTIVE_TESTS=1 php database/tests/test_db.php   # 67 checks, wipes the DB
```

## Three rules everyone codes against

1. **The engine reads the snapshot, not the tables.** `cit_current_dataset($db)['json']` is the exact
   dataset (after any approved disruptions). Pass it to Python as-is. The mirror tables exist for the
   UI, dashboard and chatbot SQL tools.
2. **Change the dataset only through `cit_create_dataset_version()`.** M3 applies a disruption with the
   validator's own `apply_disruption()`, then calls this function inside the approval transaction.
   It stores the new snapshot, marks it current and reloads the mirror.
3. **Exactly one active timetable.** `schedule_versions.status = 'active'` is unique at the database
   level. All `v_*` views read the active version. To activate a version, set the old one to
   `superseded` and the new one to `active` in a single transaction.

## Ownership

| Owner | Tables |
|---|---|
| M4 | roles, users, app_settings, dataset_imports, dataset_versions, audit_logs, ai_interactions, kb_chunks, approval_history |
| M1 | cal_days, cal_periods, exam_slots, rooms, room_unavailability, faculty, faculty_unavailability, student_groups, courses, sessions, session_groups, exams, exam_courses, exam_groups, schedule_versions, schedule_entries, exam_entries, exam_entry_rooms, validation_runs |
| M2 | priority_categories, role_category_caps, booking_requests, booking_request_windows, cancellations, booking_decisions, session_overrides |
| M3 | disruptions, disruption_changes, reschedule_proposals, schedule_changes, change_requests |

Schema changes: announce them in the team chat, edit `database/schema.sql` in your own section, then rerun the tests.

## Mapping to the export (schedule.json)

| Export field | Rows |
|---|---|
| `sessions[]` | `schedule_entries` (session_id, day, start_period, room_id) |
| `fest_week.bookings[]` | `booking_decisions` (request_id, status, day, start_period, end_period, room_id, reason) |
| `fest_week.session_overrides[]` | `session_overrides` |
| `exams[]` | `exam_entries` + `exam_entry_rooms` ordered by `ordinal` |

The file the app produced is saved as `schedule_versions.export_json`, so the export survives Cloud Run's
temporary disk. Each validator run is saved verbatim in `validation_runs.report_json`, along with the
validator file's sha256.

## Decisions worth knowing

- **No FK from history tables to `sessions`.** `remove_session` deletes a session that older versions
  still reference. FKs to rooms, faculty and groups are kept, because no change type removes those.
- **`booking_requests.role` and `requested_category` have no FK.** The validator accepts unknown values,
  so judge data must import without error.
- **Window checks are loose** (`to_period` up to 24). The real data contains BR-17 = P13–P14. Feasibility
  is the engine's job, not the schema's.
- **`submitted_at` is kept as the exact ISO string.** The validator breaks priority ties by comparing
  those strings. App-created requests must use the same `YYYY-MM-DDTHH:MM:SS+05:30` form.
  `submitted_at_utc` is only for sorting in SQL.
- **`requested_room` has no FK.** BR-14 asks for `LHC-5`, which doesn't exist.
- **The database also enforces some rules:** one room per exam slot, a reason on every rejection and
  alternative, no room on a rejection, no teaching past P9, no Saturday classes, and only the five
  disruption change types.
- **`accessible` is a reserved word in MySQL,** so write `` r.`accessible` `` in SQL.
