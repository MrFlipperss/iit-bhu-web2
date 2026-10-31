<?php
// GET ?group=.. -> exam plan of the active version (rooms, seats at half capacity, groups).  [M2]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/schedule_store.php';
cit_require_login();
$db = cit_db();
$v = cit_require_active($db);
$st = $db->prepare('SELECT e.exam_id, e.day, e.session, x.size, x.duration_hours,
                    (SELECT GROUP_CONCAT(course_id ORDER BY ordinal) FROM exam_courses c WHERE c.exam_id = e.exam_id) AS courses,
                    (SELECT GROUP_CONCAT(group_id ORDER BY ordinal) FROM exam_groups g WHERE g.exam_id = e.exam_id) AS `groups`,
                    (SELECT GROUP_CONCAT(r.room_id ORDER BY r.ordinal) FROM exam_entry_rooms r WHERE r.schedule_version_id = e.schedule_version_id AND r.exam_id = e.exam_id) AS rooms,
                    (SELECT SUM(FLOOR(m.capacity / 2)) FROM exam_entry_rooms r JOIN rooms m ON m.id = r.room_id WHERE r.schedule_version_id = e.schedule_version_id AND r.exam_id = e.exam_id) AS seats
                    FROM exam_entries e JOIN exams x ON x.id = e.exam_id JOIN cal_days d ON d.code = e.day
                    WHERE e.schedule_version_id = ? ORDER BY d.ordinal, e.session, e.exam_id');
$st->execute([$v['id']]);
$rows = [];
$g = $_GET['group'] ?? null;
foreach ($st as $r) {
    foreach (['courses', 'groups', 'rooms'] as $k) $r[$k] = $r[$k] ? explode(',', $r[$k]) : [];
    $r['seats'] = (int)$r['seats'];
    if ($g && !in_array($g, $r['groups'], true)) continue;
    $rows[] = $r;
}
cit_ok(['version_id' => (int)$v['id'], 'exams' => $rows]);
