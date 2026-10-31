<?php
// GET ?view=group|faculty|room&id=..&week=regular|fest&version=.. -> timetable entries for the grid.  [M1]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/schedule_store.php';
$u = cit_require_login();
$db = cit_db();
$view = $_GET['view'] ?? 'group';
if (!in_array($view, ['group', 'faculty', 'room'], true)) cit_fail('bad_field', 'view must be group, faculty or room');
$id = cit_str($_GET, 'id', 32);
$fest = ($_GET['week'] ?? 'regular') === 'fest';
$v = isset($_GET['version']) ? cit_version($db, (int)cit_int($_GET, 'version', 1, PHP_INT_MAX)) : cit_require_active($db);
$vid = (int)$v['id'];
$entries = $db->prepare('SELECT e.session_id, e.day, e.start_period, e.duration, e.room_id, s.course_id, s.kind, s.faculty_id, s.size, s.basket_slot,
                         c.title AS course_title, f.name AS faculty_name,
                         (SELECT GROUP_CONCAT(group_id ORDER BY ordinal) FROM session_groups g WHERE g.session_id = e.session_id) AS `groups`,
                         o.day AS o_day, o.start_period AS o_start, o.room_id AS o_room, o.displaced_by
                         FROM schedule_entries e LEFT JOIN sessions s ON s.id = e.session_id LEFT JOIN courses c ON c.id = s.course_id
                         LEFT JOIN faculty f ON f.id = s.faculty_id
                         LEFT JOIN session_overrides o ON o.schedule_version_id = e.schedule_version_id AND o.session_id = e.session_id
                         WHERE e.schedule_version_id = ?');
$entries->execute([$vid]);
$rows = [];
foreach ($entries as $r) {
    $r['groups'] = $r['groups'] ? explode(',', $r['groups']) : [];
    $r['moved_for_spectra'] = null;
    if ($fest && $r['o_day']) {
        $r['moved_for_spectra'] = ['from' => [$r['day'], (int)$r['start_period'], $r['room_id']], 'by' => $r['displaced_by']];
        [$r['day'], $r['start_period'], $r['room_id']] = [$r['o_day'], $r['o_start'], $r['o_room']];
    }
    unset($r['o_day'], $r['o_start'], $r['o_room'], $r['displaced_by']);
    $match = ($view === 'group' && in_array($id, $r['groups'], true)) || ($view === 'faculty' && $r['faculty_id'] === $id) || ($view === 'room' && $r['room_id'] === $id);
    if ($match) $rows[] = $r;
}
$events = [];
if ($fest && $view === 'room') {
    $st = $db->prepare("SELECT d.request_id, d.status, d.day, d.start_period, d.end_period, b.title, b.requester FROM booking_decisions d
                        JOIN booking_requests b ON b.id = d.request_id WHERE d.schedule_version_id = ? AND d.room_id = ? AND d.status <> 'rejected'");
    $st->execute([$vid, $id]);
    $events = $st->fetchAll();
}
$closures = [];
if ($view === 'room') {
    $st = $db->prepare('SELECT day, periods, reason FROM room_unavailability WHERE room_id = ?');
    $st->execute([$id]);
    foreach ($st as $c) $closures[] = ['day' => $c['day'], 'periods' => json_decode($c['periods'], true), 'reason' => $c['reason']];
}
$unavail = [];
if ($view === 'faculty') {
    $st = $db->prepare('SELECT day, periods, reason FROM faculty_unavailability WHERE faculty_id = ?');
    $st->execute([$id]);
    foreach ($st as $c) $unavail[] = ['day' => $c['day'], 'periods' => json_decode($c['periods'], true),
        'reason' => ($u['role'] === 'admin' || $u['faculty_id'] === $id) ? $c['reason'] : 'Unavailable'];   // reasons are personal
}
cit_ok(['version' => ['id' => $vid, 'status' => $v['status'], 'created_at' => $v['created_at'], 'soft_total' => $v['soft_total'],
        'hard_violation_count' => $v['hard_violation_count']], 'view' => $view, 'id' => $id, 'week' => $fest ? 'fest' : 'regular',
        'entries' => $rows, 'events' => $events, 'closures' => $closures, 'unavailable' => $unavail]);
