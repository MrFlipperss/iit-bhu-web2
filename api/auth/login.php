<?php
// POST {username, password} -> user + csrf token.  [M4]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') cit_fail('method_not_allowed', 'Use POST', 405);
cit_session_start();
$in = cit_input();
$username = mb_strtolower(cit_str($in, 'username', 120));
$password = (string)($in['password'] ?? '');
// simple brute-force brake per session
$_SESSION['fails'] = $_SESSION['fails'] ?? 0;
if ($_SESSION['fails'] >= 8) cit_fail('too_many_attempts', 'Too many failed attempts. Wait a minute and reload.', 429);
$st = cit_db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
$st->execute([$username]);
$u = $st->fetch();
if (!$u || !password_verify($password, $u['password_hash'])) {
    $_SESSION['fails']++;
    cit_audit(null, 'auth.login_failed', 'user', $username);
    cit_fail('bad_credentials', 'Wrong username or password', 401);
}
session_regenerate_id(true);
$_SESSION['uid'] = (int)$u['id'];
$_SESSION['fails'] = 0;
unset($_SESSION['csrf']);
cit_db()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$u['id']]);
cit_audit((int)$u['id'], 'auth.login', 'user', (string)$u['id']);
cit_ok(['user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'display_name' => $u['display_name'], 'role' => $u['role'],
    'faculty_id' => $u['faculty_id'], 'department' => $u['department'], 'requester_name' => $u['requester_name']], 'csrf' => cit_csrf_token()]);
