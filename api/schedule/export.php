<?php
// GET ?kind=schedule|after&version=..&save=1 -> downloads schedule.json / schedule_after.json built from the stored rows.  [M1]
// save=1 (admin) also writes exports/<file> on the server.
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/schedule_store.php';
$u = cit_require_login();
$db = cit_db();
$kind = $_GET['kind'] ?? 'schedule';
$v = isset($_GET['version']) ? cit_version($db, (int)cit_int($_GET, 'version', 1, PHP_INT_MAX)) : cit_require_active($db);
if ($kind === 'after') {
    if ($v['kind'] !== 'repair') cit_fail('not_a_repair', 'schedule_after.json comes from an approved repair. Pick the repaired version.', 409);
    $doc = cit_build_schedule_doc($db, (int)$v['id'], false, false);
    $file = 'schedule_after.json';
} else {
    $doc = cit_build_schedule_doc($db, (int)$v['id'], true, true);
    $file = 'schedule.json';
}
$json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!empty($_GET['save']) && $u['role'] === 'admin') {
    @mkdir(cit_path('exports'), 0775, true);
    file_put_contents(cit_path('exports/' . $file), $json . "\n");
}
cit_audit((int)$u['id'], 'schedule.export', 'schedule_version', (string)$v['id'], ['file' => $file]);
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Cache-Control: private, no-store');
echo $json;
