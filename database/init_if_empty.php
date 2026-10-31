<?php
// database/init_if_empty.php  Used by docker/entrypoint.sh.
// Exit 0 when the database is ready (initialising it on the first start), 1 when MySQL is unreachable.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/db_reset.php';
require __DIR__ . '/../includes/rag.php';
try {
    $db = cit_db();
} catch (Throwable $e) {
    fwrite(STDERR, 'MySQL not ready: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
$has = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchColumn();
if ((int)$has === 0) {
    echo "Empty database: creating schema, importing the starter pack, seeding demo users\n";
    cit_reset_database($db, cit_path('data/starter/cit_public.json'), (string)cit_env('DEMO_PASSWORD', ''));
    $r = cit_rag_ingest($db);
    echo "Knowledge index: {$r['chunks']} passages ({$r['mode']})\n";
}
exit(0);
