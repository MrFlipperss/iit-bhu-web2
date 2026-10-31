<?php
// POST structured booking (manual form or confirmed AI form) -> request(s) + the engine's decision.  [M2]
// Role and requester come from the session, never from the form. The engine applies the role cap.
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/booking_store.php';
cit_method('POST');
$u = cit_require_login();
@set_time_limit(60);
cit_ok(cit_create_requests(cit_db(), $u, cit_input()));
