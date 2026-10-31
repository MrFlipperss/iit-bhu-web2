<?php
// GET -> versions with their latest validation.  POST {version_id} -> activate (admin, only if 0 hard violations).  [M1]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/schedule_store.php';
$m = cit_method('GET', 'POST');
$db = cit_db();
if ($m === 'POST') {
    $u = cit_require_role('admin');
    $v = cit_version($db, cit_int(cit_input(), 'version_id', 1, PHP_INT_MAX));
    if ($v['kind'] === 'repair' && $v['status'] === 'draft') cit_fail('use_proposal', 'Repairs are activated by approving their proposal on the Reschedule page', 409);
    if ((int)$v['hard_violation_count'] !== 0) cit_fail('invalid', 'Only a version with 0 hard violations can be made active', 409);
    $cur = cit_current_dataset($db);
    if ((int)$v['dataset_version_id'] !== $cur['version_id']) cit_fail('stale', 'This version was made for older data (before a change was applied)', 409);
    cit_tx($db, fn($db) => cit_activate($db, (int)$v['id'], (int)$u['id']));
    cit_audit((int)$u['id'], 'schedule.activate', 'schedule_version', (string)$v['id']);
}
cit_require_login();
cit_ok($db->query('SELECT v.id, v.kind, v.status, v.parent_id, v.hard_violation_count, v.soft_total, v.booking_score, v.solve_seconds, v.note,
                   v.created_at, u.display_name AS created_by FROM schedule_versions v LEFT JOIN users u ON u.id = v.created_by ORDER BY v.id DESC LIMIT 50')->fetchAll());
