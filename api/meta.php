<?php
// GET -> calendar, rooms, groups, faculty, courses (for selectors and forms).  [M1]
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$u = cit_require_login();
$db = cit_db();
$rooms = $db->query('SELECT id, name, building, type, capacity, features, `accessible`, allowed_uses FROM rooms ORDER BY ordinal')->fetchAll();
foreach ($rooms as &$r) { $r['features'] = json_decode($r['features'], true); $r['allowed_uses'] = json_decode($r['allowed_uses'], true); $r['accessible'] = (bool)$r['accessible']; }
unset($r);
cit_ok([
    'days' => $db->query('SELECT code FROM cal_days WHERE teaching_day = 1 ORDER BY ordinal')->fetchAll(PDO::FETCH_COLUMN),
    'exam_days' => $db->query('SELECT code FROM cal_days WHERE exam_day = 1 ORDER BY ordinal')->fetchAll(PDO::FETCH_COLUMN),
    'periods' => $db->query('SELECT id, TIME_FORMAT(start_time, "%H:%i") AS start, TIME_FORMAT(end_time, "%H:%i") AS end, is_teaching, is_event_only, is_lab_start, lunch_after FROM cal_periods ORDER BY id')->fetchAll(),
    'exam_slots' => $db->query('SELECT code, TIME_FORMAT(start_time, "%H:%i") AS start, TIME_FORMAT(end_time, "%H:%i") AS end FROM exam_slots')->fetchAll(),
    'rooms' => $rooms,
    'groups' => $db->query('SELECT id, name, year, department, size FROM student_groups ORDER BY ordinal')->fetchAll(),
    'faculty' => $db->query('SELECT id, name, department FROM faculty ORDER BY ordinal')->fetchAll(),
    'courses' => $db->query('SELECT id, title, department, year FROM courses ORDER BY ordinal')->fetchAll(),
    'categories' => $db->query('SELECT code, `rank`, bookable FROM priority_categories ORDER BY `rank`')->fetchAll(),
    'role_caps' => $db->query('SELECT role, cap_category FROM role_category_caps')->fetchAll(PDO::FETCH_KEY_PAIR),
    'reference_weekday' => cit_setting('reference_weekday', 'MON'),
]);
