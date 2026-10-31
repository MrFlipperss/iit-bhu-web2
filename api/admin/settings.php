<?php
// GET -> settings.  POST {team_name?, reference_weekday?, ai_enabled?, rebuild_knowledge?} (admin).  [M4]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/rag.php';
require __DIR__ . '/../../includes/schedule_store.php';
$m = cit_method('GET', 'POST');
$db = cit_db();
$out = [];
if ($m === 'POST') {
    $u = cit_require_role('admin');
    $in = cit_input();
    $st = $db->prepare('INSERT INTO app_settings (k, v, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by)');
    if (isset($in['team_name'])) $st->execute(['team_name', cit_json(cit_str($in, 'team_name', 120)), $u['id']]);
    if (isset($in['reference_weekday'])) {
        $d = cit_str($in, 'reference_weekday', 3);
        if (!in_array($d, ['MON', 'TUE', 'WED', 'THU', 'FRI'], true)) cit_fail('bad_field', 'reference_weekday must be MON to FRI');
        $st->execute(['reference_weekday', cit_json($d), $u['id']]);
    }
    if (isset($in['ai_enabled'])) $st->execute(['ai_enabled', cit_json((bool)$in['ai_enabled']), $u['id']]);
    if (!empty($in['rebuild_knowledge'])) $out['knowledge'] = cit_rag_ingest($db, (int)$u['id']);

    if (array_key_exists('priority_order', $in)) {
        $submitted = $in['priority_order'];
        if (!is_array($submitted) || !array_is_list($submitted) || array_filter($submitted, fn($c) => !is_string($c))) {
            cit_fail('bad_field', 'priority_order must be a list of booking categories');
        }
        $current = cit_current_dataset($db);
        $bookable = $db->query('SELECT code FROM priority_categories WHERE bookable = 1 ORDER BY `rank`')->fetchAll(PDO::FETCH_COLUMN);
        $givenSorted = $submitted; $expectedSorted = $bookable;
        sort($givenSorted); sort($expectedSorted);
        if ($givenSorted !== $expectedSorted) cit_fail('bad_field', 'Include each bookable category exactly once');
        $fixed = array_values(array_filter($current['data']['priority_order'], fn($c) => !in_array($c, $bookable, true)));
        $newOrder = array_merge($fixed, $submitted);
        $out['priority_changed'] = $newOrder !== $current['data']['priority_order'];
        if ($out['priority_changed']) {
            $current['data']['priority_order'] = $newOrder;
            $json = json_encode($current['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) cit_fail('bad_dataset', 'Could not encode the updated priority order', 500);
            $importId = (int)$db->query('SELECT import_id FROM dataset_versions WHERE id = ' . (int)$current['version_id'])->fetchColumn();
            $active = cit_active_version($db);
            $newVersionId = cit_tx($db, function (PDO $db) use ($json, $importId, $current, $u, $active): int {
                $id = cit_create_dataset_version($db, $json, $importId, (int)$current['version_id'], 'priority_edit', (int)$u['id']);
                $db->prepare("UPDATE reschedule_proposals SET status = 'stale', decision_comment = 'Stale because booking priority order changed' WHERE status = 'pending'")->execute();
                if ($active) {
                    $db->prepare('UPDATE schedule_versions SET dataset_version_id = ? WHERE id = ?')->execute([$id, $active['id']]);
                }
                return $id;
            });
            if ($active) cit_recompute_bookings($db, (int)$active['id'], (int)$u['id']);
            $out['dataset_version_id'] = $newVersionId;
        }
    }
    cit_audit((int)$u['id'], 'admin.settings', null, null, array_diff_key($in, ['rebuild_knowledge' => 1]));
} else {
    cit_require_role('admin');
}
$out['settings'] = array_map(fn($v) => json_decode($v, true), $db->query('SELECT k, v FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR));
$currentDataset = cit_current_dataset($db);
$bookableCodes = array_flip($db->query('SELECT code FROM priority_categories WHERE bookable = 1')->fetchAll(PDO::FETCH_COLUMN));
$out['priority_order'] = array_values(array_filter($currentDataset['data']['priority_order'], fn($c) => isset($bookableCodes[$c])));
$out['knowledge_chunks'] = (int)$db->query('SELECT COUNT(*) FROM kb_chunks')->fetchColumn();
$out['knowledge_embedded'] = (int)$db->query('SELECT COUNT(*) FROM kb_chunks WHERE embedding IS NOT NULL')->fetchColumn();
$out['llm'] = ['configured' => cit_env('LLM_API_KEY') !== null, 'provider' => cit_env('LLM_PROVIDER'), 'model' => cit_env('LLM_MODEL'), 'embeddings' => cit_env('EMBED_MODEL')];
$out['users'] = $db->query('SELECT username, display_name, role, requester_name, faculty_id, department, last_login_at FROM users ORDER BY id')->fetchAll();
cit_ok($out);
