<?php
// GET ?day=THU&from=10&to=12&attendance=100&types=seminar_hall,auditorium&needs=pa_system
//  -> rooms that suit the request and whether each is free in Spectra week (classes + bookings + closures).  [M2]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/schedule_store.php';
cit_require_login();
$db = cit_db();
$v = cit_require_active($db);
$day = cit_str($_GET, 'day', 3);
$from = cit_int($_GET, 'from', 1, 13);
$to = cit_int($_GET, 'to', $from, 13);
$att = cit_int($_GET, 'attendance', 0, 5000, false) ?? 0;
$types = array_filter(explode(',', $_GET['types'] ?? ''));
$needs = array_filter(explode(',', $_GET['needs'] ?? ''));
$busy = [];
$st = $db->prepare('SELECT e.room_id, e.session_id, e.start_period, e.duration, o.day AS od, o.start_period AS op, o.room_id AS orm
                    FROM schedule_entries e LEFT JOIN session_overrides o ON o.schedule_version_id = e.schedule_version_id AND o.session_id = e.session_id
                    WHERE e.schedule_version_id = ?');
$st->execute([$v['id']]);
foreach ($st as $r) {
    [$d, $s, $rm] = $r['od'] ? [$r['od'], (int)$r['op'], $r['orm']] : [null, (int)$r['start_period'], $r['room_id']];
    $dd = $r['od'] ?: null;
    $busy[] = ['room' => $rm, 'day' => $dd, 'sid' => $r['session_id'], 'from' => $s, 'to' => $s + $r['duration'] - 1];
}
$byDay = $db->prepare('SELECT session_id, day FROM schedule_entries WHERE schedule_version_id = ?');
$byDay->execute([$v['id']]);
$days = $byDay->fetchAll(PDO::FETCH_KEY_PAIR);
$out = [];
foreach ($db->query('SELECT * FROM rooms ORDER BY capacity, id') as $r) {
    $feats = json_decode($r['features'], true);
    if ($r['accessible']) $feats[] = 'accessible';
    if (!in_array('event', json_decode($r['allowed_uses'], true), true)) continue;
    if ($types && !in_array($r['type'], $types, true)) continue;
    $problems = [];
    if ($r['capacity'] < $att) $problems[] = "seats {$r['capacity']}";
    $miss = array_diff($needs, $feats);
    if ($miss) $problems[] = 'no ' . implode(', ', $miss);
    $taken = [];
    foreach ($busy as $b) {
        if ($b['room'] === $r['id'] && ($b['day'] ?? $days[$b['sid']]) === $day && $b['from'] <= $to && $b['to'] >= $from) $taken[] = "class {$b['sid']}";
    }
    $st = $db->prepare("SELECT request_id FROM booking_decisions WHERE schedule_version_id = ? AND room_id = ? AND day = ? AND status <> 'rejected' AND start_period <= ? AND end_period >= ?");
    $st->execute([$v['id'], $r['id'], $day, $to, $from]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $q) $taken[] = "booking $q";
    $st = $db->prepare('SELECT periods, reason FROM room_unavailability WHERE room_id = ? AND day = ?');
    $st->execute([$r['id'], $day]);
    foreach ($st as $c) if (array_intersect(json_decode($c['periods'], true), range($from, $to))) $taken[] = 'closed: ' . $c['reason'];
    $out[] = ['room_id' => $r['id'], 'name' => $r['name'], 'type' => $r['type'], 'capacity' => (int)$r['capacity'], 'suitable' => !$problems,
              'problems' => $problems, 'free' => !$taken, 'taken_by' => $taken];
}
cit_ok(['day' => $day, 'from' => $from, 'to' => $to, 'rooms' => $out]);
