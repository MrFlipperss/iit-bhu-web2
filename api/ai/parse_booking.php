<?php
// POST {text} -> editable structured booking + assumptions + follow-up question + room warnings. Never books.  [M4]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/booking_parser.php';
cit_method('POST');
$u = cit_require_login();
cit_ok(cit_parse_booking(cit_db(), cit_str(cit_input(), 'text', 1000), $u));
