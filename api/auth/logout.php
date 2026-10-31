<?php
// POST -> ends the session.  [M4]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
cit_method('POST');
$u = cit_user();
if ($u) cit_audit((int)$u['id'], 'auth.logout', 'user', (string)$u['id']);
$_SESSION = [];
session_destroy();
cit_ok(['logged_out' => true]);
