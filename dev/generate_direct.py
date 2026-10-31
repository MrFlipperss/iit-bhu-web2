"""
Option 2: Generate timetable directly via Python engine and insert into MySQL.
Bypasses PHP entirely.
"""
import sys
import os
import json
import time
import hashlib
import subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
ENGINE = os.path.join(ROOT, 'engine')
sys.path.insert(0, ENGINE)

# --- Load .env ---
env_path = os.path.join(ROOT, '.env')
env = {}
with open(env_path) as f:
    for line in f:
        line = line.strip()
        if line and not line.startswith('#') and '=' in line:
            k, v = line.split('=', 1)
            env[k.strip()] = v.strip()

DB_HOST = env.get('DB_HOST', '127.0.0.1')
DB_PORT = int(env.get('DB_PORT', 3306))
DB_NAME = env.get('DB_NAME', 'cit_scheduler')
DB_USER = env.get('DB_USER', 'root')
DB_PASSWORD = env.get('DB_PASSWORD', '')

print(f"DB: {DB_USER}@{DB_HOST}:{DB_PORT}/{DB_NAME}")

# --- Connect to MySQL ---
try:
    import pymysql
    print("Using pymysql")
except ImportError:
    print("Installing pymysql...")
    subprocess.run([sys.executable, '-m', 'pip', 'install', 'pymysql', '-q'], check=True)
    import pymysql

db = pymysql.connect(host=DB_HOST, port=DB_PORT, user=DB_USER, password=DB_PASSWORD,
                     database=DB_NAME, charset='utf8mb4',
                     cursorclass=pymysql.cursors.DictCursor)

cur = db.cursor()

# --- Get current dataset ---
cur.execute("SELECT id, snapshot_json FROM dataset_versions ORDER BY id DESC LIMIT 1")
row = cur.fetchone()
if not row:
    print("ERROR: No dataset found. Run php database/reset.php first.")
    sys.exit(1)

dataset_version_id = row['id']
dataset = json.loads(row['snapshot_json'])
print(f"Dataset version: {dataset_version_id}")

# --- Get admin user ---
cur.execute("SELECT id, username FROM users WHERE role='admin' LIMIT 1")
admin = cur.fetchone()
if not admin:
    print("ERROR: No admin user found.")
    sys.exit(1)
print(f"Running as: {admin['username']} (id={admin['id']})")

# --- Check for extra booking requests ---
cur.execute("SELECT * FROM booking_requests WHERE source='app' AND state != 'cancelled' ORDER BY ordinal")
app_requests_rows = cur.fetchall()
cur.execute("""SELECT w.* FROM booking_request_windows w 
               JOIN booking_requests b ON b.id = w.request_id 
               WHERE b.source='app' ORDER BY w.request_id, w.ordinal""")
windows_rows = cur.fetchall()
windows_by_req = {}
for w in windows_rows:
    windows_by_req.setdefault(w['request_id'], []).append({
        'day': w['day'], 'from_period': int(w['from_period']), 'to_period': int(w['to_period'])
    })

extra_requests = [{
    'id': r['id'], 'submitted_at': str(r['submitted_at']), 'requester': r['requester'],
    'role': r['role'], 'department': r['department'], 'requested_category': r['requested_category'],
    'title': r['title'], 'attendance': int(r['attendance']),
    'duration_periods': int(r['duration_periods']), 'windows': windows_by_req.get(r['id'], []),
    'room_types': json.loads(r['room_types'] or '[]'), 'needs': json.loads(r['needs'] or '[]'),
    'requested_room': r['requested_room'], 'mandatory_for_groups': json.loads(r['mandatory_for_groups'] or '[]'),
    'series_id': r['series_id'], 'raw_text': r['raw_text']
} for r in app_requests_rows]

# --- Build request ---
request = {
    'command': 'generate_all',
    'dataset': dataset,
    'team': 'CIT team',
    'extra_requests': extra_requests
}

print("Running engine... (this will take ~20-40 seconds)")
t0 = time.time()

# Run engine CLI
cli_path = os.path.join(ENGINE, 'cli.py')
proc = subprocess.run(
    [sys.executable, '-I', cli_path],
    input=json.dumps(request, ensure_ascii=False),
    capture_output=True, text=True, timeout=120,
    cwd=ENGINE
)

elapsed = round(time.time() - t0, 2)
print(f"Engine finished in {elapsed}s")

if proc.returncode != 0:
    print("STDERR:", proc.stderr[:500])
    print("STDOUT:", proc.stdout[:500])
    sys.exit(1)

if proc.stderr:
    print("Engine log:", proc.stderr[:300])

res = json.loads(proc.stdout)
if not res.get('ok'):
    print("Engine error:", res)
    sys.exit(1)

report = res['report']
sched = res['schedule']
print(f"Hard violations: {report['hard_violation_count']}")
print(f"Soft total: {report['soft_total']}")
print(f"Sessions: {len(sched.get('sessions', []))}")

# --- Insert into DB ---
sched_json = json.dumps(sched, ensure_ascii=False)
sched_sha = hashlib.sha256(sched_json.encode()).hexdigest()
status = 'validated' if report['hard_violation_count'] == 0 else 'draft'

solver_params = json.dumps({
    'reserved': res.get('reserved', {}),
    'timetable': res.get('timetable_stats', {}),
    'exams': res.get('exam_stats', {})
})
has_cpsat = 'solver_status' in (res.get('timetable_stats') or {})
solver = 'ortools-cpsat+greedy' if has_cpsat else 'greedy'

cur.execute("""INSERT INTO schedule_versions 
    (dataset_version_id, parent_id, kind, status, team_name, solver, solver_params, solve_seconds,
     export_json, export_sha256, note, created_by)
    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
    (dataset_version_id, None, 'generated', status,
     sched.get('team', 'CIT team'), solver, solver_params, res.get('seconds', elapsed),
     sched_json, sched_sha, None, admin['id']))
version_id = cur.lastrowid
print(f"Created schedule_version id={version_id}")

# --- Insert schedule entries ---
dur_by_id = {s['id']: s['duration'] for s in dataset.get('sessions', [])}
ins_entry = """INSERT INTO schedule_entries 
    (schedule_version_id, session_id, day, start_period, room_id, duration) 
    VALUES (%s, %s, %s, %s, %s, %s)"""
for e in sched.get('sessions', []):
    cur.execute(ins_entry, (version_id, e['session_id'], e['day'], e['start_period'],
                             e['room_id'], dur_by_id.get(e['session_id'], 1)))
print(f"Inserted {len(sched.get('sessions', []))} schedule entries")

# --- Insert exam entries ---
for x in sched.get('exams', []):
    cur.execute("INSERT INTO exam_entries (schedule_version_id, exam_id, day, session) VALUES (%s,%s,%s,%s)",
                (version_id, x['exam_id'], x['day'], x['session']))
    for i, r in enumerate(x.get('rooms', [])):
        cur.execute("INSERT INTO exam_entry_rooms (schedule_version_id, exam_id, room_id, day, session, ordinal) VALUES (%s,%s,%s,%s,%s,%s)",
                    (version_id, x['exam_id'], r, x['day'], x['session'], i))
print(f"Inserted {len(sched.get('exams', []))} exam entries")

# --- Insert booking decisions ---
all_decisions = res.get('all_decisions', [])
details = res.get('details', {})
fest_week = sched.get('fest_week', {})
overrides = fest_week.get('session_overrides', [])

cur.execute("SELECT request_id FROM cancellations")
cancelled = {r['request_id'] for r in cur.fetchall()}

for d in all_decisions:
    rid = d['request_id']
    review = 'confirmed' if rid in cancelled else 'proposed'
    notes = details.get(rid, {}).get('notes', [])
    cur.execute("""INSERT INTO booking_decisions 
        (schedule_version_id, request_id, status, day, start_period, end_period, room_id, reason,
         effective_category, inside_window, alternatives, review_state, reviewed_by, reviewed_at)
        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
        (version_id, rid, d['status'], d.get('day'), d.get('start_period'), d.get('end_period'),
         d.get('room_id'), d.get('reason', ''),
         details.get(rid, {}).get('effective_category', 'club_event'),
         1 if d['status'] == 'approved' else (0 if d['status'] == 'alternative_offered' else None),
         json.dumps({'notes': notes}) if notes else None,
         review, None, None))
print(f"Inserted {len(all_decisions)} booking decisions")

for o in overrides:
    cur.execute("INSERT INTO session_overrides (schedule_version_id, session_id, day, start_period, room_id, displaced_by) VALUES (%s,%s,%s,%s,%s,%s)",
                (version_id, o['session_id'], o['day'], o['start_period'], o['room_id'], o.get('displaced_by')))

# --- Insert validation run ---
val_sha_path = os.path.join(ROOT, 'validator', 'validator.py')
val_sha = hashlib.sha256(open(val_sha_path, 'rb').read()).hexdigest() if os.path.exists(val_sha_path) else ''
cur.execute("""INSERT INTO validation_runs 
    (schedule_version_id, mode, hard_violation_count, hard_by_rule, soft_total, soft_penalties,
     exam_soft_penalty, booking_score, priority_inversions, report_json, validator_sha256)
    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
    (version_id, 'schedule', report['hard_violation_count'],
     json.dumps(report.get('hard_by_rule', {})),
     report['soft_total'], json.dumps(report.get('soft_penalties', {})),
     report.get('exam_soft_penalty'), report.get('bookings', {}).get('booking_score'),
     len(report.get('bookings', {}).get('priority_inversions', [])),
     json.dumps(report), val_sha))
print("Inserted validation run")

# Update schedule_versions scores
cur.execute("UPDATE schedule_versions SET hard_violation_count=%s, soft_total=%s, booking_score=%s WHERE id=%s",
            (report['hard_violation_count'], report['soft_total'],
             report.get('bookings', {}).get('booking_score'), version_id))

# --- Activate if valid ---
if report['hard_violation_count'] == 0:
    cur.execute("UPDATE schedule_versions SET status='superseded' WHERE status='active'")
    cur.execute("UPDATE schedule_versions SET status='active', activated_by=%s, activated_at=UTC_TIMESTAMP() WHERE id=%s",
                (admin['id'], version_id))

# --- Audit log ---
try:
    cur.execute("""INSERT INTO audit_logs (user_id, action, entity_type, entity_id, meta_json) 
                   VALUES (%s,%s,%s,%s,%s)""",
                (admin['id'], 'schedule.generate', 'schedule_version', str(version_id),
                 json.dumps({'hard': report['hard_violation_count'], 'soft': report['soft_total'], 'seconds': res.get('seconds', elapsed)})))
except Exception as e:
    print(f"Audit log skipped: {e}")

# --- Commit everything ---
db.commit()

# --- Print result ---
if report['hard_violation_count'] == 0:
    print(f"Timetable ACTIVATED as version {version_id}!")
else:
    print(f"Hard violations: {report['hard_violation_count']} -- saved as draft (version {version_id})")

db.close()

print(f"\nDONE! Open http://localhost:8000 and refresh -- timetable version {version_id} is now visible.")
