<?php
// GET ?version=.. -> latest validator report.  POST {version_id} -> run the official validator again (admin).  [M1]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/schedule_store.php';
$m = cit_method('GET', 'POST');
$db = cit_db();
if ($m === 'POST') {
    $u = cit_require_role('admin');
    $v = cit_version($db, cit_int(cit_input(), 'version_id', 1, PHP_INT_MAX));
    $res = cit_engine_or_fail(['command' => 'validate', 'dataset' => cit_version_dataset($db, $v), 'schedule' => cit_build_schedule_doc($db, (int)$v['id'])], 30);
    cit_store_validation($db, (int)$v['id'], $res['report']);
    cit_audit((int)$u['id'], 'schedule.validate', 'schedule_version', (string)$v['id'], ['hard' => $res['report']['hard_violation_count']]);
} else {
    cit_require_login();
    $v = isset($_GET['version']) ? cit_version($db, (int)cit_int($_GET, 'version', 1, PHP_INT_MAX)) : cit_require_active($db);
}
$st = $db->prepare('SELECT id, mode, hard_violation_count, soft_total, booking_score, sessions_moved, report_json, validator_sha256, created_at
                    FROM validation_runs WHERE schedule_version_id = ? ORDER BY id DESC LIMIT 1');
$st->execute([$v['id']]);
$r = $st->fetch();
if ($r) { $r['report'] = json_decode($r['report_json'], true); unset($r['report_json']); }
cit_ok(['version_id' => (int)$v['id'], 'run' => $r ?: null]);
