<?php
// includes/audit.php   [M4]
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function cit_audit(?int $userId, string $action, ?string $entityType = null, ?string $entityId = null, array $details = []): void
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip) {
        $ip = substr(trim(explode(',', $ip)[0]), 0, 45);
    }
    cit_db()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $action, $entityType, $entityId, $details ? cit_json($details) : null, $ip]);
}

function cit_approval(string $entityType, string $entityId, string $action, ?int $userId, ?string $comment = null): void
{
    cit_db()->prepare('INSERT INTO approval_history (entity_type, entity_id, action, actor_user_id, comment) VALUES (?, ?, ?, ?, ?)')
        ->execute([$entityType, $entityId, $action, $userId, $comment]);
}
