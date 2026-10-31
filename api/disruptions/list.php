<?php
// GET -> proposals with status and size.  [M3]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
cit_require_login();
cit_ok(cit_db()->query('SELECT p.id, p.status, p.sessions_moved, p.time_changed, p.room_only_changed, p.created_at, p.decided_at,
        d.external_id, d.description, (SELECT COUNT(*) FROM disruption_changes c WHERE c.disruption_id = p.disruption_id) AS change_count,
        p.change_request_id, u.display_name AS created_by
        FROM reschedule_proposals p LEFT JOIN disruptions d ON d.id = p.disruption_id LEFT JOIN users u ON u.id = p.created_by ORDER BY p.id DESC LIMIT 50')->fetchAll());
