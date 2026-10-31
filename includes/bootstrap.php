<?php
// includes/bootstrap.php   [M4]  Included first by every api/*.php endpoint.
//
// - JSON in, JSON out: {"ok":true,"data":...} or {"ok":false,"error":{"code","message",...}}
// - PHP session in the cookie "__session" (the only cookie Firebase Hosting forwards to Cloud Run)
// - CSRF token for every state-changing request (header X-CSRF-Token)
// - role checks are enforced HERE on the server, never only by hiding buttons
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

const CIT_ROLES = ['admin', 'department_coordinator', 'faculty', 'club'];

final class ApiError extends RuntimeException
{
    public function __construct(public string $errCode, string $message, public int $http = 400, public array $extra = [])
    {
        parent::__construct($message);
    }
}

function cit_send(int $http, array $body): never
{
    if (!headers_sent()) {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');      // Firebase Hosting must never cache API answers
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function cit_ok(mixed $data = null): never
{
    cit_send(200, ['ok' => true, 'data' => $data]);
}

function cit_fail(string $code, string $message, int $http = 400, array $extra = []): never
{
    throw new ApiError($code, $message, $http, $extra);
}

set_exception_handler(function (Throwable $e) {
    if ($e instanceof ApiError) {
        cit_send($e->http, ['ok' => false, 'error' => ['code' => $e->errCode, 'message' => $e->getMessage()] + $e->extra]);
    }
    error_log('[cit] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $msg = cit_env('APP_DEBUG') === '1' ? $e->getMessage() : 'Something went wrong on the server. The error has been logged.';
    cit_send(500, ['ok' => false, 'error' => ['code' => 'server_error', 'message' => $msg]]);
});

/** Sessions live in MySQL (php_sessions) so they survive across Cloud Run instances and restarts. */
final class CitDbSession implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false
    {
        try {
            $st = cit_db()->prepare('SELECT data FROM php_sessions WHERE id = ? AND updated_at > ?');
            $st->execute([$id, time() - 8 * 3600]);
            return (string)($st->fetchColumn() ?: '');
        } catch (PDOException) {
            return '';                       // e.g. during a reset, when the table is being rebuilt
        }
    }
    public function write(string $id, string $data): bool
    {
        try {
            cit_db()->prepare('REPLACE INTO php_sessions (id, data, updated_at) VALUES (?, ?, ?)')->execute([$id, $data, time()]);
        } catch (PDOException) {
        }
        return true;
    }
    public function destroy(string $id): bool
    {
        try { cit_db()->prepare('DELETE FROM php_sessions WHERE id = ?')->execute([$id]); } catch (PDOException) {}
        return true;
    }
    public function gc(int $max): int|false
    {
        try { return cit_db()->exec('DELETE FROM php_sessions WHERE updated_at < ' . (time() - 8 * 3600)); } catch (PDOException) { return 0; }
    }
}

function cit_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_save_handler(new CitDbSession(), true);
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('__session');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function cit_method(string ...$allowed): string
{
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($m, $allowed, true)) {
        cit_fail('method_not_allowed', "Use " . implode(' or ', $allowed), 405);
    }
    if ($m !== 'GET') {
        cit_check_csrf();
    }
    return $m;
}

/** Decoded JSON body (or form fields). */
function cit_input(): array
{
    static $in = null;
    if ($in !== null) {
        return $in;
    }
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
        $in = json_decode($raw, true);
        if (!is_array($in)) {
            cit_fail('bad_json', 'The request body is not valid JSON');
        }
    } else {
        $in = $_POST;
    }
    return $in;
}

function cit_str(array $in, string $key, int $max = 500, bool $required = true): ?string
{
    $v = $in[$key] ?? null;
    if ($v === null || $v === '') {
        if ($required) cit_fail('missing_field', "\"$key\" is required");
        return null;
    }
    if (!is_string($v) && !is_int($v)) cit_fail('bad_field', "\"$key\" must be text");
    $v = trim((string)$v);
    if (mb_strlen($v) > $max) cit_fail('bad_field', "\"$key\" is longer than $max characters");
    return $v;
}

function cit_int(array $in, string $key, int $min, int $max, bool $required = true): ?int
{
    $v = $in[$key] ?? null;
    if ($v === null || $v === '') {
        if ($required) cit_fail('missing_field', "\"$key\" is required");
        return null;
    }
    if (filter_var($v, FILTER_VALIDATE_INT) === false || (int)$v < $min || (int)$v > $max) {
        cit_fail('bad_field', "\"$key\" must be a whole number from $min to $max");
    }
    return (int)$v;
}

// ------------------------------------------------------------------ auth
function cit_user(): ?array
{
    cit_session_start();
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = $_SESSION['uid'] ?? null;
    if (!$id) {
        return $user = null;
    }
    $st = cit_db()->prepare('SELECT id, username, display_name, role, requester_name, faculty_id, department FROM users WHERE id = ? AND is_active = 1');
    $st->execute([$id]);
    return $user = ($st->fetch() ?: null);
}

function cit_require_login(): array
{
    $u = cit_user();
    if (!$u) {
        cit_fail('not_logged_in', 'Please log in', 401);
    }
    return $u;
}

function cit_require_role(string ...$roles): array
{
    $u = cit_require_login();
    if (!in_array($u['role'], $roles, true)) {
        cit_fail('forbidden', 'Your role (' . str_replace('_', ' ', $u['role']) . ') cannot do this', 403);
    }
    return $u;
}

function cit_csrf_token(): string
{
    cit_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function cit_check_csrf(): void
{
    cit_session_start();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf']) || !is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
        cit_fail('csrf', 'Your session expired. Reload the page and try again.', 403);
    }
}

/** A user owns a booking request if they created it or it was filed under their requester name. */
function cit_owns_request(array $user, array $req): bool
{
    return (isset($req['created_by']) && (int)$req['created_by'] === (int)$user['id'])
        || ($user['requester_name'] !== null && $req['requester'] === $user['requester_name']);
}

function cit_setting(string $key, mixed $default = null): mixed
{
    $st = cit_db()->prepare('SELECT v FROM app_settings WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : json_decode($v, true);
}
