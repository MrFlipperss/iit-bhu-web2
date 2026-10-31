<?php
// database/tests/test_db.php
// Usage: CIT_ALLOW_DESTRUCTIVE_TESTS=1 php database/tests/test_db.php
// WIPES the configured database, then checks: import counts, a lossless round trip
// against cit_public.json, constraint rejections, views, a disruption re-sync made with
// the official validator's apply_disruption(), bad-file rejection and demo users.
declare(strict_types=1);

require_once __DIR__ . '/../../includes/db_reset.php';

if (cit_env('CIT_ALLOW_DESTRUCTIVE_TESTS') !== '1') {
    fwrite(STDERR, "Refusing to run: set CIT_ALLOW_DESTRUCTIVE_TESTS=1 (this wipes DB " . cit_env('DB_NAME') . ")\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . ($ok || $detail === '' ? '' : "  -> $detail") . PHP_EOL;
}
function rejects(PDO $db, string $name, string $sql, array $params = []): void
{
    try {
        $db->prepare($sql)->execute($params);
        check("rejects: $name", false, 'statement was accepted');
    } catch (PDOException $e) {
        check("rejects: $name", true);
    }
}
/** Recursively sort object keys so two structures compare by value (lists keep their order). */
function canon(mixed $v): mixed
{
    if (!is_array($v)) return $v;
    $v = array_map('canon', $v);
    if (!array_is_list($v)) ksort($v);
    return $v;
}
function same(mixed $a, mixed $b): bool { return canon($a) === canon($b); }
function first_diff(array $a, array $b): string
{
    foreach ($a as $i => $row) {
        if (!same($row, $b[$i] ?? null)) return json_encode($row) . "\n      vs " . json_encode($b[$i] ?? null);
    }
    return count($a) . ' vs ' . count($b) . ' rows';
}
$J = fn(?string $s) => $s === null ? null : json_decode($s, true);

$db = cit_db();
$datasetPath = cit_path('data/starter/cit_public.json');
$raw = file_get_contents($datasetPath);
$d = json_decode($raw, true);

echo "Reset\n";
$r = cit_reset_database($db, $datasetPath, (string)cit_env('DEMO_PASSWORD'));
check('reset completes', $r['dataset_version_id'] === 1);

echo "Counts match the dataset\n";
$expect = [
    'rooms' => count($d['rooms']), 'room_unavailability' => count($d['room_unavailability']),
    'faculty' => count($d['faculty']), 'student_groups' => count($d['student_groups']),
    'courses' => count($d['courses']), 'sessions' => count($d['sessions']), 'exams' => count($d['exams']),
    'booking_requests' => count($d['booking_requests']), 'cancellations' => count($d['cancellations']),
    'session_groups' => array_sum(array_map(fn($s) => count($s['groups']), $d['sessions'])),
    'faculty_unavailability' => array_sum(array_map(fn($f) => count($f['unavailable']), $d['faculty'])),
    'priority_categories' => count($d['priority_order']), 'cal_periods' => count($d['calendar']['periods']),
];
foreach ($expect as $t => $n) {
    $got = (int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    check(sprintf('%-22s %d', $t, $n), $got === $n, "got $got");
}
check('BR-04 marked cancelled', $db->query("SELECT state FROM booking_requests WHERE id='BR-04'")->fetchColumn() === 'cancelled');

echo "Snapshot is byte-identical\n";
$snap = $db->query('SELECT snapshot_json, sha256 FROM dataset_versions WHERE is_current = 1')->fetch();
check('snapshot bytes equal file', $snap['snapshot_json'] === $raw);
check('sha256 matches', $snap['sha256'] === hash('sha256', $raw));

echo "Lossless round trip (tables -> dataset JSON)\n";
$rows = fn(string $sql) => $db->query($sql)->fetchAll();
$by = function (array $list, string $key): array { $o = []; foreach ($list as $x) $o[$x[$key]][] = $x; return $o; };

$rooms = array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'building' => $r['building'], 'type' => $r['type'],
    'capacity' => $r['capacity'], 'features' => $J($r['features']), 'accessible' => (bool)$r['accessible'],
    'allowed_uses' => $J($r['allowed_uses'])], $rows('SELECT * FROM rooms ORDER BY ordinal'));
check('rooms', same($rooms, $d['rooms']), first_diff($rooms, $d['rooms']));

$ru = array_map(fn($u) => ['room_id' => $u['room_id'], 'day' => $u['day'], 'periods' => $J($u['periods']), 'reason' => $u['reason']],
    $rows('SELECT * FROM room_unavailability ORDER BY ordinal'));
check('room_unavailability', same($ru, $d['room_unavailability']), first_diff($ru, $d['room_unavailability']));

$fu = $by($rows('SELECT * FROM faculty_unavailability ORDER BY faculty_id, ordinal'), 'faculty_id');
$fac = array_map(fn($f) => ['id' => $f['id'], 'name' => $f['name'], 'department' => $f['department'],
    'max_hours_per_day' => $f['max_hours_per_day'], 'needs_accessible' => (bool)$f['needs_accessible'],
    'unavailable' => array_map(fn($u) => ['day' => $u['day'], 'periods' => $J($u['periods']), 'reason' => $u['reason']], $fu[$f['id']] ?? []),
    'preferences' => $J($f['preferences'])], $rows('SELECT * FROM faculty ORDER BY ordinal'));
check('faculty (incl. unavailable, preferences)', same($fac, $d['faculty']), first_diff($fac, $d['faculty']));

$grp = array_map(fn($g) => ['id' => $g['id'], 'name' => $g['name'], 'year' => $g['year'], 'department' => $g['department'],
    'size' => $g['size'], 'needs_accessible' => (bool)$g['needs_accessible']], $rows('SELECT * FROM student_groups ORDER BY ordinal'));
check('student_groups', same($grp, $d['student_groups']), first_diff($grp, $d['student_groups']));

$crs = array_map(fn($c) => array_diff_key($c, ['ordinal' => 1]), $rows('SELECT * FROM courses ORDER BY ordinal'));
check('courses', same($crs, $d['courses']), first_diff($crs, $d['courses']));

$sg = $by($rows('SELECT * FROM session_groups ORDER BY session_id, ordinal'), 'session_id');
$ses = array_map(fn($s) => ['id' => $s['id'], 'course_id' => $s['course_id'], 'kind' => $s['kind'],
    'groups' => array_column($sg[$s['id']] ?? [], 'group_id'), 'faculty_id' => $s['faculty_id'], 'size' => $s['size'],
    'duration' => $s['duration'], 'room_types' => $J($s['room_types']), 'needs_accessible' => (bool)$s['needs_accessible'],
    'basket_slot' => $s['basket_slot'],
    'fixed_slot' => $s['fixed_day'] === null ? null : ['day' => $s['fixed_day'], 'start_period' => $s['fixed_start_period']]],
    $rows('SELECT * FROM sessions ORDER BY ordinal'));
check('sessions (incl. groups order, baskets)', same($ses, $d['sessions']), first_diff($ses, $d['sessions']));

$ec = $by($rows('SELECT * FROM exam_courses ORDER BY exam_id, ordinal'), 'exam_id');
$eg = $by($rows('SELECT * FROM exam_groups ORDER BY exam_id, ordinal'), 'exam_id');
$ex = array_map(fn($e) => ['id' => $e['id'], 'course_ids' => array_column($ec[$e['id']], 'course_id'),
    'groups' => array_column($eg[$e['id']], 'group_id'), 'size' => $e['size'], 'duration_hours' => $e['duration_hours']],
    $rows('SELECT * FROM exams ORDER BY ordinal'));
check('exams', same($ex, $d['exams']), first_diff($ex, $d['exams']));

$win = $by($rows('SELECT * FROM booking_request_windows ORDER BY request_id, ordinal'), 'request_id');
$br = array_map(fn($b) => ['id' => $b['id'], 'submitted_at' => $b['submitted_at'], 'requester' => $b['requester'],
    'role' => $b['role'], 'department' => $b['department'], 'requested_category' => $b['requested_category'],
    'title' => $b['title'], 'attendance' => $b['attendance'], 'duration_periods' => $b['duration_periods'],
    'windows' => array_map(fn($w) => ['day' => $w['day'], 'from_period' => $w['from_period'], 'to_period' => $w['to_period']], $win[$b['id']]),
    'room_types' => $J($b['room_types']), 'needs' => $J($b['needs']), 'requested_room' => $b['requested_room'],
    'mandatory_for_groups' => $J($b['mandatory_for_groups']), 'series_id' => $b['series_id'], 'raw_text' => $b['raw_text']],
    $rows("SELECT * FROM booking_requests WHERE source='dataset' ORDER BY ordinal"));
check('booking_requests (incl. P13-P14 window of BR-17, LHC-5 wish of BR-14)', same($br, $d['booking_requests']), first_diff($br, $d['booking_requests']));

$can = array_map(fn($c) => ['request_id' => $c['request_id'], 'cancelled_at' => $c['cancelled_at'], 'by' => $c['cancelled_by'], 'note' => $c['note']],
    $rows('SELECT * FROM cancellations'));
check('cancellations', same($can, $d['cancellations']));
check('priority_order', array_column($rows('SELECT code FROM priority_categories ORDER BY `rank`'), 'code') === $d['priority_order']);
check('role_category_cap', same(array_column($rows('SELECT * FROM role_category_caps'), 'cap_category', 'role'), $d['role_category_cap']));
$per = array_map(fn($p) => ['id' => $p['id'], 'start' => substr($p['start_time'], 0, 5), 'end' => substr($p['end_time'], 0, 5),
    'teaching' => (bool)$p['is_teaching']], $rows('SELECT * FROM cal_periods ORDER BY id'));
check('calendar periods', same($per, $d['calendar']['periods']));
check('lab starts / lunch / event-only flags',
    array_map('intval', array_column($rows('SELECT id FROM cal_periods WHERE is_lab_start=1 ORDER BY id'), 'id')) === $d['calendar']['lab_start_periods']
    && (int)$db->query('SELECT id FROM cal_periods WHERE lunch_after=1')->fetchColumn() === $d['calendar']['lunch']['after_period']
    && array_map('intval', array_column($rows('SELECT id FROM cal_periods WHERE is_event_only=1 ORDER BY id'), 'id')) === $d['calendar']['event_only_periods']);

echo "Schedule data + views\n";
$db->exec("INSERT INTO schedule_versions (dataset_version_id, kind, status, team_name) VALUES (1, 'generated', 'active', 'Test')");
$sv = (int)$db->lastInsertId();
$lab = $db->query("SELECT id, duration FROM sessions WHERE kind='lab' ORDER BY ordinal LIMIT 1")->fetch();
$lec = $db->query("SELECT id FROM sessions WHERE kind='lecture' ORDER BY ordinal LIMIT 1")->fetchColumn();
$ins = $db->prepare('INSERT INTO schedule_entries (schedule_version_id, session_id, day, start_period, room_id, duration) VALUES (?,?,?,?,?,?)');
$ins->execute([$sv, $lab['id'], 'MON', 2, 'CS-LAB-1', $lab['duration']]);
$ins->execute([$sv, $lec, 'TUE', 3, 'LH-1', 1]);
check('v_session_slots expands a 3-period lab', (int)$db->query("SELECT COUNT(*) FROM v_session_slots WHERE session_id=" . $db->quote($lab['id']))->fetchColumn() === 3);
$gcount = (int)$db->query("SELECT COUNT(*) FROM session_groups WHERE session_id=" . $db->quote($lec))->fetchColumn();
check('v_group_slots: one row per group', (int)$db->query("SELECT COUNT(*) FROM v_group_slots WHERE session_id=" . $db->quote($lec))->fetchColumn() === $gcount);
check('v_room_utilization CS-LAB-1 = 3/45', (float)$db->query("SELECT utilization_pct FROM v_room_utilization WHERE room_id='CS-LAB-1'")->fetchColumn() === 6.7);
$ruSlots = array_sum(array_map(fn($u) => count($u['periods']), $d['room_unavailability']));
check("v_room_unavailable_slots = $ruSlots", (int)$db->query('SELECT COUNT(*) FROM v_room_unavailable_slots')->fetchColumn() === $ruSlots);
$fuSlots = array_sum(array_map(fn($f) => array_sum(array_map(fn($u) => count($u['periods']), $f['unavailable'])), $d['faculty']));
check("v_faculty_unavailable_slots = $fuSlots", (int)$db->query('SELECT COUNT(*) FROM v_faculty_unavailable_slots')->fetchColumn() === $fuSlots);

$dec = $db->prepare('INSERT INTO booking_decisions (schedule_version_id, request_id, status, day, start_period, end_period, room_id, reason, effective_category) VALUES (?,?,?,?,?,?,?,?,?)');
$dec->execute([$sv, 'BR-07', 'approved', 'THU', 10, 12, 'SH-1', '', 'club_event']);
$dec->execute([$sv, 'BR-09', 'rejected', null, null, null, null, 'No venue seats 1,200', 'club_event']);
check('v_booking_slots: approved P10-P12 = 3 slots', (int)$db->query('SELECT COUNT(*) FROM v_booking_slots')->fetchColumn() === 3);
check('v_pending_approvals lists both decisions', (int)$db->query("SELECT COUNT(*) FROM v_pending_approvals WHERE item_type='booking_decision'")->fetchColumn() === 2);
$db->exec("INSERT INTO exam_entries VALUES ($sv, 'EX-MA101', 'MON', 'AM')");
$db->exec("INSERT INTO exam_entry_rooms VALUES ($sv, 'EX-MA101', 'AUD', 'MON', 'AM', 0)");

echo "Constraints reject bad data\n";
rejects($db, 'a second active timetable', "INSERT INTO schedule_versions (dataset_version_id, kind, status, team_name) VALUES (1,'generated','active','x')");
rejects($db, 'a lab running past P9', 'INSERT INTO schedule_entries VALUES (?,?,?,?,?,?)', [$sv, 'X-1', 'WED', 8, 'LH-2', 3]);
rejects($db, 'a teaching session in P10', 'INSERT INTO schedule_entries VALUES (?,?,?,?,?,?)', [$sv, 'X-2', 'WED', 10, 'LH-2', 1]);
rejects($db, 'a Saturday class', 'INSERT INTO schedule_entries VALUES (?,?,?,?,?,?)', [$sv, 'X-3', 'SAT', 2, 'LH-2', 1]);
rejects($db, 'an unknown room', 'INSERT INTO schedule_entries VALUES (?,?,?,?,?,?)', [$sv, 'X-4', 'WED', 2, 'LHC-5', 1]);
$db->exec("INSERT INTO exam_entries VALUES ($sv,'EX-PH101','MON','AM')");   // a second exam in the slot is fine...
rejects($db, 'one room, two exams, same slot', "INSERT INTO exam_entry_rooms VALUES ($sv,'EX-PH101','AUD','MON','AM',0)");   // ...in the same room is not
$db->exec("DELETE FROM exam_entries WHERE schedule_version_id=$sv AND exam_id='EX-PH101'");
rejects($db, 'two decisions for one request', $dec->queryString, [$sv, 'BR-07', 'rejected', null, null, null, null, 'dup', 'club_event']);
rejects($db, 'a rejection that holds a room', $dec->queryString, [$sv, 'BR-06', 'rejected', 'THU', 10, 11, 'SH-1', 'x', 'club_event']);
rejects($db, 'an alternative without a reason', $dec->queryString, [$sv, 'BR-06', 'alternative_offered', 'THU', 10, 11, 'AUD', ' ', 'club_event']);
rejects($db, 'a booking ending at P14', $dec->queryString, [$sv, 'BR-17', 'alternative_offered', 'WED', 13, 14, 'OAT', 'x', 'club_event']);
$db->exec("INSERT INTO disruptions (raw_json, sha256, base_dataset_version_id) VALUES ('{\"changes\":[]}', REPEAT('0',64), 1)");
$dis = (int)$db->lastInsertId();
rejects($db, 'an unknown disruption change type', 'INSERT INTO disruption_changes VALUES (?,?,?,?,?)', [$dis, 0, 'swap_rooms', null, '{}']);
rejects($db, 'a proposal with no origin', "INSERT INTO reschedule_proposals (baseline_version_id) VALUES ($sv)");
$db->exec("INSERT INTO reschedule_proposals (disruption_id, baseline_version_id) VALUES ($dis, $sv)");
$prop = (int)$db->lastInsertId();
rejects($db, 'an "added" diff row with a from-slot', 'INSERT INTO schedule_changes (proposal_id, session_id, change_kind, from_day, to_day) VALUES (?,?,?,?,?)', [$prop, 'CS202-T2', 'added', 'MON', 'TUE']);
rejects($db, 'invalid JSON as a snapshot', "INSERT INTO dataset_versions (import_id, dataset_id, created_reason, snapshot_json, sha256) VALUES (1,'x','import','{bad', REPEAT('0',64))");

echo "Unicode (Hinglish / Devanagari)\n";
$txt = 'seminar hall kal shaam 6 baje, सेमिनार हॉल, 100 log';
$db->prepare("INSERT INTO booking_requests (id, source, submitted_at, submitted_at_utc, requester, role, requested_category, title, attendance, duration_periods, room_types, needs, mandatory_for_groups, raw_text, ordinal)
              VALUES ('APP-0001','app','2027-02-05T10:00:00+05:30','2027-02-05 04:30:00','Quiz Club','club','club_event','Test',100,2,'[\"seminar_hall\"]','[\"pa_system\"]','[]',?,0)")->execute([$txt]);
check('raw_text round-trips', $db->query("SELECT raw_text FROM booking_requests WHERE id='APP-0001'")->fetchColumn() === $txt);

echo "Disruption re-sync (dataset built by the official validator's apply_disruption)\n";
$py = ['python3', '-I', '-c',
    'import sys,json; sys.path.insert(0, sys.argv[1]); import validator as v; '
  . 'd=v.load(sys.argv[2]); x=v.apply_disruption(d, v.load(sys.argv[3])); sys.stdout.write(json.dumps(x, ensure_ascii=False))',
    cit_path('validator'), $datasetPath, cit_path('data/starter/examples/sample_disruption.json')];
$proc = proc_open($py, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);   // argument array: no shell
$after = stream_get_contents($pipes[1]); $perr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
check('python apply_disruption ran', proc_close($proc) === 0 && $after !== '', $perr);
$v2 = cit_tx($db, fn($db) => cit_create_dataset_version($db, $after, 1, 1, 'disruption', null));
check('new version is current', (int)$db->query('SELECT id FROM dataset_versions WHERE is_current=1')->fetchColumn() === $v2);
check('only one current version', (int)$db->query('SELECT COUNT(*) FROM dataset_versions WHERE is_current=1')->fetchColumn() === 1);
check('190 sessions (CS202-T2 added)', (int)$db->query('SELECT COUNT(*) FROM sessions')->fetchColumn() === 190);
check('CR-102 closed MON-FRI (5 rows)', (int)$db->query("SELECT COUNT(*) FROM room_unavailability WHERE room_id='CR-102'")->fetchColumn() === 5);
check('F07 unavailable all Monday', (int)$db->query("SELECT COUNT(*) FROM v_faculty_unavailable_slots WHERE faculty_id='F07' AND day='MON'")->fetchColumn() === 9);
check('booking requests untouched', (int)$db->query('SELECT COUNT(*) FROM booking_requests')->fetchColumn() === 26);
check('existing schedule rows still valid', (int)$db->query('SELECT COUNT(*) FROM schedule_entries e LEFT JOIN rooms r ON r.id=e.room_id WHERE r.id IS NULL')->fetchColumn() === 0
    && (int)$db->query("SELECT COUNT(*) FROM schedule_entries WHERE schedule_version_id=$sv")->fetchColumn() === 2);

echo "Bad files are rejected without changing anything\n";
$bad = $d; $bad['sessions'][0]['faculty_id'] = 'F99'; unset($bad['exams'][0]['size']);
$errs = cit_dataset_shape_errors($bad);
check('unknown faculty and missing field reported', count($errs) === 1 && str_contains($errs[0], 'size'),
    implode(' | ', $errs));   // shape errors are reported first; references after they are fixed
unset($bad['exams']); $bad['exams'] = $d['exams'];
check('reference error reported', (bool)array_filter(cit_dataset_shape_errors($bad), fn($e) => str_contains($e, 'F99')));
try { cit_import_dataset($db, json_encode($bad), 'bad.json'); check('bad import throws', false); }
catch (InvalidArgumentException $e) { check('bad import throws', true); }
check('current dataset unchanged', (int)$db->query('SELECT id FROM dataset_versions WHERE is_current=1')->fetchColumn() === $v2);

echo "Demo users\n";
$users = $rows('SELECT username, role, password_hash FROM users');
check('5 users across 4 roles', count($users) === 5 && count(array_unique(array_column($users, 'role'))) === 4);
check('passwords hashed and verifiable', !array_filter($users, fn($u) => !password_verify((string)cit_env('DEMO_PASSWORD'), $u['password_hash'])
    || str_contains($u['password_hash'], (string)cit_env('DEMO_PASSWORD'))));

echo "\nFinal reset to a clean demo state\n";
cit_reset_database($db, $datasetPath, (string)cit_env('DEMO_PASSWORD'));
check('clean state', (int)$db->query('SELECT COUNT(*) FROM schedule_versions')->fetchColumn() === 0);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
