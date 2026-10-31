<?php
// includes/db_reset.php   [M4]
// Rebuilds the database from database/schema.sql, imports the starter dataset and
// seeds the demo accounts. Used by `php database/reset.php` and, later, by the
// Admin "Reset demo data" endpoint (which must check the admin role first).
declare(strict_types=1);

require_once __DIR__ . '/dataset_import.php';
require_once __DIR__ . '/seed_users.php';

/** Run a .sql file whose statements each end with ";" at the end of a line. */
function cit_run_sql_file(PDO $db, string $path): int
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read $path");
    }
    $count = 0;
    $buffer = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) {
            $db->exec($buffer);
            $buffer = '';
            $count++;
        }
    }
    if (trim($buffer) !== '') {
        throw new RuntimeException("Unterminated SQL statement at end of $path");
    }
    return $count;
}

function cit_default_settings(PDO $db): void
{
    $settings = [
        'team_name'         => cit_env('TEAM_NAME', 'CIT Crunch Week Team'),
        // "kal" and "today" in requests resolve against this Week-7 weekday (editable by Admin).
        'reference_weekday' => cit_env('REFERENCE_WEEKDAY', 'MON'),
        'ai_enabled'        => cit_env('LLM_API_KEY') !== null,
    ];
    $stmt = $db->prepare('INSERT INTO app_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    foreach ($settings as $k => $v) {
        $stmt->execute([$k, cit_json($v)]);
    }
}

/**
 * Drop and recreate every table, import the dataset, seed demo users.
 * DDL commits implicitly in MySQL, so this is not one transaction; the import step is.
 */
function cit_reset_database(PDO $db, string $datasetPath, string $demoPassword): array
{
    $json = file_get_contents($datasetPath);
    if ($json === false) {
        throw new RuntimeException("Cannot read dataset $datasetPath");
    }
    // Validate before dropping anything, so a bad file never leaves an empty database.
    $errors = cit_dataset_shape_errors(json_decode($json, true));
    if ($errors) {
        throw new InvalidArgumentException("Dataset rejected:\n- " . implode("\n- ", $errors));
    }
    cit_check_demo_password($demoPassword);

    $statements = cit_run_sql_file($db, cit_path('database/schema.sql'));
    $users = cit_seed_demo_users($db, $demoPassword);
    $import = cit_import_dataset($db, $json, basename($datasetPath), null);
    cit_default_settings($db);
    return ['schema_statements' => $statements, 'users' => $users] + $import;
}
