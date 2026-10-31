<?php
// GET -> currently scheduled sessions in the signed-in coordinator's department.
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/schedule_store.php';
$u = cit_require_role('department_coordinator');
if (empty($u['department'])) cit_fail('no_department', 'Your account is not linked to a department', 409);
$db = cit_db();
$active = cit_require_active($db);
$st = $db->prepare('SELECT e.session_id, e.day, e.start_period, e.duration, e.room_id, s.course_id, s.kind, c.title,
                           GROUP_CONCAT(DISTINCT g.group_id ORDER BY g.group_id SEPARATOR ",") AS groups
                    FROM schedule_entries e
                    JOIN sessions s ON s.id = e.session_id
                    JOIN courses c ON c.id = s.course_id
                    LEFT JOIN session_groups g ON g.session_id = e.session_id
                    WHERE e.schedule_version_id = ? AND c.department = ?
                    GROUP BY e.session_id, e.day, e.start_period, e.duration, e.room_id, s.course_id, s.kind, c.title
                    ORDER BY e.day, e.start_period, e.session_id');
$st->execute([$active['id'], $u['department']]);
$rows = $st->fetchAll();
foreach ($rows as &$row) $row['groups'] = $row['groups'] ? explode(',', $row['groups']) : [];
cit_ok(['department' => $u['department'], 'sessions' => $rows]);
