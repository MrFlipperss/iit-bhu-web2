<?php
// POST {id, action: approve|reject, comment?} -> Admin decision. Approve re-validates, applies permanently, re-decides Spectra.  [M3]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/disruption_store.php';
cit_method('POST');
$u = cit_require_role('admin');
@set_time_limit(70);
$in = cit_input();
cit_ok(cit_decide_proposal(cit_db(), $u, cit_int($in, 'id', 1, PHP_INT_MAX), cit_str($in, 'action', 10), cit_str($in, 'comment', 500, false)));
