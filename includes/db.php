<?php
// includes/db.php
// One PDO connection per request. Real prepared statements, exceptions on error,
// strict SQL mode, utf8mb4, UTC timestamps.
//
// Supports TCP (DB_HOST/DB_PORT), a Unix socket (DB_SOCKET, e.g. Cloud SQL on
// Cloud Run: /cloudsql/PROJECT:REGION:INSTANCE) and TLS (DB_SSL_CA) for managed MySQL.
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function cit_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $name = cit_env('DB_NAME', 'cit_scheduler');
    $socket = cit_env('DB_SOCKET');
    $dsn = $socket
        ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $name)
        : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            cit_env('DB_HOST', '127.0.0.1'), cit_env('DB_PORT', '3306'), $name);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];
    $ca = cit_env('DB_SSL_CA');
    if ($ca) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
    }

    $pdo = new PDO($dsn, cit_env('DB_USER', 'cit'), cit_env('DB_PASSWORD', ''), $options);
    // Same collation as the tables, whatever the server default is (avoids "Illegal mix of collations").
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");
    return $pdo;
}

/** json_encode that keeps Hindi/Hinglish text readable and fails loudly. */
function cit_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** Run $fn inside a transaction; roll back on any exception. */
function cit_tx(PDO $db, callable $fn): mixed
{
    $db->beginTransaction();
    try {
        $result = $fn($db);
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
