<?php
// GET -> settings.  POST {team_name?, reference_weekday?, ai_enabled?, rebuild_knowledge?} (admin).  [M4]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/rag.php';
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
    cit_audit((int)$u['id'], 'admin.settings', null, null, array_diff_key($in, ['rebuild_knowledge' => 1]));
} else {
    cit_require_role('admin');
}
$out['settings'] = array_map(fn($v) => json_decode($v, true), $db->query('SELECT k, v FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR));
$out['knowledge_chunks'] = (int)$db->query('SELECT COUNT(*) FROM kb_chunks')->fetchColumn();
$out['knowledge_embedded'] = (int)$db->query('SELECT COUNT(*) FROM kb_chunks WHERE embedding IS NOT NULL')->fetchColumn();
$out['llm'] = ['configured' => cit_env('LLM_API_KEY') !== null, 'provider' => cit_env('LLM_PROVIDER'), 'model' => cit_env('LLM_MODEL'), 'embeddings' => cit_env('EMBED_MODEL')];
$out['users'] = $db->query('SELECT username, display_name, role, requester_name, faculty_id, department, last_login_at FROM users ORDER BY id')->fetchAll();
cit_ok($out);
