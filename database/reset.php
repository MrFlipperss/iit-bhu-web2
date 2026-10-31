<?php
// database/reset.php
// Usage:  php database/reset.php [path/to/dataset.json]
// Drops and recreates all tables, imports the dataset (default: the starter pack)
// and seeds demo accounts using DEMO_PASSWORD from the environment or .env.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db_reset.php';

$dataset = $argv[1] ?? cit_path('data/starter/cit_public.json');
try {
    $result = cit_reset_database(cit_db(), $dataset, (string)cit_env('DEMO_PASSWORD', ''));
} catch (Throwable $e) {
    fwrite(STDERR, 'Reset failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

printf("Schema: %d statements\n", $result['schema_statements']);
printf("Dataset: %s (version %d)\n", $result['dataset_id'], $result['dataset_version_id']);
foreach ($result['counts'] as $table => $n) {
    printf("  %-24s %d\n", $table, $n);
}
echo 'Demo users: ' . implode(', ', $result['users']) . PHP_EOL;
