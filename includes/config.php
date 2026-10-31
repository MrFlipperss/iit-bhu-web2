<?php
// includes/config.php
// Reads settings from real environment variables first, then from a .env file
// in the project root (local development). No secret is ever written in code.
declare(strict_types=1);

const CIT_ROOT = __DIR__ . '/..';

function cit_load_env_file(string $path): array
{
    $vars = [];
    if (!is_readable($path)) {
        return $vars;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $vars[$key] = $value;
    }
    return $vars;
}

function cit_env(string $key, ?string $default = null): ?string
{
    static $file = null;
    if ($file === null) {
        $file = cit_load_env_file(CIT_ROOT . '/.env');
    }
    $real = getenv($key);
    if ($real !== false && $real !== '') {
        return $real;
    }
    return array_key_exists($key, $file) && $file[$key] !== '' ? $file[$key] : $default;
}

function cit_path(string $relative): string
{
    return CIT_ROOT . '/' . ltrim($relative, '/');
}
