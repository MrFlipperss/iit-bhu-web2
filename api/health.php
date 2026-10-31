<?php
// GET -> database + engine status (public, for deployment checks).  [M4]
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/python.php';
$db = 'down';
try { cit_db()->query('SELECT 1'); $db = 'ok'; } catch (Throwable) {}
$engine = cit_engine(['command' => 'ping'], 15);
cit_ok(['database' => $db, 'engine' => $engine['ok'] ? $engine : 'down']);
