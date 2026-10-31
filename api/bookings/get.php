<?php
// GET ?id=BR-07 -> full request with windows, decision, history.  [M2]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/booking_store.php';
$u = cit_require_login();
cit_ok(cit_request_detail(cit_db(), cit_str($_GET, 'id', 32), $u));
