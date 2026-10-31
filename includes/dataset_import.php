<?php
// includes/dataset_import.php   [M4 owns; M1/M2/M3 call cit_current_dataset / cit_create_dataset_version]
//
// Imports a dataset JSON (cit_public.json format) into MySQL:
//   1. checks its shape and internal references (clear error list, nothing written on failure)
//   2. stores the exact bytes as a dataset version (canonical copy for the Python engine)
//   3. reloads the reference mirror tables used by the UI, dashboard and chatbot SQL tools
//   4. (full import only) loads booking requests, windows and cancellations
// Field names and IDs are kept exactly as the starter pack defines them.
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CIT_DAYS_ORDER = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

// Display-only copy of validator.py CAT_WEIGHT. The validator stays the authority.
const CIT_CATEGORY_WEIGHTS = ['institute_event' => 5, 'guest_lecture' => 4, 'department_event' => 3, 'club_event' => 2];

// field => accepted types ('?' prefix = may be null)
const CIT_SHAPE = [
    'rooms' => ['id' => 'string', 'name' => 'string', 'building' => 'string', 'type' => 'string',
        'capacity' => 'int', 'features' => 'list', 'accessible' => 'bool', 'allowed_uses' => 'list'],
    'room_unavailability' => ['room_id' => 'string', 'day' => 'string', 'periods' => 'list'],
    'faculty' => ['id' => 'string', 'name' => 'string', 'department' => 'string', 'max_hours_per_day' => 'int',
        'needs_accessible' => 'bool', 'unavailable' => 'list', 'preferences' => 'list'],
    'student_groups' => ['id' => 'string', 'name' => 'string', 'year' => 'int', 'department' => 'string',
        'size' => 'int', 'needs_accessible' => 'bool'],
    'courses' => ['id' => 'string', 'title' => 'string', 'department' => 'string', 'year' => 'int',
        'lecture_hours' => 'int', 'tutorial_hours' => 'int', 'lab_blocks' => 'int'],
    'sessions' => ['id' => 'string', 'course_id' => 'string', 'kind' => 'string', 'groups' => 'list',
        'faculty_id' => 'string', 'size' => 'int', 'duration' => 'int', 'room_types' => 'list',
        'needs_accessible' => 'bool', 'basket_slot' => '?string', 'fixed_slot' => '?object'],
    'booking_requests' => ['id' => 'string', 'submitted_at' => 'string', 'requester' => 'string', 'role' => 'string',
        'requested_category' => 'string', 'title' => 'string', 'attendance' => 'int', 'duration_periods' => 'int',
        'windows' => 'list', 'room_types' => 'list', 'needs' => 'list', 'requested_room' => '?string',
        'mandatory_for_groups' => 'list', 'series_id' => '?string', 'raw_text' => '?string'],
    'cancellations' => ['request_id' => 'string', 'cancelled_at' => 'string'],
    'exams' => ['id' => 'string', 'course_ids' => 'list', 'groups' => 'list', 'size' => 'int', 'duration_hours' => 'int'],
];

function cit_type_ok(mixed $v, string $type): bool
{
    if ($type[0] === '?') {
        if ($v === null) {
            return true;
        }
        $type = substr($type, 1);
    }
    return match ($type) {
        'string' => is_string($v),
        'int'    => is_int($v),
        'bool'   => is_bool($v),
        'list'   => is_array($v) && array_is_list($v),
        'object' => is_array($v) && ($v === [] || !array_is_list($v)),
        default  => false,
    };
}

/** Returns a list of human-readable problems; empty list = safe to import. */
function cit_dataset_shape_errors(mixed $d): array
{
    $err = [];
    if (!is_array($d) || array_is_list($d)) {
        return ['The file is not a JSON object.'];
    }
    foreach (['dataset_id', 'calendar', 'priority_order', 'role_category_cap'] as $k) {
        if (!array_key_exists($k, $d)) {
            $err[] = "Missing top-level key \"$k\".";
        }
    }
    foreach (CIT_SHAPE as $section => $fields) {
        if (!isset($d[$section]) || !is_array($d[$section]) || !array_is_list($d[$section])) {
            $err[] = "Missing or invalid list \"$section\".";
            continue;
        }
        foreach ($d[$section] as $i => $row) {
            $label = $section . '[' . $i . ']' . (is_array($row) && isset($row['id']) && is_string($row['id']) ? " ({$row['id']})" : '');
            if (!is_array($row)) {
                $err[] = "$label is not an object.";
                continue;
            }
            foreach ($fields as $f => $type) {
                if (!array_key_exists($f, $row)) {
                    if ($type[0] !== '?') {
                        $err[] = "$label is missing \"$f\".";
                    }
                } elseif (!cit_type_ok($row[$f], $type)) {
                    $err[] = "$label field \"$f\" should be " . ltrim($type, '?') . '.';
                }
            }
        }
    }
    $cal = $d['calendar'] ?? null;
    if (!is_array($cal) || !isset($cal['days'], $cal['periods'], $cal['lunch']['after_period'],
            $cal['lab_start_periods'], $cal['event_only_periods'], $cal['exam_week']['days'], $cal['exam_week']['sessions'])) {
        $err[] = 'calendar is missing days, periods, lunch, lab_start_periods, event_only_periods or exam_week.';
    }
    if ($err) {
        return array_slice($err, 0, 50);
    }

    // Internal references (would otherwise surface later as confusing FK errors).
    $ids = fn(string $s) => array_flip(array_column($d[$s], 'id'));
    $rooms = $ids('rooms'); $fac = $ids('faculty'); $grp = $ids('student_groups');
    $crs = $ids('courses'); $req = $ids('booking_requests');
    foreach (['rooms', 'faculty', 'student_groups', 'courses', 'sessions', 'booking_requests', 'exams'] as $s) {
        $seen = [];
        foreach ($d[$s] as $row) {
            if (isset($seen[$row['id']])) {
                $err[] = "Duplicate id {$row['id']} in $s.";
            }
            $seen[$row['id']] = true;
        }
    }
    foreach ($d['sessions'] as $s) {
        if (!isset($crs[$s['course_id']])) $err[] = "Session {$s['id']} references unknown course {$s['course_id']}.";
        if (!isset($fac[$s['faculty_id']])) $err[] = "Session {$s['id']} references unknown faculty {$s['faculty_id']}.";
        foreach ($s['groups'] as $g) {
            if (!isset($grp[$g])) $err[] = "Session {$s['id']} references unknown group $g.";
        }
        if (!in_array($s['kind'], ['lecture', 'tutorial', 'lab'], true)) $err[] = "Session {$s['id']} has unknown kind {$s['kind']}.";
    }
    foreach ($d['room_unavailability'] as $u) {
        if (!isset($rooms[$u['room_id']])) $err[] = "room_unavailability references unknown room {$u['room_id']}.";
    }
    foreach ($d['exams'] as $e) {
        foreach ($e['course_ids'] as $c) if (!isset($crs[$c])) $err[] = "Exam {$e['id']} references unknown course $c.";
        foreach ($e['groups'] as $g) if (!isset($grp[$g])) $err[] = "Exam {$e['id']} references unknown group $g.";
    }
    foreach ($d['cancellations'] as $c) {
        if (!isset($req[$c['request_id']])) $err[] = "Cancellation references unknown request {$c['request_id']}.";
    }
    foreach ($d['role_category_cap'] as $role => $cap) {
        if (!in_array($cap, $d['priority_order'], true)) $err[] = "Role cap for $role ($cap) is not in priority_order.";
    }
    return array_slice($err, 0, 50);
}

function cit_utc(string $iso): string
{
    return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Insert many rows with one prepared statement. Column names are trusted constants. */
function cit_insert_rows(PDO $db, string $table, array $columns, array $rows): int
{
    if (!$rows) {
        return 0;
    }
    $cols = implode(', ', array_map(fn($c) => "`$c`", $columns));
    $marks = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $db->prepare("INSERT INTO `$table` ($cols) VALUES ($marks)");
    foreach ($rows as $row) {
        $stmt->execute($row);
    }
    return count($rows);
}

/** Reload calendar, priority rules and the reference mirror from a decoded dataset. */
function cit_sync_reference_tables(PDO $db, array $d): array
{
    $mirror = ['exam_groups', 'exam_courses', 'exams', 'session_groups', 'sessions', 'courses', 'student_groups',
        'faculty_unavailability', 'faculty', 'room_unavailability', 'rooms', 'role_category_caps',
        'priority_categories', 'exam_slots', 'cal_periods', 'cal_days'];
    // Long-lived tables keep FKs to rooms/faculty/groups/categories. Their IDs come back
    // in the reload, so checks are switched off only while the old rows are deleted.
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($mirror as $t) {
            $db->exec("DELETE FROM `$t`");
        }
    } finally {
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    $cal = $d['calendar'];
    $n = [];
    $days = array_values(array_intersect(CIT_DAYS_ORDER, array_unique(array_merge($cal['days'], $cal['exam_week']['days']))));
    $n['cal_days'] = cit_insert_rows($db, 'cal_days', ['code', 'ordinal', 'teaching_day', 'exam_day'],
        array_map(fn($day, $i) => [$day, $i + 1, (int)in_array($day, $cal['days'], true), (int)in_array($day, $cal['exam_week']['days'], true)],
            $days, array_keys($days)));
    $n['cal_periods'] = cit_insert_rows($db, 'cal_periods',
        ['id', 'start_time', 'end_time', 'is_teaching', 'is_event_only', 'is_lab_start', 'lunch_after'],
        array_map(fn($p) => [$p['id'], $p['start'], $p['end'], (int)$p['teaching'],
            (int)in_array($p['id'], $cal['event_only_periods'], true),
            (int)in_array($p['id'], $cal['lab_start_periods'], true),
            (int)($p['id'] === $cal['lunch']['after_period'])], $cal['periods']));
    $n['exam_slots'] = cit_insert_rows($db, 'exam_slots', ['code', 'start_time', 'end_time'],
        array_map(fn($s) => [$s['id'], $s['start'], $s['end']], $cal['exam_week']['sessions']));

    $n['priority_categories'] = cit_insert_rows($db, 'priority_categories', ['code', 'rank', 'bookable', 'score_weight'],
        array_map(fn($c, $i) => [$c, $i + 1, (int)isset(CIT_CATEGORY_WEIGHTS[$c]), CIT_CATEGORY_WEIGHTS[$c] ?? null],
            $d['priority_order'], array_keys($d['priority_order'])));
    $n['role_category_caps'] = cit_insert_rows($db, 'role_category_caps', ['role', 'cap_category'],
        array_map(fn($r, $c) => [$r, $c], array_keys($d['role_category_cap']), $d['role_category_cap']));

    $n['rooms'] = cit_insert_rows($db, 'rooms',
        ['id', 'name', 'building', 'type', 'capacity', 'features', 'accessible', 'allowed_uses', 'ordinal'],
        array_map(fn($r, $i) => [$r['id'], $r['name'], $r['building'], $r['type'], $r['capacity'],
            cit_json($r['features']), (int)$r['accessible'], cit_json($r['allowed_uses']), $i], $d['rooms'], array_keys($d['rooms'])));
    $n['room_unavailability'] = cit_insert_rows($db, 'room_unavailability', ['room_id', 'day', 'periods', 'reason', 'ordinal'],
        array_map(fn($u, $i) => [$u['room_id'], $u['day'], cit_json($u['periods']), $u['reason'] ?? '', $i],
            $d['room_unavailability'], array_keys($d['room_unavailability'])));

    $n['faculty'] = cit_insert_rows($db, 'faculty',
        ['id', 'name', 'department', 'max_hours_per_day', 'needs_accessible', 'preferences', 'ordinal'],
        array_map(fn($f, $i) => [$f['id'], $f['name'], $f['department'], $f['max_hours_per_day'],
            (int)$f['needs_accessible'], cit_json($f['preferences']), $i], $d['faculty'], array_keys($d['faculty'])));
    $fu = [];
    foreach ($d['faculty'] as $f) {
        foreach ($f['unavailable'] as $i => $u) {
            $fu[] = [$f['id'], $u['day'], cit_json($u['periods']), $u['reason'] ?? '', $i];
        }
    }
    $n['faculty_unavailability'] = cit_insert_rows($db, 'faculty_unavailability', ['faculty_id', 'day', 'periods', 'reason', 'ordinal'], $fu);

    $n['student_groups'] = cit_insert_rows($db, 'student_groups', ['id', 'name', 'year', 'department', 'size', 'needs_accessible', 'ordinal'],
        array_map(fn($g, $i) => [$g['id'], $g['name'], $g['year'], $g['department'], $g['size'], (int)$g['needs_accessible'], $i],
            $d['student_groups'], array_keys($d['student_groups'])));
    $n['courses'] = cit_insert_rows($db, 'courses',
        ['id', 'title', 'department', 'year', 'lecture_hours', 'tutorial_hours', 'lab_blocks', 'ordinal'],
        array_map(fn($c, $i) => [$c['id'], $c['title'], $c['department'], $c['year'], $c['lecture_hours'],
            $c['tutorial_hours'], $c['lab_blocks'], $i], $d['courses'], array_keys($d['courses'])));

    $n['sessions'] = cit_insert_rows($db, 'sessions',
        ['id', 'course_id', 'kind', 'faculty_id', 'size', 'duration', 'room_types', 'needs_accessible',
         'basket_slot', 'fixed_day', 'fixed_start_period', 'ordinal'],
        array_map(fn($s, $i) => [$s['id'], $s['course_id'], $s['kind'], $s['faculty_id'], $s['size'], $s['duration'],
            cit_json($s['room_types']), (int)$s['needs_accessible'], $s['basket_slot'] ?? null,
            $s['fixed_slot']['day'] ?? null, $s['fixed_slot']['start_period'] ?? null, $i], $d['sessions'], array_keys($d['sessions'])));
    $sg = [];
    foreach ($d['sessions'] as $s) {
        foreach ($s['groups'] as $i => $g) {
            $sg[] = [$s['id'], $g, $i];
        }
    }
    $n['session_groups'] = cit_insert_rows($db, 'session_groups', ['session_id', 'group_id', 'ordinal'], $sg);

    $n['exams'] = cit_insert_rows($db, 'exams', ['id', 'size', 'duration_hours', 'ordinal'],
        array_map(fn($e, $i) => [$e['id'], $e['size'], $e['duration_hours'], $i], $d['exams'], array_keys($d['exams'])));
    $ec = $eg = [];
    foreach ($d['exams'] as $e) {
        foreach ($e['course_ids'] as $i => $c) $ec[] = [$e['id'], $c, $i];
        foreach ($e['groups'] as $i => $g) $eg[] = [$e['id'], $g, $i];
    }
    $n['exam_courses'] = cit_insert_rows($db, 'exam_courses', ['exam_id', 'course_id', 'ordinal'], $ec);
    $n['exam_groups'] = cit_insert_rows($db, 'exam_groups', ['exam_id', 'group_id', 'ordinal'], $eg);
    return $n;
}

/** Replace all dataset booking requests (keeps app-created ones), windows and cancellations. */
function cit_load_booking_requests(PDO $db, array $d): array
{
    $db->exec("DELETE FROM booking_requests WHERE source = 'dataset'");   // cascades windows, cancellations, decisions
    $cancelled = array_flip(array_column($d['cancellations'], 'request_id'));
    $n = [];
    $n['booking_requests'] = cit_insert_rows($db, 'booking_requests',
        ['id', 'source', 'submitted_at', 'submitted_at_utc', 'requester', 'role', 'department', 'requested_category',
         'title', 'attendance', 'duration_periods', 'room_types', 'needs', 'requested_room', 'mandatory_for_groups',
         'series_id', 'raw_text', 'state', 'ordinal'],
        array_map(fn($r, $i) => [$r['id'], 'dataset', $r['submitted_at'], cit_utc($r['submitted_at']), $r['requester'],
            $r['role'], $r['department'] ?? null, $r['requested_category'], $r['title'], $r['attendance'],
            $r['duration_periods'], cit_json($r['room_types']), cit_json($r['needs']), $r['requested_room'] ?? null,
            cit_json($r['mandatory_for_groups']), $r['series_id'] ?? null, $r['raw_text'] ?? null,
            isset($cancelled[$r['id']]) ? 'cancelled' : 'pending', $i], $d['booking_requests'], array_keys($d['booking_requests'])));
    $w = [];
    foreach ($d['booking_requests'] as $r) {
        foreach ($r['windows'] as $i => $win) {
            $w[] = [$r['id'], $i, $win['day'], $win['from_period'], $win['to_period']];
        }
    }
    $n['booking_request_windows'] = cit_insert_rows($db, 'booking_request_windows',
        ['request_id', 'ordinal', 'day', 'from_period', 'to_period'], $w);
    $n['cancellations'] = cit_insert_rows($db, 'cancellations',
        ['request_id', 'cancelled_at', 'cancelled_at_utc', 'cancelled_by', 'note'],
        array_map(fn($c) => [$c['request_id'], $c['cancelled_at'], cit_utc($c['cancelled_at']), $c['by'] ?? '', $c['note'] ?? null],
            $d['cancellations']));
    return $n;
}

/** Store a dataset snapshot as the new current version and reload the mirror. Call inside a transaction. */
function cit_create_dataset_version(PDO $db, string $json, int $importId, ?int $parentId, string $reason, ?int $userId): int
{
    $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $errors = cit_dataset_shape_errors($d);
    if ($errors) {
        throw new InvalidArgumentException("Dataset rejected:\n- " . implode("\n- ", $errors));
    }
    $db->exec('UPDATE dataset_versions SET is_current = 0 WHERE is_current = 1');
    $db->prepare('INSERT INTO dataset_versions (import_id, parent_id, dataset_id, created_reason, snapshot_json, sha256, is_current, created_by)
                  VALUES (?, ?, ?, ?, ?, ?, 1, ?)')
       ->execute([$importId, $parentId, $d['dataset_id'], $reason, $json, hash('sha256', $json), $userId]);
    $id = (int)$db->lastInsertId();
    cit_sync_reference_tables($db, $d);
    return $id;
}

/** Remove everything derived from a previous dataset (timetables, decisions, disruptions, app requests). */
function cit_clear_operational_data(PDO $db): void
{
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach (['schedule_changes', 'reschedule_proposals', 'change_requests', 'disruption_changes', 'disruptions',
                     'session_overrides', 'booking_decisions', 'cancellations', 'booking_request_windows', 'booking_requests',
                     'validation_runs', 'exam_entry_rooms', 'exam_entries', 'schedule_entries', 'schedule_versions',
                     'approval_history'] as $t) {
            $db->exec("DELETE FROM `$t`");
        }
    } finally {
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

/**
 * Full import of a dataset file: replaces the dataset, its booking requests and all
 * operational data. Users, audit log, AI log and RAG chunks are kept.
 * Returns ['import_id', 'dataset_version_id', 'dataset_id', 'counts'].
 */
function cit_import_dataset(PDO $db, string $json, string $sourceName, ?int $userId = null): array
{
    $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $errors = cit_dataset_shape_errors($d);
    if ($errors) {
        throw new InvalidArgumentException("Dataset rejected:\n- " . implode("\n- ", $errors));
    }
    return cit_tx($db, function (PDO $db) use ($json, $d, $sourceName, $userId) {
        cit_clear_operational_data($db);
        $db->prepare('INSERT INTO dataset_imports (dataset_id, source_name, sha256, byte_size, imported_by) VALUES (?, ?, ?, ?, ?)')
           ->execute([$d['dataset_id'], $sourceName, hash('sha256', $json), strlen($json), $userId]);
        $importId = (int)$db->lastInsertId();
        $versionId = cit_create_dataset_version($db, $json, $importId, null, 'import', $userId);
        $counts = cit_load_booking_requests($db, $d);
        $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)')
           ->execute([$userId, 'dataset.import', 'dataset_version', (string)$versionId,
               cit_json(['source' => $sourceName, 'dataset_id' => $d['dataset_id'], 'sha256' => hash('sha256', $json)])]);
        return ['import_id' => $importId, 'dataset_version_id' => $versionId, 'dataset_id' => $d['dataset_id'], 'counts' => $counts];
    });
}

/** The current dataset: exact JSON (for the engine) and decoded array. */
function cit_current_dataset(PDO $db): array
{
    $row = $db->query('SELECT id, snapshot_json FROM dataset_versions WHERE is_current = 1')->fetch();
    if (!$row) {
        throw new RuntimeException('No dataset imported yet. Run php database/reset.php or use Admin > Import.');
    }
    return ['version_id' => (int)$row['id'], 'json' => $row['snapshot_json'],
            'data' => json_decode($row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR)];
}
