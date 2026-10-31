<?php
// POST {text, preview?: bool, changes?: [...]} -> faculty report of times they cannot teach.  [M3]
// preview=true: parse only. Otherwise the (confirmed) changes become a repair proposal for an Admin to approve.
// A faculty member can only report their OWN unavailability.
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/disruption_store.php';
require __DIR__ . '/../../includes/change_parser.php';
cit_method('POST');
$u = cit_require_role('faculty');
if (!$u['faculty_id']) cit_fail('no_faculty_id', 'Your account is not linked to a faculty ID', 409);
$db = cit_db();
$in = cit_input();
$text = cit_str($in, 'text', 1000);
if (!empty($in['preview'])) cit_ok(cit_parse_unavailability($db, $text, $u));
$changes = $in['changes'] ?? cit_parse_unavailability($db, $text, $u)['changes'];
if (!is_array($changes) || !$changes) cit_fail('nothing_to_change', 'Say which days and times you cannot teach');
foreach ($changes as $c) {
    if (($c['type'] ?? '') !== 'faculty_unavailable' || ($c['faculty_id'] ?? '') !== $u['faculty_id']) cit_fail('forbidden', 'You can only report your own unavailability', 403);
}
$db->prepare("INSERT INTO change_requests (requested_by, raw_text, parsed, state) VALUES (?, ?, ?, 'submitted')")->execute([$u['id'], $text, cit_json($changes)]);
$crid = (int)$db->lastInsertId();
$json = cit_json(['id' => "change-request-$crid", 'dataset' => cit_current_dataset($db)['data']['dataset_id'], 'description' => "Reported by {$u['display_name']}: $text", 'changes' => $changes]);
@set_time_limit(70);
$res = cit_propose_disruption($db, $u, $json, $crid);
$db->prepare('UPDATE change_requests SET state = ? WHERE id = ?')->execute([$res['status'] === 'pending' ? 'proposed' : 'infeasible', $crid]);
cit_audit((int)$u['id'], 'change.request', 'change_request', (string)$crid);
cit_ok($res + ['change_request_id' => $crid]);
