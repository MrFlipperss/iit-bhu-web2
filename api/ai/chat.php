<?php
// POST {message, history?: [{role, content}]} -> reply, intent, sources, optional action.  [M4]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/chat.php';
cit_method('POST');
$u = cit_require_login();
$in = cit_input();
$hist = is_array($in['history'] ?? null) ? array_slice($in['history'], -8) : [];
cit_ok(cit_chat(cit_db(), $u, cit_str($in, 'message', 1000), $hist));
