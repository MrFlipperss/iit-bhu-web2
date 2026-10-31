<?php
// includes/booking_store.php   [M2]  Spectra-week requests made in the app.
declare(strict_types=1);

require_once __DIR__ . '/schedule_store.php';

const CIT_BOOKABLE = ['institute_event', 'guest_lecture', 'department_event', 'club_event'];

function cit_ist_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d\TH:i:sP');
}

function cit_next_app_id(PDO $db): string
{
    $n = (int)$db->query("SELECT COALESCE(MAX(CAST(SUBSTRING(id, 5) AS UNSIGNED)), 0) FROM booking_requests WHERE source = 'app'")->fetchColumn();
    return sprintf('APP-%04d', $n + 1);
}

/** Validate a structured booking (from the manual form or the confirmed AI form). Returns clean fields. */
function cit_clean_booking(PDO $db, array $in): array
{
    $rooms = $db->query('SELECT id, type FROM rooms')->fetchAll(PDO::FETCH_KEY_PAIR);
    $types = array_values(array_unique($rooms));
    $features = ['accessible'];
    foreach ($db->query('SELECT features FROM rooms') as $r) $features = array_merge($features, json_decode($r['features'], true));
    $features = array_values(array_unique($features));
    $groups = $db->query('SELECT id FROM student_groups')->fetchAll(PDO::FETCH_COLUMN);
    $days = $db->query('SELECT code FROM cal_days WHERE teaching_day = 1 ORDER BY ordinal')->fetchAll(PDO::FETCH_COLUMN);

    $out = [
        'title' => cit_str($in, 'title', 200),
        'attendance' => cit_int($in, 'attendance', 1, 5000),
        'duration_periods' => cit_int($in, 'duration_periods', 1, 13),
        'requested_category' => cit_str($in, 'requested_category', 32, false) ?? 'club_event',
        'raw_text' => cit_str($in, 'raw_text', 2000, false),
        'requested_room' => cit_str($in, 'requested_room', 32, false),
    ];
    if (!in_array($out['requested_category'], CIT_BOOKABLE, true)) cit_fail('bad_field', 'Unknown category');
    $list = function (string $key, array $allowed, bool $required) use ($in): array {
        $v = $in[$key] ?? [];
        if (!is_array($v)) cit_fail('bad_field', "\"$key\" must be a list");
        $v = array_values(array_unique(array_map('strval', $v)));
        $bad = array_diff($v, $allowed);
        if ($bad) cit_fail('bad_field', "Unknown $key: " . implode(', ', $bad));
        if ($required && !$v) cit_fail('missing_field', "Choose at least one of $key");
        return $v;
    };
    $out['room_types'] = $list('room_types', $types, true);
    $out['needs'] = $list('needs', $features, false);
    $out['mandatory_for_groups'] = $list('mandatory_for_groups', $groups, false);
    $w = $in['windows'] ?? [];
    if (!is_array($w) || !$w || count($w) > 10) cit_fail('missing_field', 'Give between 1 and 10 time windows');
    $out['windows'] = [];
    foreach ($w as $i => $win) {
        if (!is_array($win) || !in_array($win['day'] ?? null, $days, true)) cit_fail('bad_field', 'Window ' . ($i + 1) . ': choose a day from ' . implode(', ', $days));
        $f = cit_int($win, 'from_period', 1, 13);
        $t = cit_int($win, 'to_period', 1, 13);
        if ($t < $f) cit_fail('bad_field', 'Window ' . ($i + 1) . ': the end period is before the start');
        $out['windows'][] = ['day' => $win['day'], 'from_period' => $f, 'to_period' => $t];
    }
    if ($out['requested_room'] !== null && !isset($rooms[$out['requested_room']])) cit_fail('bad_field', "There is no room {$out['requested_room']}");
    return $out;
}

/** Store one request (or one per window when "repeat" is set) and re-decide the week. */
function cit_create_requests(PDO $db, array $user, array $in): array
{
    $clean = cit_clean_booking($db, $in);
    $repeat = !empty($in['repeat']) && count($clean['windows']) > 1;
    $series = $repeat ? 'SER-' . strtoupper(bin2hex(random_bytes(3))) : null;
    $windowSets = $repeat ? array_map(fn($w) => [$w], $clean['windows']) : [$clean['windows']];
    $ids = cit_tx($db, function (PDO $db) use ($user, $clean, $windowSets, $series, $in) {
        $ids = [];
        $ord = (int)$db->query('SELECT COALESCE(MAX(ordinal), 0) + 1 FROM booking_requests')->fetchColumn();
        foreach ($windowSets as $wins) {
            $id = cit_next_app_id($db);
            $now = cit_ist_now();
            $db->prepare('INSERT INTO booking_requests (id, source, submitted_at, submitted_at_utc, requester, role, department, requested_category,
                          title, attendance, duration_periods, room_types, needs, requested_room, mandatory_for_groups, series_id, raw_text,
                          created_by, ai_interaction_id, ordinal) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$id, 'app', $now, cit_utc($now), $user['requester_name'] ?? $user['display_name'], $user['role'], $user['department'],
                   $clean['requested_category'], $clean['title'], $clean['attendance'], $clean['duration_periods'], cit_json($clean['room_types']),
                   cit_json($clean['needs']), $clean['requested_room'], cit_json($clean['mandatory_for_groups']), $series, $clean['raw_text'],
                   $user['id'], isset($in['ai_interaction_id']) ? (int)$in['ai_interaction_id'] : null, $ord++]);
            foreach ($wins as $i => $w) {
                $db->prepare('INSERT INTO booking_request_windows (request_id, ordinal, day, from_period, to_period) VALUES (?,?,?,?,?)')
                   ->execute([$id, $i, $w['day'], $w['from_period'], $w['to_period']]);
            }
            cit_approval('booking_request', $id, 'submitted', (int)$user['id']);
            $ids[] = $id;
        }
        cit_audit((int)$user['id'], 'booking.submit', 'booking_request', implode(',', $ids), ['title' => $clean['title']]);
        return $ids;
    });
    $active = cit_active_version($db);
    if ($active) {
        cit_recompute_bookings($db, (int)$active['id'], (int)$user['id']);
    }
    return ['request_ids' => $ids, 'decisions' => array_map(fn($id) => cit_request_detail($db, $id, $user)['decision'], $ids)];
}

function cit_request_row(PDO $db, string $id): array
{
    $st = $db->prepare('SELECT * FROM booking_requests WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) cit_fail('not_found', "There is no request $id", 404);
    return $r;
}

function cit_request_detail(PDO $db, string $id, array $user): array
{
    $r = cit_request_row($db, $id);
    if ($user['role'] !== 'admin' && !cit_owns_request($user, $r)) cit_fail('forbidden', 'You can only see your own requests', 403);
    $st = $db->prepare('SELECT day, from_period, to_period FROM booking_request_windows WHERE request_id = ? ORDER BY ordinal');
    $st->execute([$id]);
    $r['windows'] = $st->fetchAll();
    foreach (['room_types', 'needs', 'mandatory_for_groups'] as $k) $r[$k] = json_decode($r[$k], true);
    $active = cit_active_version($db);
    $r['decision'] = null;
    if ($active) {
        $st = $db->prepare('SELECT d.*, u.display_name AS reviewed_by_name FROM booking_decisions d LEFT JOIN users u ON u.id = d.reviewed_by
                            WHERE d.schedule_version_id = ? AND d.request_id = ?');
        $st->execute([$active['id'], $id]);
        $r['decision'] = $st->fetch() ?: null;
        if ($r['decision']) $r['decision']['alternatives'] = json_decode($r['decision']['alternatives'] ?? 'null', true);
    }
    $st = $db->prepare('SELECT * FROM cancellations WHERE request_id = ?');
    $st->execute([$id]);
    $r['cancellation'] = $st->fetch() ?: null;
    $st = $db->prepare('SELECT h.action, h.comment, h.created_at, u.display_name FROM approval_history h LEFT JOIN users u ON u.id = h.actor_user_id
                        WHERE h.entity_type IN (?, ?) AND h.entity_id = ? ORDER BY h.id');
    $st->execute(['booking_request', 'booking_decision', $id]);
    $r['history'] = $st->fetchAll();
    $r['is_mine'] = cit_owns_request($user, $r);
    return $r;
}

function cit_cancel_request(PDO $db, array $user, string $id, ?string $note): void
{
    $r = cit_request_row($db, $id);
    if ($user['role'] !== 'admin' && !cit_owns_request($user, $r)) cit_fail('forbidden', 'Only the requester or an Admin can cancel this', 403);
    if ($r['state'] === 'cancelled') cit_fail('already_cancelled', 'This request is already cancelled', 409);
    cit_tx($db, function (PDO $db) use ($user, $id, $note) {
        $now = cit_ist_now();
        $db->prepare('INSERT INTO cancellations (request_id, cancelled_at, cancelled_at_utc, cancelled_by, note, cancelled_by_user) VALUES (?,?,?,?,?,?)')
           ->execute([$id, $now, cit_utc($now), $user['display_name'], $note, $user['id']]);
        $db->prepare("UPDATE booking_requests SET state = 'cancelled' WHERE id = ?")->execute([$id]);
        cit_approval('booking_request', $id, 'cancelled', (int)$user['id'], $note);
        cit_audit((int)$user['id'], 'booking.cancel', 'booking_request', $id);
    });
    $active = cit_active_version($db);
    if ($active) {
        // a dataset request cancelled in the app is passed to the engine as excluded
        $db->prepare("INSERT INTO booking_decisions (schedule_version_id, request_id, status, reason, effective_category, review_state, reviewed_by, reviewed_at)
                      SELECT ?, id, 'rejected', ?, COALESCE((SELECT effective_category FROM booking_decisions WHERE schedule_version_id = ? AND request_id = ?), 'club_event'),
                             'overridden', ?, UTC_TIMESTAMP() FROM booking_requests WHERE id = ? AND source = 'dataset'
                      ON DUPLICATE KEY UPDATE status = 'rejected', day = NULL, start_period = NULL, end_period = NULL, room_id = NULL,
                             reason = VALUES(reason), review_state = 'overridden', reviewed_by = VALUES(reviewed_by), reviewed_at = UTC_TIMESTAMP()")
           ->execute([$active['id'], 'Cancelled by ' . $user['display_name'] . ($note ? ": $note" : ''), $active['id'], $id, $user['id'], $id]);
        cit_recompute_bookings($db, (int)$active['id'], (int)$user['id']);
    }
}

/** Admin review of an engine decision: confirm it, or reject it with a reason. Never on one's own request. */
function cit_review_decision(PDO $db, array $admin, string $requestId, string $action, ?string $reason): array
{
    $active = cit_require_active($db);
    $r = cit_request_row($db, $requestId);
    if (cit_owns_request($admin, $r)) {
        cit_fail('self_approval', 'You cannot approve or reject your own request. Another Admin has to review it.', 403);
    }
    $st = $db->prepare('SELECT * FROM booking_decisions WHERE schedule_version_id = ? AND request_id = ?');
    $st->execute([$active['id'], $requestId]);
    $d = $st->fetch();
    if (!$d) cit_fail('not_found', 'There is no decision for this request yet', 404);
    if ($action === 'confirm') {
        $db->prepare("UPDATE booking_decisions SET review_state = 'confirmed', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?")
           ->execute([$admin['id'], $d['id']]);
        cit_approval('booking_decision', $requestId, 'confirmed', (int)$admin['id']);
        cit_audit((int)$admin['id'], 'booking.confirm', 'booking_request', $requestId, ['status' => $d['status']]);
    } elseif ($action === 'reject') {
        if (!$reason) cit_fail('missing_field', 'Give a reason for the rejection');
        $db->prepare("UPDATE booking_decisions SET status = 'rejected', day = NULL, start_period = NULL, end_period = NULL, room_id = NULL,
                      reason = ?, review_state = 'overridden', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?")
           ->execute(['Rejected by Admin: ' . $reason, $admin['id'], $d['id']]);
        cit_approval('booking_decision', $requestId, 'rejected', (int)$admin['id'], $reason);
        cit_audit((int)$admin['id'], 'booking.reject', 'booking_request', $requestId, ['reason' => $reason]);
        cit_recompute_bookings($db, (int)$active['id'], (int)$admin['id']);   // the freed room goes to the next request
    } else {
        cit_fail('bad_field', 'action must be confirm or reject');
    }
    return cit_request_detail($db, $requestId, $admin);
}
