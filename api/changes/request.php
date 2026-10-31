<?php
// POST {text, preview?: bool, changes?: [...]} -> faculty unavailability request.
// Faculty may POST {action: extra|move, session_id, reason, day?, period?}; coordinators may POST {session_id, reason}.
// preview=true: parse only. Otherwise the (confirmed) changes become a repair proposal for an Admin to approve.
// Faculty can only report their OWN unavailability; coordinators are scoped to their department.
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/disruption_store.php';
require __DIR__ . '/../../includes/change_parser.php';
cit_method('POST');
$u = cit_require_role('faculty', 'department_coordinator');
$db = cit_db();
$in = cit_input();

if ($u['role'] === 'department_coordinator') {
    if (empty($u['department'])) cit_fail('no_department', 'Your account is not linked to a department', 409);
    $sessionId = cit_str($in, 'session_id', 64);
    $reason = cit_str($in, 'reason', 500);
    $data = cit_current_dataset($db)['data'];
    $session = null;
    foreach ($data['sessions'] as $candidate) if ($candidate['id'] === $sessionId) { $session = $candidate; break; }
    if (!$session) cit_fail('not_found', "There is no session $sessionId", 404);
    $course = null;
    foreach ($data['courses'] as $candidate) if ($candidate['id'] === $session['course_id']) { $course = $candidate; break; }
    if (!$course || $course['department'] !== $u['department']) {
        cit_fail('forbidden', 'You can request changes only to sessions in your department', 403);
    }
    $active = cit_require_active($db);
    $st = $db->prepare('SELECT COUNT(*) FROM schedule_entries WHERE schedule_version_id = ? AND session_id = ?');
    $st->execute([$active['id'], $sessionId]);
    if (!(int)$st->fetchColumn()) cit_fail('not_scheduled', 'That session is not on the active timetable', 409);

    $text = "Department coordinator request for $sessionId: $reason";
    $changes = [['type' => 'remove_session', 'session_id' => $sessionId, 'reason' => $reason]];
    $db->prepare("INSERT INTO change_requests (requested_by, raw_text, parsed, state) VALUES (?, ?, ?, 'submitted')")
       ->execute([$u['id'], $text, cit_json($changes)]);
    $crid = (int)$db->lastInsertId();
    $disruption = ['id' => "department-change-$crid", 'dataset' => $data['dataset_id'],
        'description' => $text, 'changes' => $changes];
    @set_time_limit(70);
    $res = cit_propose_disruption($db, $u, cit_json($disruption), $crid);
    $db->prepare('UPDATE change_requests SET state = ? WHERE id = ?')
       ->execute([$res['status'] === 'pending' ? 'proposed' : 'infeasible', $crid]);
    cit_audit((int)$u['id'], 'change.request', 'change_request', (string)$crid, ['session_id' => $sessionId]);
    cit_ok($res + ['change_request_id' => $crid]);
}

if (!$u['faculty_id']) cit_fail('no_faculty_id', 'Your account is not linked to a faculty ID', 409);
if (isset($in['action'])) {
    $action = cit_str($in, 'action', 10);
    if (!in_array($action, ['extra', 'move'], true)) cit_fail('bad_field', 'action must be extra or move');
    $sessionId = cit_str($in, 'session_id', 32);
    $reason = cit_str($in, 'reason', 500);
    $data = cit_current_dataset($db)['data'];
    $base = null;
    foreach ($data['sessions'] as $candidate) if ($candidate['id'] === $sessionId) { $base = $candidate; break; }
    if (!$base) cit_fail('not_found', "There is no session $sessionId", 404);
    if ($base['faculty_id'] !== $u['faculty_id']) cit_fail('forbidden', 'You may request changes only to your own sessions', 403);
    $active = cit_require_active($db);
    $st = $db->prepare('SELECT COUNT(*) FROM schedule_entries WHERE schedule_version_id = ? AND session_id = ?');
    $st->execute([$active['id'], $sessionId]);
    if (!(int)$st->fetchColumn()) cit_fail('not_scheduled', 'That session is not on the active timetable', 409);

    $day = cit_str($in, 'day', 3, false);
    $period = isset($in['period']) ? cit_int($in, 'period', 1, 9) : null;
    if (($day === null) !== ($period === null)) cit_fail('bad_field', 'Give both a day and period, or neither');
    if ($day !== null && !in_array($day, $data['calendar']['days'], true)) cit_fail('bad_field', 'Choose a teaching day from the calendar');
    if ($action === 'move' && $day === null) cit_fail('missing_field', 'Choose the requested day and period for the move');
    if ($action === 'move' && !empty($base['basket_slot'])) {
        cit_fail('coordinated_session', 'This elective-basket session must be moved with its full basket by an Admin', 409);
    }

    $newSession = $base;
    do { $newSession['id'] = 'REQ-' . strtoupper(bin2hex(random_bytes(6))); }
    while (in_array($newSession['id'], array_column($data['sessions'], 'id'), true));
    $newSession['fixed_slot'] = $day === null ? null : ['day' => $day, 'start_period' => $period];
    if ($action === 'extra') $newSession['basket_slot'] = null;
    $changes = [];
    if ($action === 'move') $changes[] = ['type' => 'remove_session', 'session_id' => $sessionId, 'reason' => $reason];
    $changes[] = ['type' => 'add_session', 'session' => $newSession, 'reason' => $reason];
    $text = $action === 'move'
        ? "Faculty {$u['faculty_id']} requests moving $sessionId to $day P$period: $reason"
        : "Faculty {$u['faculty_id']} requests an extra session based on $sessionId" . ($day ? " at $day P$period" : '') . ": $reason";
    $db->prepare("INSERT INTO change_requests (requested_by, raw_text, parsed, state) VALUES (?, ?, ?, 'submitted')")
       ->execute([$u['id'], $text, cit_json($changes)]);
    $crid = (int)$db->lastInsertId();
    $disruption = ['id' => "faculty-session-change-$crid", 'dataset' => $data['dataset_id'],
        'description' => $text, 'changes' => $changes];
    @set_time_limit(70);
    $res = cit_propose_disruption($db, $u, cit_json($disruption), $crid);
    $db->prepare('UPDATE change_requests SET state = ? WHERE id = ?')
       ->execute([$res['status'] === 'pending' ? 'proposed' : 'infeasible', $crid]);
    cit_audit((int)$u['id'], 'change.request', 'change_request', (string)$crid, ['action' => $action, 'session_id' => $sessionId]);
    cit_ok($res + ['change_request_id' => $crid]);
}

$text = cit_str($in, 'text', 1000);
if (!empty($in['preview'])) cit_ok(cit_parse_unavailability($db, $text, $u));
$changes = $in['changes'] ?? cit_parse_unavailability($db, $text, $u)['changes'];
if (!is_array($changes) || !$changes) cit_fail('nothing_to_change', 'Say which days and times you cannot teach');
foreach ($changes as $c) {
    if (($c['type'] ?? '') !== 'faculty_unavailable' || ($c['faculty_id'] ?? '') !== $u['faculty_id']) cit_fail('forbidden', 'You can only report your own unavailability', 403);
}
$db->prepare("INSERT INTO change_requests (requested_by, raw_text, parsed, state) VALUES (?, ?, ?, 'submitted')")->execute([$u['id'], $text, cit_json($changes)]);
$crid = (int)$db->lastInsertId();
$json = cit_json(['id' => "change-request-$crid", 'dataset' => cit_current_dataset($db)['data']['dataset_id'], 'description' => "Reported by {$u['display_name']}: $text", 'changes' => $changes]);
@set_time_limit(70);
$res = cit_propose_disruption($db, $u, $json, $crid);
$db->prepare('UPDATE change_requests SET state = ? WHERE id = ?')->execute([$res['status'] === 'pending' ? 'proposed' : 'infeasible', $crid]);
cit_audit((int)$u['id'], 'change.request', 'change_request', (string)$crid);
cit_ok($res + ['change_request_id' => $crid]);
