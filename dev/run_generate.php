<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/schedule_store.php';

echo "Starting timetable generation via engine...\n";
$db = cit_db();
$adminUser = ['id' => 1, 'email' => 'dean@cit.demo', 'role' => 'admin'];

$res = cit_generate($db, $adminUser, false);
echo "Generation complete!\n";
echo "Version ID: " . ($res['version_id'] ?? 'unknown') . "\n";
echo "Activated: " . ($res['activated'] ? 'YES' : 'NO') . "\n";
echo "Hard Violations: " . ($res['hard_violations'] ?? 0) . "\n";
echo "Soft Total: " . ($res['soft_total'] ?? 0) . "\n";
echo "Seconds: " . ($res['seconds'] ?? 0) . "\n";
