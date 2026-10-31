<?php
// GET ?kind=schedule|after&version=.. downloads a timetable export.
// POST {kind, version?} saves the export on the server (Admin only, CSRF protected).
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/schedule_store.php';

$method = cit_method('GET', 'POST');
$db = cit_db();
$build = static function (string $kind, ?int $versionId) use ($db): array {
    if (!in_array($kind, ['schedule', 'after'], true)) cit_fail('bad_field', 'kind must be schedule or after');
    $v = $versionId !== null ? cit_version($db, $versionId) : cit_require_active($db);
    if ($kind === 'after') {
        if ($v['kind'] !== 'repair' || !in_array($v['status'], ['active', 'superseded'], true)) {
            cit_fail('not_a_repair', 'schedule_after.json comes from an approved repair. Pick the repaired version.', 409);
        }
        $doc = cit_build_schedule_doc($db, (int)$v['id'], false, false);
        $file = 'schedule_after.json';
    } else {
        $doc = cit_build_schedule_doc($db, (int)$v['id'], true, true);
        $file = 'schedule.json';
    }
    $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) cit_fail('export_encode_failed', 'The timetable could not be encoded as JSON', 500);
    return [$v, $file, $json];
};

if ($method === 'POST') {
    $u = cit_require_role('admin');
    $in = cit_input();
    $kind = cit_str($in, 'kind', 16, false) ?? 'schedule';
    $versionId = isset($in['version']) ? cit_int($in, 'version', 1, PHP_INT_MAX) : null;
    [$v, $file, $json] = $build($kind, $versionId);
    $dir = cit_path('exports');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        cit_fail('export_write_failed', 'The exports directory could not be created', 500);
    }
    $tmp = @tempnam($dir, '.cit-export-');
    if ($tmp === false) cit_fail('export_write_failed', 'A temporary export file could not be created', 500);
    $contents = $json . "\n";
    $written = @file_put_contents($tmp, $contents, LOCK_EX);
    if ($written !== strlen($contents) || !@rename($tmp, $dir . DIRECTORY_SEPARATOR . $file)) {
        @unlink($tmp);
        cit_fail('export_write_failed', "Could not write $file to the server", 500);
    }
    cit_audit((int)$u['id'], 'schedule.export', 'schedule_version', (string)$v['id'], ['file' => $file, 'saved' => true]);
    cit_ok(['saved' => true, 'file' => $file, 'version_id' => (int)$v['id']]);
}

$u = cit_require_login();
if (array_key_exists('save', $_GET)) cit_fail('method_not_allowed', 'Use POST with a CSRF token to save an export', 405);
$kind = cit_str($_GET, 'kind', 16, false) ?? 'schedule';
$versionId = isset($_GET['version']) ? cit_int($_GET, 'version', 1, PHP_INT_MAX) : null;
[$v, $file, $json] = $build($kind, $versionId);
cit_audit((int)$u['id'], 'schedule.export', 'schedule_version', (string)$v['id'], ['file' => $file]);
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Cache-Control: private, no-store');
echo $json;
