<?php
// GET ?id=.. -> proposal, change file, before/after diff with reasons, validation.  [M3]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/disruption_store.php';
cit_require_login();
cit_ok(cit_proposal(cit_db(), cit_int($_GET, 'id', 1, PHP_INT_MAX)));
