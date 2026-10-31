<?php
// includes/python.php   [M4]  The only place PHP starts Python.
//
// Security: a fixed interpreter and a fixed script, started with an argument ARRAY (no shell,
// nothing from the browser reaches the command line). All input goes as JSON on stdin.
// A hard timeout kills runaway solves; Firebase Hosting cuts requests at 60 s.
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const CIT_ENGINE_COMMANDS = ['ping', 'generate_all', 'timetable', 'bookings', 'exams', 'check_disruption',
    'apply_disruption', 'repair', 'validate', 'explain'];

function cit_engine(array $request, int $timeoutSeconds = 55): array
{
    if (!in_array($request['command'] ?? '', CIT_ENGINE_COMMANDS, true)) {
        throw new InvalidArgumentException('Unknown engine command');
    }
    $python = cit_env('PYTHON_BIN', 'python3');
    if (!str_contains($python, ':') && !str_starts_with($python, '/') && !str_starts_with($python, '\\')) {
        $python = cit_path($python);
    }
    $cmd = [$python, '-I', cit_path('engine/cli.py')];     // -I: ignore env vars / user site
    $env = array_filter(array_merge($_SERVER, [
        'PATH' => getenv('PATH') ?: '',
        'SYSTEMROOT' => getenv('SYSTEMROOT') ?: 'C:\\Windows',
        'TEMP' => getenv('TEMP') ?: 'C:\\Windows\\Temp',
        'LANG' => 'C.UTF-8',
        'PYTHONIOENCODING' => 'utf-8',
    ]), 'is_string');
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, cit_path('engine'), $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start the scheduling engine');
    }
    $payload = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $len = strlen($payload);
    $written = 0;
    while ($written < $len) {
        $n = fwrite($pipes[0], substr($payload, $written, 65536));
        if ($n === false) {
            break;
        }
        $written += $n;
    }
    fclose($pipes[0]);

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);
    if (trim($err) !== '') {
        error_log('[cit engine] ' . substr($err, -2000));
    }
    $res = json_decode($out, true);
    if (!is_array($res) || !isset($res['ok'])) {
        return ['ok' => false, 'error' => ['code' => 'engine_crash',
            'message' => 'The scheduling engine stopped unexpectedly. Details are in the server log.']];
    }
    return $res;
}

/** cit_engine() that turns engine errors into API errors (status 422) with the engine's details. */
function cit_engine_or_fail(array $request, int $timeoutSeconds = 55): array
{
    $res = cit_engine($request, $timeoutSeconds);
    if (!$res['ok']) {
        $e = $res['error'];
        $code = $e['code'] ?? 'engine_error';
        $msg = $e['message'] ?? 'Engine error';
        unset($e['code'], $e['message']);
        throw new ApiError($code, $msg, in_array($code, ['engine_timeout', 'engine_crash'], true) ? 503 : 422, $e);
    }
    return $res;
}
