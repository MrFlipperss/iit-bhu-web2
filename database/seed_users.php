<?php
// database/seed_users.php
// Usage:  php database/seed_users.php [password]
// Inserts or updates all users (Admins, Coordinators, Clubs, Faculty)
// into the database without altering timetable or booking data.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/seed_users.php';

$pw = $argv[1] ?? (string)cit_env('DEMO_PASSWORD', 'Kian@2407');
try {
    $seeded = cit_seed_demo_users(cit_db(), $pw);
    printf("Successfully seeded %d user accounts into database '%s'.\n", count($seeded), cit_env('DB_NAME', 'cit_scheduler'));
    echo "Default password configured: " . $pw . "\n";
    echo "\nSample Accounts:\n";
    echo "  - Admin:       dean@cit.demo, spectra@cit.demo\n";
    echo "  - Coordinator: coord.ece@cit.demo, coord.cse@cit.demo, tnp@cit.demo\n";
    echo "  - Club:        dramatics@cit.demo, coding@cit.demo, dance@cit.demo\n";
    echo "  - Faculty:     f01@cit.demo .. f40@cit.demo (e.g. f12@cit.demo)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'User seeding failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
