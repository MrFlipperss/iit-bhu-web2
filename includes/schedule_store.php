<?php
// includes/schedule_store.php   [M1]  Schedule versions <-> MySQL.
declare(strict_types=1);

require_once __DIR__ . '/dataset_import.php';
require_once __DIR__ . '/python.php';
require_once __DIR__ . '/audit.php';

function cit_active_version(PDO $db): ?array
{
    $row = $db->query("SELECT * FROM schedule_versions WHERE status = 'active'")->fetch();
    return $row ?: null;
}

function cit_version(PDO $db, int $id): array
{
    $st = $db->prepare('SELECT * FROM schedule_versions WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) cit_fail('not_found', "Schedule version $id does not exist", 404);
    return $row;
}

function cit_require_active(PDO $db): array
{
    $v = cit_active_version($db);
    if (!$v) cit_fail('no_timetable', 'No timetable yet. An Admin needs to generate one first.', 409);
    return $v;
}

/** Dataset (decoded) that a schedule version was built on. */
function cit_version_dataset(PDO $db, array $version): array
{
    $st = $db->prepare('SELECT snapshot_json FROM dataset_versions WHERE id = ?');
    $st->execute([$version['dataset_version_id']]);
    return json_decode($st->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
}

function cit_version_assign(PDO $db, int $versionId): array
{
    $st = $db->prepare('SELECT session_id, day, start_period, room_id FROM schedule_entries WHERE schedule_version_id = ?');
    $st->execute([$versionId]);
    $out = [];
    foreach ($st as $r) {
        $out[$r['session_id']] = [$r['day'], (int)$r['start_period'], $r['room_id']];
    }
    return $out;
}

function cit_activate(PDO $db, int $versionId, ?int $userId): void
{
    $db->exec("UPDATE schedule_versions SET status = 'superseded' WHERE status = 'active'");
    $db->prepare("UPDATE schedule_versions SET status = 'active', activated_by = ?, activated_at = UTC_TIMESTAMP() WHERE id = ?")
       ->execute([$userId, $versionId]);
}

function cit_insert_entries(PDO $db, int $versionId, array $data, array $sessions): void
{
    $dur = array_column($data['sessions'], 'duration', 'id');
    $st = $db->prepare('INSERT INTO schedule_entries (schedule_version_id, session_id, day, start_period, room_id, duration) VALUES (?,?,?,?,?,?)');
    foreach ($sessions as $e) {
        $st->execute([$versionId, $e['session_id'], $e['day'], $e['start_period'], $e['room_id'], $dur[$e['session_id']]]);
    }
}

function cit_insert_exams(PDO $db, int $versionId, array $exams): void
{
    $e1 = $db->prepare('INSERT INTO exam_entries (schedule_version_id, exam_id, day, session) VALUES (?,?,?,?)');
    $e2 = $db->prepare('INSERT INTO exam_entry_rooms (schedule_version_id, exam_id, room_id, day, session, ordinal) VALUES (?,?,?,?,?,?)');
    foreach ($exams as $x) {
        $e1->execute([$versionId, $x['exam_id'], $x['day'], $x['session']]);
        foreach ($x['rooms'] as $i => $r) {
            $e2->execute([$versionId, $x['exam_id'], $r, $x['day'], $x['session'], $i]);
        }
    }
}

/** Replace a version's booking decisions and overrides. Keeps an Admin's confirmations when nothing changed. */
function cit_store_decisions(PDO $db, int $versionId, array $decisions, array $overrides, array $details, ?int $userId = null): void
{
    $prev = [];
    $st = $db->prepare('SELECT request_id, status, day, start_period, end_period, room_id, review_state, reviewed_by, reviewed_at FROM booking_decisions WHERE schedule_version_id = ?');
    $st->execute([$versionId]);
    foreach ($st as $r) $prev[$r['request_id']] = $r;
    $db->prepare('DELETE FROM booking_decisions WHERE schedule_version_id = ?')->execute([$versionId]);
    $db->prepare('DELETE FROM session_overrides WHERE schedule_version_id = ?')->execute([$versionId]);
    $ins = $db->prepare('INSERT INTO booking_decisions (schedule_version_id, request_id, status, day, start_period, end_period, room_id, reason,
                         effective_category, inside_window, alternatives, review_state, reviewed_by, reviewed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $cancelled = array_flip($db->query('SELECT request_id FROM cancellations')->fetchAll(PDO::FETCH_COLUMN));
    foreach ($decisions as $d) {
        $rid = $d['request_id'];
        $p = $prev[$rid] ?? null;
        $same = $p && $p['status'] === $d['status'] && $p['room_id'] === ($d['room_id'] ?? null) && $p['day'] === ($d['day'] ?? null)
            && (int)$p['start_period'] === (int)($d['start_period'] ?? 0) && (int)$p['end_period'] === (int)($d['end_period'] ?? 0);
        $review = $p && $p['review_state'] === 'overridden' ? 'overridden' : ($same && $p['review_state'] === 'confirmed' ? 'confirmed' : 'proposed');
        if (isset($cancelled[$rid])) $review = 'confirmed';     // nothing for a human to approve
        $notes = $details[$rid]['notes'] ?? [];
        $ins->execute([$versionId, $rid, $d['status'], $d['day'] ?? null, $d['start_period'] ?? null, $d['end_period'] ?? null,
            $d['room_id'] ?? null, $d['reason'] ?? '', $details[$rid]['effective_category'] ?? 'club_event',
            $d['status'] === 'approved' ? 1 : ($d['status'] === 'alternative_offered' ? 0 : null),
            $notes ? cit_json(['notes' => $notes]) : null, $review,
            $review === 'proposed' ? null : ($p['reviewed_by'] ?? $userId), $review === 'proposed' ? null : ($p['reviewed_at'] ?? null)]);
    }
    $ov = $db->prepare('INSERT INTO session_overrides (schedule_version_id, session_id, day, start_period, room_id, displaced_by) VALUES (?,?,?,?,?,?)');
    foreach ($overrides as $o) {
        $ov->execute([$versionId, $o['session_id'], $o['day'], $o['start_period'], $o['room_id'], $o['displaced_by']]);
    }
    $db->exec("UPDATE booking_requests SET state = 'decided' WHERE state = 'pending' AND id IN (SELECT request_id FROM booking_decisions WHERE schedule_version_id = $versionId)");
}

function cit_store_validation(PDO $db, int $versionId, array $report, string $mode = 'schedule', ?int $baselineId = null, ?int $disruptionId = null): int
{
    $db->prepare('INSERT INTO validation_runs (schedule_version_id, mode, baseline_version_id, disruption_id, hard_violation_count, hard_by_rule,
                  soft_total, soft_penalties, exam_soft_penalty, booking_score, priority_inversions, sessions_moved, report_json, validator_sha256)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$versionId, $mode, $baselineId, $disruptionId, $report['hard_violation_count'], cit_json((object)$report['hard_by_rule']),
           $report['soft_total'], cit_json($report['soft_penalties']), $report['exam_soft_penalty'] ?? null,
           $report['bookings']['booking_score'] ?? null, isset($report['bookings']) ? count($report['bookings']['priority_inversions']) : null,
           $report['stability']['sessions_moved'] ?? null, cit_json($report), hash_file('sha256', cit_path('validator/validator.py'))]);
    if ($mode === 'schedule') {
        $db->prepare('UPDATE schedule_versions SET hard_violation_count = ?, soft_total = ?, booking_score = ? WHERE id = ?')
           ->execute([$report['hard_violation_count'], $report['soft_total'], $report['bookings']['booking_score'] ?? null, $versionId]);
    }
    return (int)$db->lastInsertId();
}

/** App-created requests in the engine's request shape (cancelled ones are left out). */
function cit_app_requests(PDO $db): array
{
    $rows = $db->query("SELECT * FROM booking_requests WHERE source = 'app' AND state <> 'cancelled' ORDER BY ordinal")->fetchAll();
    $win = [];
    foreach ($db->query("SELECT w.* FROM booking_request_windows w JOIN booking_requests b ON b.id = w.request_id WHERE b.source = 'app' ORDER BY w.request_id, w.ordinal") as $w) {
        $win[$w['request_id']][] = ['day' => $w['day'], 'from_period' => (int)$w['from_period'], 'to_period' => (int)$w['to_period']];
    }
    return array_map(fn($r) => [
        'id' => $r['id'], 'submitted_at' => $r['submitted_at'], 'requester' => $r['requester'], 'role' => $r['role'],
        'department' => $r['department'], 'requested_category' => $r['requested_category'], 'title' => $r['title'],
        'attendance' => (int)$r['attendance'], 'duration_periods' => (int)$r['duration_periods'], 'windows' => $win[$r['id']] ?? [],
        'room_types' => json_decode($r['room_types'], true), 'needs' => json_decode($r['needs'], true),
        'requested_room' => $r['requested_room'], 'mandatory_for_groups' => json_decode($r['mandatory_for_groups'], true),
        'series_id' => $r['series_id'], 'raw_text' => $r['raw_text']], $rows);
}

/** Requests an Admin rejected by hand: they stay rejected when decisions are recomputed. */
function cit_admin_rejections(PDO $db, int $versionId): array
{
    $st = $db->prepare("SELECT request_id, reason FROM booking_decisions WHERE schedule_version_id = ? AND review_state = 'overridden' AND status = 'rejected'");
    $st->execute([$versionId]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Generate (or improve) the whole plan and store it as a new version; activates it when valid. */
function cit_generate(PDO $db, array $user, bool $improve = false): array
{
    $ds = cit_current_dataset($db);
    $req = ['command' => 'generate_all', 'dataset' => $ds['data'], 'team' => (string)cit_setting('team_name', 'CIT team'),
            'extra_requests' => cit_app_requests($db)];
    $parent = cit_active_version($db);
    if ($improve) {
        if (!$parent) cit_fail('no_timetable', 'Generate a timetable before improving it', 409);
        if ((int)$parent['dataset_version_id'] !== $ds['version_id']) cit_fail('stale', 'The dataset changed since this timetable was made. Generate a new one instead.', 409);
        $req['start'] = cit_version_assign($db, (int)$parent['id']);
        $params = json_decode($parent['solver_params'] ?? 'null', true) ?: [];
        $req['start_reserved'] = $params['reserved'] ?? new stdClass();
        $req['excluded'] = (object)cit_admin_rejections($db, (int)$parent['id']);
    }
    $res = cit_engine_or_fail($req, 57);
    $report = $res['report'];
    return cit_tx($db, function (PDO $db) use ($res, $report, $ds, $parent, $user, $improve) {
        $sched = $res['schedule'];
        $json = cit_json($sched);
        $db->prepare("INSERT INTO schedule_versions (dataset_version_id, parent_id, kind, status, team_name, solver, solver_params, solve_seconds,
                      export_json, export_sha256, note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$ds['version_id'], $parent['id'] ?? null, 'generated', $report['hard_violation_count'] === 0 ? 'validated' : 'draft',
               $sched['team'], isset($res['timetable_stats']['solver_status']) ? 'ortools-cpsat+greedy' : 'greedy',
               cit_json(['reserved' => (object)$res['reserved'], 'timetable' => $res['timetable_stats'], 'exams' => $res['exam_stats']]),
               $res['seconds'], $json, hash('sha256', $json), $improve ? 'Improved from version ' . $parent['id'] : null, $user['id']]);
        $vid = (int)$db->lastInsertId();
        $data = $ds['data'];
        cit_insert_entries($db, $vid, $data, $sched['sessions']);
        cit_insert_exams($db, $vid, $sched['exams']);
        cit_store_decisions($db, $vid, $res['all_decisions'], $sched['fest_week']['session_overrides'], $res['details'], $user['id']);
        if ($improve && $parent) {   // carry an Admin's manual rejections forward
            $db->prepare("UPDATE booking_decisions d JOIN booking_decisions p ON p.request_id = d.request_id AND p.schedule_version_id = ?
                          SET d.review_state = 'overridden', d.reviewed_by = p.reviewed_by, d.reviewed_at = p.reviewed_at
                          WHERE d.schedule_version_id = ? AND p.review_state = 'overridden'")->execute([$parent['id'], $vid]);
        }
        cit_store_validation($db, $vid, $report);
        if ($report['hard_violation_count'] === 0) {
            cit_activate($db, $vid, $user['id']);
        }
        cit_audit((int)$user['id'], $improve ? 'schedule.improve' : 'schedule.generate', 'schedule_version', (string)$vid,
            ['hard' => $report['hard_violation_count'], 'soft' => $report['soft_total'], 'seconds' => $res['seconds']]);
        return ['version_id' => $vid, 'activated' => $report['hard_violation_count'] === 0, 'seconds' => $res['seconds'],
                'hard_violations' => $report['hard_violation_count'], 'hard_by_rule' => $report['hard_by_rule'],
                'soft_total' => $report['soft_total'], 'soft_penalties' => $report['soft_penalties'],
                'exam_soft_penalty' => $report['exam_soft_penalty'] ?? null, 'bookings' => $report['bookings'] ?? null,
                'violations' => array_slice($report['hard_violations'], 0, 20)];
    });
}

/** Re-decide every Spectra request on a version (after a new request, a cancellation or an Admin rejection). */
function cit_recompute_bookings(PDO $db, int $versionId, ?int $userId): array
{
    $v = cit_version($db, $versionId);
    $data = cit_version_dataset($db, $v);
    $params = json_decode($v['solver_params'] ?? 'null', true) ?: [];
    $res = cit_engine_or_fail(['command' => 'bookings', 'dataset' => $data, 'sessions' => cit_version_assign($db, $versionId),
        'reserved' => $params['reserved'] ?? new stdClass(), 'extra_requests' => cit_app_requests($db),
        'excluded' => (object)cit_admin_rejections($db, $versionId)], 30);
    cit_tx($db, fn($db) => cit_store_decisions($db, $versionId, $res['decisions'], $res['session_overrides'], $res['details'], $userId));
    $doc = cit_build_schedule_doc($db, $versionId);
    $val = cit_engine(['command' => 'validate', 'dataset' => $data, 'schedule' => $doc], 30);
    if ($val['ok']) cit_store_validation($db, $versionId, $val['report']);
    return $res;
}

/** schedule.json rebuilt from the stored rows (what Export downloads). */
function cit_build_schedule_doc(PDO $db, int $versionId, bool $fest = true, bool $exams = true): array
{
    $v = cit_version($db, $versionId);
    $data = cit_version_dataset($db, $v);
    $order = array_flip(array_column($data['sessions'], 'id'));
    $assign = cit_version_assign($db, $versionId);
    uksort($assign, fn($a, $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
    $doc = ['team' => $v['team_name'], 'dataset_id' => $data['dataset_id'], 'sessions' => []];
    foreach ($assign as $sid => [$d, $t, $r]) {
        $doc['sessions'][] = ['session_id' => $sid, 'day' => $d, 'start_period' => $t, 'room_id' => $r];
    }
    if ($fest) {
        $known = array_flip(array_column($data['booking_requests'], 'id'));
        $reqOrder = array_flip(array_column($data['booking_requests'], 'id'));
        $st = $db->prepare('SELECT * FROM booking_decisions WHERE schedule_version_id = ?');
        $st->execute([$versionId]);
        $bookings = [];
        foreach ($st as $d) {
            if (!isset($known[$d['request_id']])) continue;          // app requests are not part of the export
            $row = ['request_id' => $d['request_id'], 'status' => $d['status']];
            if ($d['status'] !== 'rejected') {
                $row += ['day' => $d['day'], 'start_period' => (int)$d['start_period'], 'end_period' => (int)$d['end_period'], 'room_id' => $d['room_id']];
            }
            $row['reason'] = $d['reason'];
            $bookings[] = $row;
        }
        usort($bookings, fn($a, $b) => $reqOrder[$a['request_id']] <=> $reqOrder[$b['request_id']]);
        $st = $db->prepare('SELECT session_id, day, start_period, room_id, displaced_by FROM session_overrides WHERE schedule_version_id = ? ORDER BY session_id');
        $st->execute([$versionId]);
        $ov = array_map(fn($o) => ['session_id' => $o['session_id'], 'day' => $o['day'], 'start_period' => (int)$o['start_period'],
            'room_id' => $o['room_id'], 'displaced_by' => $o['displaced_by']], $st->fetchAll());
        $doc['fest_week'] = ['bookings' => $bookings, 'session_overrides' => $ov];
    }
    if ($exams) {
        $exOrder = array_flip(array_column($data['exams'], 'id'));
        $st = $db->prepare('SELECT e.exam_id, e.day, e.session, r.room_id FROM exam_entries e LEFT JOIN exam_entry_rooms r
                            ON r.schedule_version_id = e.schedule_version_id AND r.exam_id = e.exam_id WHERE e.schedule_version_id = ? ORDER BY e.exam_id, r.ordinal');
        $st->execute([$versionId]);
        $ex = [];
        foreach ($st as $r) {
            $ex[$r['exam_id']] ??= ['exam_id' => $r['exam_id'], 'day' => $r['day'], 'session' => $r['session'], 'rooms' => []];
            if ($r['room_id']) $ex[$r['exam_id']]['rooms'][] = $r['room_id'];
        }
        if ($ex) {
            uksort($ex, fn($a, $b) => ($exOrder[$a] ?? 0) <=> ($exOrder[$b] ?? 0));
            $doc['exams'] = array_values($ex);
        }
    }
    return $doc;
}
