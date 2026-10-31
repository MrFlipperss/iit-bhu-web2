-- =====================================================================
-- CIT Campus Scheduling & Venue Management  |  database/schema.sql
-- Target: MySQL 8.0.16+ (CHECK constraints enforced), utf8mb4.
--
-- Running this file DROPS and recreates every table (it is the reset).
-- Load it through:  php database/reset.php
--
-- Data rules
--  * data/starter/cit_public.json is canonical. Each dataset version is
--    stored byte-for-byte in dataset_versions.snapshot_json, and the
--    Python engine reads that snapshot, never these tables.
--  * The "reference mirror" tables (rooms ... exam_groups) are a queryable
--    copy of the CURRENT dataset version, for the UI, dashboard and the
--    chatbot's live SQL tools. includes/dataset_import.php reloads them.
--  * IDs are the starter-pack IDs, unchanged (VARCHAR primary keys).
--  * Long-lived tables (schedule_entries, booking_decisions ...) do NOT
--    hold a foreign key to sessions, because remove_session can delete a
--    session that older versions still reference. They DO hold foreign
--    keys to rooms, faculty and groups, which no change type removes.
--  * Every timestamp column is UTC (db.php sets time_zone = '+00:00').
--
-- Owners: [M4] platform  [M1] timetable/exams  [M2] bookings  [M3] disruptions
-- Statements end with ";" at the end of a line. reset.php relies on that.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS v_pending_approvals;
DROP VIEW IF EXISTS v_room_utilization;
DROP VIEW IF EXISTS v_faculty_unavailable_slots;
DROP VIEW IF EXISTS v_room_unavailable_slots;
DROP VIEW IF EXISTS v_booking_slots;
DROP VIEW IF EXISTS v_group_slots;
DROP VIEW IF EXISTS v_session_slots;
DROP VIEW IF EXISTS v_active_schedule_version;

DROP TABLE IF EXISTS change_requests;
DROP TABLE IF EXISTS schedule_changes;
DROP TABLE IF EXISTS reschedule_proposals;
DROP TABLE IF EXISTS disruption_changes;
DROP TABLE IF EXISTS disruptions;
DROP TABLE IF EXISTS session_overrides;
DROP TABLE IF EXISTS booking_decisions;
DROP TABLE IF EXISTS cancellations;
DROP TABLE IF EXISTS booking_request_windows;
DROP TABLE IF EXISTS booking_requests;
DROP TABLE IF EXISTS role_category_caps;
DROP TABLE IF EXISTS priority_categories;
DROP TABLE IF EXISTS validation_runs;
DROP TABLE IF EXISTS exam_entry_rooms;
DROP TABLE IF EXISTS exam_entries;
DROP TABLE IF EXISTS schedule_entries;
DROP TABLE IF EXISTS schedule_versions;
DROP TABLE IF EXISTS exam_groups;
DROP TABLE IF EXISTS exam_courses;
DROP TABLE IF EXISTS exams;
DROP TABLE IF EXISTS session_groups;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS courses;
DROP TABLE IF EXISTS student_groups;
DROP TABLE IF EXISTS faculty_unavailability;
DROP TABLE IF EXISTS faculty;
DROP TABLE IF EXISTS room_unavailability;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS exam_slots;
DROP TABLE IF EXISTS cal_periods;
DROP TABLE IF EXISTS cal_days;
DROP TABLE IF EXISTS php_sessions;
DROP TABLE IF EXISTS approval_history;
DROP TABLE IF EXISTS kb_chunks;
DROP TABLE IF EXISTS ai_interactions;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS dataset_versions;
DROP TABLE IF EXISTS dataset_imports;
DROP TABLE IF EXISTS app_settings;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- [M4] PLATFORM: roles, users, settings, datasets, audit, AI, RAG
-- =====================================================================

-- Role codes are the dataset's booking_requests.role values.
CREATE TABLE roles (
  code          VARCHAR(32)  NOT NULL PRIMARY KEY,
  label         VARCHAR(64)  NOT NULL,
  can_approve   TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order    TINYINT      NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO roles (code, label, can_approve, sort_order) VALUES
  ('admin',                  'Admin (Dean''s office)',  1, 1),
  ('department_coordinator', 'Department coordinator',  0, 2),
  ('faculty',                'Faculty',                 0, 3),
  ('club',                   'Club representative',     0, 4);

CREATE TABLE users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(120) NOT NULL,
  display_name    VARCHAR(120) NOT NULL,
  role            VARCHAR(32)  NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,              -- password_hash(), never plain text
  -- Links a user to dataset identities, for permissions and the no-self-approval rule.
  requester_name  VARCHAR(120) NULL,                  -- matches booking_requests.requester
  faculty_id      VARCHAR(16)  NULL,                  -- matches faculty.id (role = faculty)
  department      VARCHAR(16)  NULL,                  -- matches *.department (coordinators)
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at   DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  KEY ix_users_role (role),
  CONSTRAINT fk_users_role FOREIGN KEY (role) REFERENCES roles (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Small key/value store: team name, the "today" weekday used to resolve "kal", feature flags.
CREATE TABLE app_settings (
  k           VARCHAR(64) NOT NULL PRIMARY KEY,
  v           JSON        NOT NULL,
  updated_by  INT UNSIGNED NULL,
  updated_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per uploaded dataset file (admin import / reset).
CREATE TABLE dataset_imports (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dataset_id    VARCHAR(64)  NOT NULL,                -- e.g. cit-public-v1
  source_name   VARCHAR(255) NOT NULL,
  sha256        CHAR(64)     NOT NULL,
  byte_size     INT UNSIGNED NOT NULL,
  imported_by   INT UNSIGNED NULL,
  imported_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_imports_user FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- The effective dataset over time: v1 = the import; later versions = after an approved
-- disruption, or an admin priority edit. Exactly one row is current.
CREATE TABLE dataset_versions (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  import_id       INT UNSIGNED NOT NULL,
  parent_id       INT UNSIGNED NULL,
  dataset_id      VARCHAR(64)  NOT NULL,
  created_reason  ENUM('import','disruption','priority_edit') NOT NULL,
  snapshot_json   LONGTEXT     NOT NULL,              -- exact bytes (LONGTEXT keeps key order; JSON type would not)
  sha256          CHAR(64)     NOT NULL,
  is_current      TINYINT(1)   NOT NULL DEFAULT 0,
  current_marker  TINYINT GENERATED ALWAYS AS (IF(is_current = 1, 1, NULL)) STORED,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dataset_versions_current (current_marker),   -- at most one current version
  CONSTRAINT ck_dataset_snapshot_json CHECK (JSON_VALID(snapshot_json)),
  CONSTRAINT fk_dv_import FOREIGN KEY (import_id) REFERENCES dataset_imports (id) ON DELETE CASCADE,
  CONSTRAINT fk_dv_parent FOREIGN KEY (parent_id) REFERENCES dataset_versions (id) ON DELETE SET NULL,
  CONSTRAINT fk_dv_user   FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE audit_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(64)  NOT NULL,                 -- e.g. login, booking.submit, schedule.generate
  entity_type  VARCHAR(48)  NULL,
  entity_id    VARCHAR(64)  NULL,
  details      JSON         NULL,
  ip           VARCHAR(45)  NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_audit_entity (entity_type, entity_id),
  KEY ix_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Every LLM / embedding call, for AI_USAGE.md evidence and debugging.
CREATE TABLE ai_interactions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NULL,
  feature        ENUM('parse_booking','parse_change','route_intent','chat','rag_answer','explain','embed') NOT NULL,
  provider       VARCHAR(32)  NULL,
  model          VARCHAR(64)  NULL,
  input_text     MEDIUMTEXT   NULL,
  output_json    JSON         NULL,
  status         ENUM('ok','error','fallback','rejected_schema') NOT NULL,
  error_message  VARCHAR(500) NULL,
  latency_ms     INT UNSIGNED NULL,
  tokens_in      INT UNSIGNED NULL,
  tokens_out     INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ai_user (user_id, created_at),
  CONSTRAINT fk_ai_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- RAG store: static knowledge only (rules, policies, README, entity cards).
-- Live state (availability, bookings, timetable) is always queried from the tables.
CREATE TABLE kb_chunks (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_type     ENUM('doc','entity') NOT NULL,
  source_ref      VARCHAR(160) NOT NULL,              -- e.g. knowledge/policies.md, room:SH-1
  section         VARCHAR(200) NULL,
  visibility      ENUM('all','staff','admin') NOT NULL DEFAULT 'all',
  content         TEXT         NOT NULL,
  content_sha256  CHAR(64)     NOT NULL,
  token_estimate  SMALLINT UNSIGNED NULL,
  embedding       LONGBLOB     NULL,                  -- packed little-endian float32; NULL until embedded
  embedding_dim   SMALLINT UNSIGNED NULL,
  embedding_model VARCHAR(64)  NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kb_chunk (source_ref, content_sha256),
  KEY ix_kb_visibility (visibility),
  FULLTEXT KEY ft_kb_content (section, content)       -- keyword fallback when the embedding API is down
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- PHP sessions in MySQL, so every Cloud Run instance sees the same logins.
CREATE TABLE php_sessions (
  id          VARCHAR(128) NOT NULL PRIMARY KEY,
  data        MEDIUMBLOB   NOT NULL,
  updated_at  INT UNSIGNED NOT NULL,
  KEY ix_sessions_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One approval trail for every governed object (bookings, reschedules, change requests).
CREATE TABLE approval_history (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type   ENUM('booking_request','booking_decision','reschedule_proposal','change_request','priority_rules') NOT NULL,
  entity_id     VARCHAR(64)  NOT NULL,
  action        ENUM('submitted','proposed','approved','rejected','confirmed','overridden','cancelled','applied') NOT NULL,
  actor_user_id INT UNSIGNED NULL,                    -- NULL = the deterministic engine
  comment       VARCHAR(1000) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_approval_entity (entity_type, entity_id),
  CONSTRAINT fk_approval_user FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- =====================================================================
-- [M1] CALENDAR + REFERENCE MIRROR (reloaded from the current dataset)
-- =====================================================================

CREATE TABLE cal_days (
  code         ENUM('MON','TUE','WED','THU','FRI','SAT') NOT NULL PRIMARY KEY,
  ordinal      TINYINT    NOT NULL,
  teaching_day TINYINT(1) NOT NULL,                   -- from calendar.days
  exam_day     TINYINT(1) NOT NULL                    -- from calendar.exam_week.days
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE cal_periods (
  id              TINYINT    NOT NULL PRIMARY KEY,
  start_time      TIME       NOT NULL,
  end_time        TIME       NOT NULL,
  is_teaching     TINYINT(1) NOT NULL,
  is_event_only   TINYINT(1) NOT NULL,
  is_lab_start    TINYINT(1) NOT NULL,
  lunch_after     TINYINT(1) NOT NULL,                -- 1 for the period that lunch follows (P5)
  CONSTRAINT ck_period_id CHECK (id BETWEEN 1 AND 13)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE exam_slots (
  code        ENUM('AM','PM') NOT NULL PRIMARY KEY,
  start_time  TIME NOT NULL,
  end_time    TIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rooms (
  id            VARCHAR(16)  NOT NULL PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  building      VARCHAR(120) NOT NULL,
  type          VARCHAR(32)  NOT NULL,                -- classroom | lecture_hall | ... (kept open for judge data)
  capacity      SMALLINT UNSIGNED NOT NULL,
  features      JSON         NOT NULL,                -- ["projector","pa_system",...]
  `accessible`  TINYINT(1)   NOT NULL,                -- backticked: ACCESSIBLE is a reserved word
  allowed_uses  JSON         NOT NULL,                -- ["lecture","tutorial","lab","exam","event"]
  ordinal       SMALLINT     NOT NULL,                -- position in the dataset (lossless export)
  KEY ix_rooms_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Same shape as dataset room_unavailability entries (and disruption room_unavailable, one row per day).
CREATE TABLE room_unavailability (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  room_id   VARCHAR(16)  NOT NULL,
  day       ENUM('MON','TUE','WED','THU','FRI','SAT') NOT NULL,
  periods   JSON         NOT NULL,                    -- [1,2,3]
  reason    VARCHAR(255) NOT NULL DEFAULT '',
  ordinal   SMALLINT     NOT NULL,
  KEY ix_room_unav (room_id, day),
  CONSTRAINT fk_room_unav_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE faculty (
  id                 VARCHAR(16)  NOT NULL PRIMARY KEY,
  name               VARCHAR(120) NOT NULL,
  department         VARCHAR(16)  NOT NULL,
  max_hours_per_day  TINYINT UNSIGNED NOT NULL,
  needs_accessible   TINYINT(1)   NOT NULL,
  preferences        JSON         NOT NULL,           -- soft: [{"type":"avoid","days":[..],"periods":[..],"note":".."}]
  ordinal            SMALLINT     NOT NULL,
  KEY ix_faculty_dept (department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- faculty[].unavailable entries (hard), one row per {day, periods, reason}.
CREATE TABLE faculty_unavailability (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  faculty_id  VARCHAR(16)  NOT NULL,
  day         ENUM('MON','TUE','WED','THU','FRI','SAT') NOT NULL,
  periods     JSON         NOT NULL,
  reason      VARCHAR(255) NOT NULL DEFAULT '',
  ordinal     SMALLINT     NOT NULL,                  -- position inside that faculty's list
  KEY ix_fac_unav (faculty_id, day),
  CONSTRAINT fk_fac_unav_fac FOREIGN KEY (faculty_id) REFERENCES faculty (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE student_groups (
  id                VARCHAR(16)  NOT NULL PRIMARY KEY,
  name              VARCHAR(120) NOT NULL,
  year              TINYINT UNSIGNED NOT NULL,
  department        VARCHAR(16)  NOT NULL,
  size              SMALLINT UNSIGNED NOT NULL,
  needs_accessible  TINYINT(1)   NOT NULL,
  ordinal           SMALLINT     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE courses (
  id              VARCHAR(16)  NOT NULL PRIMARY KEY,
  title           VARCHAR(160) NOT NULL,
  department      VARCHAR(16)  NOT NULL,
  year            TINYINT UNSIGNED NOT NULL,
  lecture_hours   TINYINT UNSIGNED NOT NULL,
  tutorial_hours  TINYINT UNSIGNED NOT NULL,
  lab_blocks      TINYINT UNSIGNED NOT NULL,
  ordinal         SMALLINT     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- The thing that gets scheduled.
CREATE TABLE sessions (
  id                  VARCHAR(32)  NOT NULL PRIMARY KEY,
  course_id           VARCHAR(16)  NOT NULL,
  kind                ENUM('lecture','tutorial','lab') NOT NULL,
  faculty_id          VARCHAR(16)  NOT NULL,
  size                SMALLINT UNSIGNED NOT NULL,
  duration            TINYINT UNSIGNED NOT NULL,
  room_types          JSON         NOT NULL,
  needs_accessible    TINYINT(1)   NOT NULL,
  basket_slot         VARCHAR(16)  NULL,              -- e.g. OEB-1
  fixed_day           ENUM('MON','TUE','WED','THU','FRI') NULL,
  fixed_start_period  TINYINT      NULL,
  ordinal             SMALLINT     NOT NULL,
  KEY ix_sessions_faculty (faculty_id),
  KEY ix_sessions_course (course_id),
  KEY ix_sessions_basket (basket_slot),
  CONSTRAINT ck_sessions_duration CHECK (duration BETWEEN 1 AND 9),
  CONSTRAINT ck_sessions_fixed CHECK ((fixed_day IS NULL) = (fixed_start_period IS NULL)),
  CONSTRAINT fk_sessions_course  FOREIGN KEY (course_id)  REFERENCES courses (id),
  CONSTRAINT fk_sessions_faculty FOREIGN KEY (faculty_id) REFERENCES faculty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- sessions[].groups. The timetable-by-group view uses this table.
CREATE TABLE session_groups (
  session_id  VARCHAR(32) NOT NULL,
  group_id    VARCHAR(16) NOT NULL,
  ordinal     TINYINT     NOT NULL,
  PRIMARY KEY (session_id, group_id),
  KEY ix_session_groups_group (group_id),
  CONSTRAINT fk_sg_session FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE,
  CONSTRAINT fk_sg_group   FOREIGN KEY (group_id)   REFERENCES student_groups (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE exams (
  id              VARCHAR(32) NOT NULL PRIMARY KEY,
  size            SMALLINT UNSIGNED NOT NULL,
  duration_hours  TINYINT UNSIGNED NOT NULL,
  ordinal         SMALLINT    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE exam_courses (
  exam_id    VARCHAR(32) NOT NULL,
  course_id  VARCHAR(16) NOT NULL,
  ordinal    TINYINT     NOT NULL,
  PRIMARY KEY (exam_id, course_id),
  CONSTRAINT fk_ec_exam   FOREIGN KEY (exam_id)   REFERENCES exams (id) ON DELETE CASCADE,
  CONSTRAINT fk_ec_course FOREIGN KEY (course_id) REFERENCES courses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE exam_groups (
  exam_id   VARCHAR(32) NOT NULL,
  group_id  VARCHAR(16) NOT NULL,
  ordinal   TINYINT     NOT NULL,
  PRIMARY KEY (exam_id, group_id),
  KEY ix_eg_group (group_id),
  CONSTRAINT fk_eg_exam  FOREIGN KEY (exam_id)  REFERENCES exams (id) ON DELETE CASCADE,
  CONSTRAINT fk_eg_group FOREIGN KEY (group_id) REFERENCES student_groups (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- =====================================================================
-- [M1] SCHEDULE VERSIONS, ENTRIES, EXAMS, VALIDATION
-- =====================================================================

-- Each generated / repaired / manual timetable. Exactly one is 'active' (the live timetable).
-- A version holds the regular week (schedule_entries), the exam plan (exam_entries) and the
-- Spectra-week decisions built on it (booking_decisions, session_overrides).
CREATE TABLE schedule_versions (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dataset_version_id   INT UNSIGNED NOT NULL,
  parent_id            INT UNSIGNED NULL,             -- baseline this version was derived from
  kind                 ENUM('generated','repair','manual') NOT NULL,
  status               ENUM('draft','validated','active','superseded','rejected','failed') NOT NULL DEFAULT 'draft',
  team_name            VARCHAR(120) NOT NULL,
  solver               VARCHAR(64)  NULL,             -- e.g. ortools-cpsat-9.x / greedy-v1
  solver_params        JSON         NULL,
  solve_seconds        DECIMAL(8,2) NULL,
  hard_violation_count INT UNSIGNED NULL,             -- from the latest validation run
  soft_total           INT UNSIGNED NULL,
  booking_score        DECIMAL(6,1) NULL,
  export_json          LONGTEXT     NULL,             -- exact schedule.json produced by the app
  export_sha256        CHAR(64)     NULL,
  note                 VARCHAR(500) NULL,
  created_by           INT UNSIGNED NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_by         INT UNSIGNED NULL,
  activated_at         DATETIME     NULL,
  active_marker        TINYINT GENERATED ALWAYS AS (IF(status = 'active', 1, NULL)) STORED,
  UNIQUE KEY uq_schedule_active (active_marker),      -- at most one active timetable
  KEY ix_sv_status (status),
  CONSTRAINT ck_sv_export_json CHECK (export_json IS NULL OR JSON_VALID(export_json)),
  CONSTRAINT fk_sv_dataset   FOREIGN KEY (dataset_version_id) REFERENCES dataset_versions (id),
  CONSTRAINT fk_sv_parent    FOREIGN KEY (parent_id) REFERENCES schedule_versions (id) ON DELETE SET NULL,
  CONSTRAINT fk_sv_created   FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_sv_activated FOREIGN KEY (activated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Exactly the export fields (session_id, day, start_period, room_id) plus duration,
-- copied so the occupancy views still work after a session has been removed.
CREATE TABLE schedule_entries (
  schedule_version_id  INT UNSIGNED NOT NULL,
  session_id           VARCHAR(32)  NOT NULL,         -- no FK on purpose (see header)
  day                  ENUM('MON','TUE','WED','THU','FRI') NOT NULL,
  start_period         TINYINT      NOT NULL,
  room_id              VARCHAR(16)  NOT NULL,
  duration             TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (schedule_version_id, session_id),
  KEY ix_se_room (schedule_version_id, room_id, day),
  KEY ix_se_time (schedule_version_id, day, start_period),
  CONSTRAINT ck_se_period CHECK (start_period BETWEEN 1 AND 9 AND start_period + duration - 1 <= 9),
  CONSTRAINT fk_se_version FOREIGN KEY (schedule_version_id) REFERENCES schedule_versions (id) ON DELETE CASCADE,
  CONSTRAINT fk_se_room    FOREIGN KEY (room_id) REFERENCES rooms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE exam_entries (
  schedule_version_id  INT UNSIGNED NOT NULL,
  exam_id              VARCHAR(32)  NOT NULL,
  day                  ENUM('MON','TUE','WED','THU','FRI','SAT') NOT NULL,
  session              ENUM('AM','PM') NOT NULL,
  PRIMARY KEY (schedule_version_id, exam_id),
  KEY ix_ee_slot (schedule_version_id, day, session),
  CONSTRAINT fk_ee_version FOREIGN KEY (schedule_version_id) REFERENCES schedule_versions (id) ON DELETE CASCADE,
  CONSTRAINT fk_ee_exam    FOREIGN KEY (exam_id) REFERENCES exams (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- exams[].rooms. day/session are repeated here so the DB itself refuses
-- one room hosting two exams in the same slot.
CREATE TABLE exam_entry_rooms (
  schedule_version_id  INT UNSIGNED NOT NULL,
  exam_id              VARCHAR(32)  NOT NULL,
  room_id              VARCHAR(16)  NOT NULL,
  day                  ENUM('MON','TUE','WED','THU','FRI','SAT') NOT NULL,
  session              ENUM('AM','PM') NOT NULL,
  ordinal              TINYINT      NOT NULL,
  PRIMARY KEY (schedule_version_id, exam_id, room_id),
  UNIQUE KEY uq_exam_room_slot (schedule_version_id, day, session, room_id),
  CONSTRAINT fk_eer_entry FOREIGN KEY (schedule_version_id, exam_id)
      REFERENCES exam_entries (schedule_version_id, exam_id) ON DELETE CASCADE,
  CONSTRAINT fk_eer_room  FOREIGN KEY (room_id) REFERENCES rooms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Each run of the official validator.py against an exported version.
CREATE TABLE validation_runs (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  schedule_version_id    INT UNSIGNED NOT NULL,
  mode                   ENUM('schedule','disruption') NOT NULL,
  baseline_version_id    INT UNSIGNED NULL,           -- --baseline
  disruption_id          INT UNSIGNED NULL,           -- --disruption (FK added at the end)
  hard_violation_count   INT UNSIGNED NOT NULL,
  hard_by_rule           JSON         NOT NULL,       -- {"H3":2,"E1":1}
  soft_total             INT UNSIGNED NOT NULL,
  soft_penalties         JSON         NOT NULL,       -- {"S1":4,...,"S6":0}
  exam_soft_penalty      INT UNSIGNED NULL,
  booking_score          DECIMAL(6,1) NULL,
  priority_inversions    SMALLINT UNSIGNED NULL,
  sessions_moved         SMALLINT UNSIGNED NULL,      -- stability (disruption mode)
  report_json            LONGTEXT     NOT NULL,       -- validator --json output, verbatim
  validator_sha256       CHAR(64)     NOT NULL,       -- proves the untouched official file was used
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_vr_version (schedule_version_id, created_at),
  CONSTRAINT ck_vr_report CHECK (JSON_VALID(report_json)),
  CONSTRAINT fk_vr_version  FOREIGN KEY (schedule_version_id) REFERENCES schedule_versions (id) ON DELETE CASCADE,
  CONSTRAINT fk_vr_baseline FOREIGN KEY (baseline_version_id) REFERENCES schedule_versions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- =====================================================================
-- [M2] PRIORITY RULES, SPECTRA REQUESTS, DECISIONS, OVERRIDES
-- =====================================================================

-- Mirror of priority_order (rank 1 = highest). score_weight copies validator CAT_WEIGHT
-- for display only; the validator stays the authority.
CREATE TABLE priority_categories (
  code          VARCHAR(32) NOT NULL PRIMARY KEY,
  `rank`        TINYINT     NOT NULL,
  bookable      TINYINT(1)  NOT NULL,                 -- exam and regular_session cannot be requested
  score_weight  TINYINT     NULL,
  UNIQUE KEY uq_priority_rank (`rank`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Mirror of role_category_cap: the highest category a role may claim.
CREATE TABLE role_category_caps (
  role          VARCHAR(32) NOT NULL PRIMARY KEY,
  cap_category  VARCHAR(32) NOT NULL,
  CONSTRAINT fk_cap_cat FOREIGN KEY (cap_category) REFERENCES priority_categories (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dataset requests (source = dataset) and requests made in the app (source = app).
-- requested_category is stored as claimed; the effective (capped) category is computed.
CREATE TABLE booking_requests (
  id                    VARCHAR(32)  NOT NULL PRIMARY KEY,   -- BR-07 or APP-0001
  source                ENUM('dataset','app') NOT NULL,
  submitted_at          VARCHAR(40)  NOT NULL,        -- exact ISO string (validator compares strings)
  submitted_at_utc      DATETIME     NOT NULL,        -- the same instant, for SQL sorting
  requester             VARCHAR(120) NOT NULL,
  role                  VARCHAR(32)  NOT NULL,
  department            VARCHAR(16)  NULL,
  requested_category    VARCHAR(32)  NOT NULL,
  title                 VARCHAR(200) NOT NULL,
  attendance            SMALLINT UNSIGNED NOT NULL,
  duration_periods      TINYINT UNSIGNED NOT NULL,
  room_types            JSON         NOT NULL,
  needs                 JSON         NOT NULL,        -- "accessible" = room must be accessible
  requested_room        VARCHAR(32)  NULL,            -- a wish; may not exist (BR-14 asks for LHC-5), so no FK
  mandatory_for_groups  JSON         NOT NULL,
  series_id             VARCHAR(32)  NULL,
  raw_text              TEXT         NULL,            -- the original message; data, never instructions
  state                 ENUM('pending','decided','cancelled') NOT NULL DEFAULT 'pending',
  created_by            INT UNSIGNED NULL,            -- app user who submitted (NULL for dataset rows)
  ai_interaction_id     BIGINT UNSIGNED NULL,         -- set when the form was pre-filled by the AI parser
  ordinal               SMALLINT     NOT NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_br_state (state),
  KEY ix_br_series (series_id),
  KEY ix_br_requester (requester),
  -- role and requested_category have no FK on purpose: the validator accepts unknown values
  -- (unknown role caps at club_event, unknown category ranks last), so judge data must import too.
  CONSTRAINT ck_br_duration CHECK (duration_periods BETWEEN 1 AND 13),
  CONSTRAINT fk_br_user     FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_br_ai       FOREIGN KEY (ai_interaction_id) REFERENCES ai_interactions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- windows[]. to_period may exceed 13 in real data (BR-17 asks for P13-P14), so the
-- check is loose here. Feasibility is the engine's job, not the schema's.
CREATE TABLE booking_request_windows (
  request_id   VARCHAR(32) NOT NULL,
  ordinal      TINYINT     NOT NULL,
  day          ENUM('MON','TUE','WED','THU','FRI','SAT') NOT NULL,
  from_period  TINYINT     NOT NULL,
  to_period    TINYINT     NOT NULL,
  PRIMARY KEY (request_id, ordinal),
  KEY ix_brw_day (day),
  CONSTRAINT ck_brw_range CHECK (from_period >= 1 AND to_period >= from_period AND to_period <= 24),
  CONSTRAINT fk_brw_request FOREIGN KEY (request_id) REFERENCES booking_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- cancellations[] (dataset) plus withdrawals made in the app. A cancelled request is never approved.
CREATE TABLE cancellations (
  request_id        VARCHAR(32)  NOT NULL PRIMARY KEY,
  cancelled_at      VARCHAR(40)  NOT NULL,            -- exact ISO string
  cancelled_at_utc  DATETIME     NOT NULL,
  cancelled_by      VARCHAR(120) NOT NULL,            -- dataset "by"
  note              VARCHAR(500) NULL,
  cancelled_by_user INT UNSIGNED NULL,
  CONSTRAINT fk_cancel_request FOREIGN KEY (request_id) REFERENCES booking_requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_cancel_user    FOREIGN KEY (cancelled_by_user) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One decision per request per schedule version: the fest_week.bookings[] rows.
-- approved and alternative_offered both occupy the room (that is how the validator treats them).
CREATE TABLE booking_decisions (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  schedule_version_id  INT UNSIGNED NOT NULL,
  request_id           VARCHAR(32)  NOT NULL,
  status               ENUM('approved','rejected','alternative_offered') NOT NULL,
  day                  ENUM('MON','TUE','WED','THU','FRI') NULL,
  start_period         TINYINT      NULL,
  end_period           TINYINT      NULL,             -- inclusive
  room_id              VARCHAR(16)  NULL,
  reason               VARCHAR(500) NOT NULL DEFAULT '',
  effective_category   VARCHAR(32)  NOT NULL,         -- after role cap
  inside_window        TINYINT(1)   NULL,
  alternatives         JSON         NULL,             -- other options shown to the user
  review_state         ENUM('proposed','confirmed','overridden') NOT NULL DEFAULT 'proposed',
  reviewed_by          INT UNSIGNED NULL,
  reviewed_at          DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_decision (schedule_version_id, request_id),
  KEY ix_bd_room (schedule_version_id, room_id, day),
  CONSTRAINT ck_bd_slot CHECK (
      (status = 'rejected' AND day IS NULL AND start_period IS NULL AND end_period IS NULL AND room_id IS NULL)
   OR (status <> 'rejected' AND day IS NOT NULL AND room_id IS NOT NULL
       AND start_period BETWEEN 1 AND 13 AND end_period BETWEEN start_period AND 13)),
  CONSTRAINT ck_bd_reason CHECK (status = 'approved' OR CHAR_LENGTH(TRIM(reason)) > 0),
  CONSTRAINT fk_bd_version  FOREIGN KEY (schedule_version_id) REFERENCES schedule_versions (id) ON DELETE CASCADE,
  CONSTRAINT fk_bd_request  FOREIGN KEY (request_id) REFERENCES booking_requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_bd_room     FOREIGN KEY (room_id) REFERENCES rooms (id),
  CONSTRAINT fk_bd_category FOREIGN KEY (effective_category) REFERENCES priority_categories (code),
  CONSTRAINT fk_bd_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- fest_week.session_overrides[]: Week-7-only moves forced by an approved institute event.
CREATE TABLE session_overrides (
  schedule_version_id  INT UNSIGNED NOT NULL,
  session_id           VARCHAR(32)  NOT NULL,
  day                  ENUM('MON','TUE','WED','THU','FRI') NOT NULL,
  start_period         TINYINT      NOT NULL,
  room_id              VARCHAR(16)  NOT NULL,
  displaced_by         VARCHAR(32)  NOT NULL,         -- request_id of the approved institute event
  PRIMARY KEY (schedule_version_id, session_id),
  CONSTRAINT ck_so_period CHECK (start_period BETWEEN 1 AND 9),
  CONSTRAINT fk_so_version FOREIGN KEY (schedule_version_id) REFERENCES schedule_versions (id) ON DELETE CASCADE,
  CONSTRAINT fk_so_room    FOREIGN KEY (room_id) REFERENCES rooms (id),
  CONSTRAINT fk_so_request FOREIGN KEY (displaced_by) REFERENCES booking_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- =====================================================================
-- [M3] DISRUPTIONS, PROPOSALS, CHANGES, FACULTY CHANGE REQUESTS
-- =====================================================================

CREATE TABLE disruptions (
  id                         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  external_id                VARCHAR(64)  NULL,        -- file "id", e.g. sample-disruption
  dataset_ref                VARCHAR(64)  NULL,        -- file "dataset"
  description                VARCHAR(500) NULL,
  raw_json                   LONGTEXT     NOT NULL,    -- exact uploaded bytes (passed to the validator)
  sha256                     CHAR(64)     NOT NULL,
  status                     ENUM('uploaded','proposed','applied','rejected','invalid') NOT NULL DEFAULT 'uploaded',
  error_message              VARCHAR(1000) NULL,
  base_dataset_version_id    INT UNSIGNED NOT NULL,
  result_dataset_version_id  INT UNSIGNED NULL,        -- set once applied
  uploaded_by                INT UNSIGNED NULL,
  uploaded_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT ck_dis_json CHECK (JSON_VALID(raw_json)),
  CONSTRAINT fk_dis_base   FOREIGN KEY (base_dataset_version_id) REFERENCES dataset_versions (id),
  CONSTRAINT fk_dis_result FOREIGN KEY (result_dataset_version_id) REFERENCES dataset_versions (id) ON DELETE SET NULL,
  CONSTRAINT fk_dis_user   FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- changes[] in order. The five types the validator accepts, nothing else.
CREATE TABLE disruption_changes (
  disruption_id  INT UNSIGNED NOT NULL,
  ordinal        SMALLINT     NOT NULL,
  type           ENUM('faculty_unavailable','room_unavailable','add_faculty','add_session','remove_session') NOT NULL,
  target_id      VARCHAR(32)  NULL,                   -- faculty/room/session id the change touches
  payload        JSON         NOT NULL,               -- the change object verbatim
  PRIMARY KEY (disruption_id, ordinal),
  CONSTRAINT fk_dc_dis FOREIGN KEY (disruption_id) REFERENCES disruptions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Faculty natural-language change requests (requirement 7). Parsed by AI, proposed by the engine.
CREATE TABLE change_requests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  requested_by  INT UNSIGNED NOT NULL,
  raw_text      TEXT         NOT NULL,
  parsed        JSON         NULL,                    -- user-confirmed structured change(s)
  state         ENUM('draft','submitted','proposed','approved','rejected','infeasible') NOT NULL DEFAULT 'draft',
  ai_interaction_id BIGINT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cr_user FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_ai   FOREIGN KEY (ai_interaction_id) REFERENCES ai_interactions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- A repaired timetable waiting for Admin approval. proposed_version_id points at a
-- schedule_versions row (kind = repair, status = draft) that becomes active on approval.
CREATE TABLE reschedule_proposals (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  disruption_id           INT UNSIGNED NULL,
  change_request_id       INT UNSIGNED NULL,
  baseline_version_id     INT UNSIGNED NOT NULL,
  proposed_version_id     INT UNSIGNED NULL,          -- NULL when infeasible
  status                  ENUM('pending','approved','rejected','infeasible','stale') NOT NULL DEFAULT 'pending',
  sessions_moved          SMALLINT UNSIGNED NULL,     -- same definition as validator stability
  time_changed            SMALLINT UNSIGNED NULL,
  room_only_changed       SMALLINT UNSIGNED NULL,
  infeasible_reasons      JSON         NULL,          -- [{"constraint":"H7","entities":["F07","MON"],"detail":"..."}]
  created_by              INT UNSIGNED NULL,
  created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_by              INT UNSIGNED NULL,
  decided_at              DATETIME     NULL,
  decision_comment        VARCHAR(1000) NULL,
  KEY ix_rp_status (status),
  CONSTRAINT ck_rp_origin CHECK (disruption_id IS NOT NULL OR change_request_id IS NOT NULL),
  CONSTRAINT fk_rp_dis      FOREIGN KEY (disruption_id) REFERENCES disruptions (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_cr       FOREIGN KEY (change_request_id) REFERENCES change_requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_baseline FOREIGN KEY (baseline_version_id) REFERENCES schedule_versions (id),
  CONSTRAINT fk_rp_proposed FOREIGN KEY (proposed_version_id) REFERENCES schedule_versions (id) ON DELETE SET NULL,
  CONSTRAINT fk_rp_created  FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_rp_decided  FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- The before/after diff: one row per session that differs between baseline and proposal.
CREATE TABLE schedule_changes (
  proposal_id   INT UNSIGNED NOT NULL,
  session_id    VARCHAR(32)  NOT NULL,
  change_kind   ENUM('moved','room_only','added','removed') NOT NULL,
  from_day      ENUM('MON','TUE','WED','THU','FRI') NULL,
  from_period   TINYINT      NULL,
  from_room     VARCHAR(16)  NULL,
  to_day        ENUM('MON','TUE','WED','THU','FRI') NULL,
  to_period     TINYINT      NULL,
  to_room       VARCHAR(16)  NULL,
  reason        VARCHAR(500) NOT NULL DEFAULT '',     -- e.g. "F07 unavailable MON (disruption change 1)"
  PRIMARY KEY (proposal_id, session_id),
  CONSTRAINT ck_sc_shape CHECK (
      (change_kind = 'added'   AND from_day IS NULL     AND to_day IS NOT NULL)
   OR (change_kind = 'removed' AND from_day IS NOT NULL AND to_day IS NULL)
   OR (change_kind IN ('moved','room_only') AND from_day IS NOT NULL AND to_day IS NOT NULL)),
  CONSTRAINT fk_sc_proposal FOREIGN KEY (proposal_id) REFERENCES reschedule_proposals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Cross-module foreign key added after both tables exist.
ALTER TABLE validation_runs
  ADD CONSTRAINT fk_vr_disruption FOREIGN KEY (disruption_id) REFERENCES disruptions (id) ON DELETE SET NULL;

-- =====================================================================
-- VIEWS (read-only helpers for the dashboard, timetable views and chatbot tools)
-- =====================================================================

CREATE VIEW v_active_schedule_version AS
  SELECT id AS schedule_version_id, dataset_version_id
  FROM schedule_versions WHERE status = 'active';

-- Active timetable, one row per occupied period (regular week, no overrides).
CREATE VIEW v_session_slots AS
  SELECT e.schedule_version_id, e.session_id, s.course_id, s.kind, s.faculty_id, s.basket_slot,
         e.room_id, e.day, p.id AS period
  FROM schedule_entries e
  JOIN v_active_schedule_version a ON a.schedule_version_id = e.schedule_version_id
  JOIN cal_periods p ON p.id BETWEEN e.start_period AND e.start_period + e.duration - 1
  LEFT JOIN sessions s ON s.id = e.session_id;

-- Same, per student group (combined lectures appear once for each group).
CREATE VIEW v_group_slots AS
  SELECT ss.*, g.group_id
  FROM v_session_slots ss
  JOIN session_groups g ON g.session_id = ss.session_id;

-- Spectra-week room use by booking decisions on the active version.
CREATE VIEW v_booking_slots AS
  SELECT d.schedule_version_id, d.request_id, d.status, d.room_id, d.day, p.id AS period
  FROM booking_decisions d
  JOIN v_active_schedule_version a ON a.schedule_version_id = d.schedule_version_id
  JOIN cal_periods p ON p.id BETWEEN d.start_period AND d.end_period
  WHERE d.status IN ('approved','alternative_offered');

CREATE VIEW v_room_unavailable_slots AS
  SELECT u.room_id, u.day, p.id AS period, u.reason
  FROM room_unavailability u
  JOIN cal_periods p ON JSON_CONTAINS(u.periods, CAST(p.id AS CHAR));

CREATE VIEW v_faculty_unavailable_slots AS
  SELECT u.faculty_id, u.day, p.id AS period, u.reason
  FROM faculty_unavailability u
  JOIN cal_periods p ON JSON_CONTAINS(u.periods, CAST(p.id AS CHAR));

-- Teaching-hour utilisation of each room in the active timetable (45 teaching periods a week).
CREATE VIEW v_room_utilization AS
  SELECT r.id AS room_id, r.name, r.type, r.capacity,
         COUNT(ss.period) AS used_periods,
         (SELECT COUNT(*) FROM cal_days d WHERE d.teaching_day = 1)
           * (SELECT COUNT(*) FROM cal_periods q WHERE q.is_teaching = 1) AS teaching_periods,
         ROUND(100 * COUNT(ss.period) /
           ((SELECT COUNT(*) FROM cal_days d WHERE d.teaching_day = 1)
            * (SELECT COUNT(*) FROM cal_periods q WHERE q.is_teaching = 1)), 1) AS utilization_pct
  FROM rooms r
  LEFT JOIN v_session_slots ss ON ss.room_id = r.id
  GROUP BY r.id, r.name, r.type, r.capacity;

-- Everything waiting on a human, for the dashboard.
CREATE VIEW v_pending_approvals AS
  SELECT 'booking_decision' AS item_type, CAST(d.id AS CHAR) AS item_id, d.request_id AS ref,
         CONCAT(d.status, ' - ', b.title) AS summary, d.created_at
  FROM booking_decisions d
  JOIN v_active_schedule_version a ON a.schedule_version_id = d.schedule_version_id
  JOIN booking_requests b ON b.id = d.request_id
  WHERE d.review_state = 'proposed'
  UNION ALL
  SELECT 'reschedule_proposal', CAST(p.id AS CHAR), CAST(p.disruption_id AS CHAR),
         CONCAT(COALESCE(p.sessions_moved, 0), ' sessions moved'), p.created_at
  FROM reschedule_proposals p WHERE p.status = 'pending'
  UNION ALL
  SELECT 'change_request', CAST(c.id AS CHAR), CAST(c.requested_by AS CHAR),
         LEFT(c.raw_text, 120), c.created_at
  FROM change_requests c WHERE c.state = 'submitted';
