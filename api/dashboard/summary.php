<?php
// GET -> one call for the dashboard: timetable health, pending approvals, conflicts, utilisation.  [M4]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/schedule_store.php';
$u = cit_require_login();
$db = cit_db();
$v = cit_active_version($db);
$val = null;
if ($v) {
    $st = $db->prepare('SELECT hard_violation_count, hard_by_rule, soft_total, soft_penalties, exam_soft_penalty, booking_score, priority_inversions, report_json, created_at
                        FROM validation_runs WHERE schedule_version_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$v['id']]);
    $val = $st->fetch() ?: null;
    if ($val) {
        $rep = json_decode($val['report_json'], true);
        $val['violations'] = array_slice($rep['hard_violations'] ?? [], 0, 10);
        $val['bookings'] = $rep['bookings'] ?? null;
        $val['soft_penalties'] = json_decode($val['soft_penalties'], true);
        unset($val['report_json'], $val['hard_by_rule']);
    }
}
$counts = $v ? $db->query("SELECT status, COUNT(*) n FROM booking_decisions WHERE schedule_version_id = {$v['id']} GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$pending = $u['role'] === 'admin' ? $db->query('SELECT * FROM v_pending_approvals ORDER BY created_at LIMIT 30')->fetchAll() : [];
$mine = [];
if ($u['role'] !== 'admin') {
    $st = $db->prepare('SELECT b.id, b.title, b.state, d.status, d.room_id, d.day, d.start_period, d.end_period, d.reason FROM booking_requests b
                        LEFT JOIN booking_decisions d ON d.request_id = b.id AND d.schedule_version_id = ? WHERE b.created_by = ? OR b.requester = ? ORDER BY b.submitted_at_utc DESC LIMIT 10');
    $st->execute([$v['id'] ?? 0, $u['id'], $u['requester_name'] ?? '']);
    $mine = $st->fetchAll();
}
$util = $db->query('SELECT room_id, name, type, capacity, used_periods, teaching_periods, utilization_pct FROM v_room_utilization ORDER BY utilization_pct DESC')->fetchAll();
$closures = $db->query('SELECT room_id, day, periods, reason FROM room_unavailability ORDER BY room_id, FIELD(day, "MON","TUE","WED","THU","FRI")')->fetchAll();
foreach ($closures as &$c) $c['periods'] = json_decode($c['periods'], true);
unset($c);
cit_ok([
    'version' => $v ? ['id' => (int)$v['id'], 'kind' => $v['kind'], 'created_at' => $v['created_at'], 'activated_at' => $v['activated_at'], 'solve_seconds' => $v['solve_seconds']] : null,
    'validation' => $val,
    'decision_counts' => $counts,
    'pending' => $pending,
    'my_requests' => $mine,
    'utilization' => $util,
    'closures' => $closures,
    'proposals_pending' => (int)$db->query("SELECT COUNT(*) FROM reschedule_proposals WHERE status = 'pending'")->fetchColumn(),
    'dataset' => $db->query('SELECT v.id, v.dataset_id, v.created_reason, v.created_at FROM dataset_versions v WHERE is_current = 1')->fetch() ?: null,
]);
