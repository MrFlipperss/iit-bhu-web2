<?php
// POST {disruption: <object> | text: "<json>"} -> checks the change file, runs the minimum-move repair, stores a pending proposal. Admin only.  [M3]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/disruption_store.php';
cit_method('POST');
$u = cit_require_role('admin');
@set_time_limit(70);
$in = cit_input();
$json = isset($in['disruption']) && is_array($in['disruption']) ? json_encode($in['disruption'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : cit_str($in, 'text', 500000);
cit_ok(cit_propose_disruption(cit_db(), $u, $json));
