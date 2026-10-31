<?php
// POST {id, action: confirm|reject, reason?} -> Admin review. Refused on the Admin's own request.  [M2]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/booking_store.php';
cit_method('POST');
$u = cit_require_role('admin');
$in = cit_input();
cit_ok(cit_review_decision(cit_db(), $u, cit_str($in, 'id', 32), cit_str($in, 'action', 10), cit_str($in, 'reason', 300, false)));
