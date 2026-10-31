<?php
// GET -> latest audit log entries and AI calls (admin).  [M4]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
cit_require_role('admin');
$db = cit_db();
cit_ok([
    'audit' => $db->query('SELECT a.created_at, a.action, a.entity_type, a.entity_id, a.details, u.display_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 100')->fetchAll(),
    'ai' => $db->query('SELECT created_at, feature, provider, model, status, latency_ms, LEFT(input_text, 160) AS input FROM ai_interactions ORDER BY id DESC LIMIT 50')->fetchAll(),
]);
