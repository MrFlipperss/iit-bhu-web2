<?php
// POST {text: "<dataset json>", name?} -> replaces the dataset (and all timetables/decisions). Admin only.  [M4]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/dataset_import.php';
require __DIR__ . '/../../includes/rag.php';
cit_method('POST');
$u = cit_require_role('admin');
$in = cit_input();
$json = cit_str($in, 'text', 20000000);
try {
    $res = cit_import_dataset(cit_db(), $json, cit_str($in, 'name', 200, false) ?? 'upload.json', (int)$u['id']);
} catch (InvalidArgumentException | JsonException $e) {
    cit_fail('invalid_dataset', 'The dataset was not imported', 422, ['problems' => array_values(array_filter(array_map('trim', explode("\n- ", $e->getMessage())), fn($l) => $l !== '' && !str_starts_with($l, 'Dataset rejected')))]);
}
$res['knowledge'] = cit_rag_ingest(cit_db(), (int)$u['id']);
cit_ok($res);
