<?php
// GET -> current user + csrf token (401 if not logged in).  [M4]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
$u = cit_require_login();
cit_ok(['user' => $u, 'csrf' => cit_csrf_token(), 'ai_available' => cit_env('LLM_API_KEY') !== null && (bool)cit_setting('ai_enabled', true)]);
