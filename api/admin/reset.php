<?php
// POST -> rebuilds the database from the starter dataset (demo reset). Admin only.  [M4]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/db_reset.php';
require __DIR__ . '/../../includes/rag.php';
cit_method('POST');
$u = cit_require_role('admin');
$name = $u['username'];
$pw = (string)cit_env('DEMO_PASSWORD', '');
$res = cit_reset_database(cit_db(), cit_path('data/starter/cit_public.json'), $pw);
$rag = cit_rag_ingest(cit_db());
// the session's user id may have changed: log the admin back in by username
$st = cit_db()->prepare('SELECT id FROM users WHERE username = ?');
$st->execute([$name]);
$_SESSION['uid'] = (int)$st->fetchColumn();
cit_audit($_SESSION['uid'], 'admin.reset', 'dataset_version', (string)$res['dataset_version_id']);
cit_ok(['dataset_version_id' => $res['dataset_version_id'], 'counts' => $res['counts'], 'knowledge' => $rag, 'next' => 'Generate the timetable']);
