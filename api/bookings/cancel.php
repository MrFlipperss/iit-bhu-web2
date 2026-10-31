<?php
// POST {id, note?} -> cancels (requester or admin). A cancelled request is never approved.  [M2]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/booking_store.php';
cit_method('POST');
$u = cit_require_login();
$in = cit_input();
$id = cit_str($in, 'id', 32);
cit_cancel_request(cit_db(), $u, $id, cit_str($in, 'note', 300, false));
cit_ok(cit_request_detail(cit_db(), $id, $u));
