<?php
// GET ?session_id=.. | ?request_id=.. -> deterministic explanation (why here / why this decision).  [M1]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/schedule_store.php';
$u = cit_require_login();
$db = cit_db();
$v = cit_require_active($db);
$req = ['command' => 'explain', 'dataset' => cit_version_dataset($db, $v), 'schedule' => cit_build_schedule_doc($db, (int)$v['id'], true, false)];
if (!empty($_GET['session_id'])) $req['session_id'] = cit_str($_GET, 'session_id', 40);
elseif (!empty($_GET['request_id'])) {
    $rid = cit_str($_GET, 'request_id', 32);
    $st = $db->prepare('SELECT * FROM booking_requests WHERE id = ?');
    $st->execute([$rid]);
    $r = $st->fetch();
    if ($r && $u['role'] !== 'admin' && !cit_owns_request($u, $r)) cit_fail('forbidden', 'You can only see your own requests', 403);
    $req['request_id'] = $rid;
} else cit_fail('missing_field', 'Give session_id or request_id');
cit_ok(cit_engine_or_fail($req, 20));
