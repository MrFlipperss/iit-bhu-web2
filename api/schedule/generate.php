<?php
// POST {improve?: bool} -> generates timetable + Spectra decisions + exams, validates, activates if valid. Admin only.  [M1]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/schedule_store.php';
cit_method('POST');
$u = cit_require_role('admin');
@set_time_limit(70);
cit_ok(cit_generate(cit_db(), $u, !empty(cit_input()['improve'])));
