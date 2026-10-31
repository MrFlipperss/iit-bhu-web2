<?php
// GET ?status=..&q=.. -> requests with their current decision. Admin sees all; others see their own.  [M2]
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/schedule_store.php';
$u = cit_require_login();
$db = cit_db();
$active = cit_active_version($db);
$sql = 'SELECT b.id, b.source, b.submitted_at, b.requester, b.role, b.requested_category, b.title, b.attendance, b.duration_periods, b.series_id,
        b.state, b.requested_room, b.created_by, d.status, d.day, d.start_period, d.end_period, d.room_id, d.reason, d.effective_category, d.review_state,
        (SELECT GROUP_CONCAT(CONCAT(w.day, " P", w.from_period, "-P", w.to_period) ORDER BY w.ordinal SEPARATOR ", ") FROM booking_request_windows w WHERE w.request_id = b.id) AS windows
        FROM booking_requests b LEFT JOIN booking_decisions d ON d.request_id = b.id AND d.schedule_version_id = ?';
$args = [$active['id'] ?? 0];
$where = [];
if ($u['role'] !== 'admin') { $where[] = '(b.created_by = ? OR b.requester = ?)'; $args[] = $u['id']; $args[] = $u['requester_name'] ?? ''; }
$status = $_GET['status'] ?? '';
if (in_array($status, ['approved', 'rejected', 'alternative_offered'], true)) { $where[] = 'd.status = ?'; $args[] = $status; }
if ($status === 'to_review') $where[] = "d.review_state = 'proposed'";
if ($status === 'cancelled') $where[] = "b.state = 'cancelled'";
if (!empty($_GET['q'])) { $where[] = '(b.id LIKE ? OR b.title LIKE ? OR b.requester LIKE ?)'; $q = '%' . cit_str($_GET, 'q', 80) . '%'; array_push($args, $q, $q, $q); }
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY b.submitted_at_utc';
$st = $db->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();
foreach ($rows as &$r) { $r['is_mine'] = cit_owns_request($u, $r); unset($r['created_by']); }
cit_ok(['version_id' => $active['id'] ?? null, 'requests' => $rows]);
