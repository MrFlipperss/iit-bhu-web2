<?php
// rag/ingest.php   Usage: php rag/ingest.php   (rebuilds kb_chunks; also available in Admin > Settings)
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/rag.php';
$r = cit_rag_ingest(cit_db());
echo "Knowledge index: {$r['chunks']} chunks, {$r['embedded']} embedded ({$r['mode']})\n";
