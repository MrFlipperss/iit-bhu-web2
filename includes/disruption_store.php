<?php
// includes/disruption_store.php   [M3]  Change file -> repair proposal -> Admin approval.
//
// Nothing changes until an Admin approves. On approval the proposal is validated AGAIN with the
// official validator (--disruption --baseline semantics) against the timetable that is active at
// that moment; if the baseline moved on in the meantime, the proposal is marked stale.
declare(strict_types=1);

require_once __DIR__ . '/schedule_store.php';

/** Store a change file and run the minimum-movement repair. Returns the proposal summary. */
function cit_propose_disruption(PDO $db, array $user, string $json, ?int $changeRequestId = null): array
{
    $dis = json_decode($json, true);
    if (!is_array($dis)) cit_fail('bad_json', 'The change file is not valid JSON');
    $active = cit_require_active($db);
    $ds = cit_current_dataset($db);
    if ((int)$active['dataset_version_id'] !== $ds['version_id']) cit_fail('stale', 'The active timetable is out of date. Generate a new one first.', 409);

    $check = cit_engine_or_fail(['command' => 'check_disruption', 'dataset' => $ds['data'], 'disruption' => $dis], 20);
    if ($check['problems']) {
        cit_fail('invalid_disruption', 'The change file cannot be applied', 422, ['problems' => $check['problems']]);
    }
    $baseline = cit_build_schedule_doc($db, (int)$active['id'], false, false);
    $res = cit_engine_or_fail(['command' => 'repair', 'dataset' => $ds['data'], 'disruption' => $dis, 'baseline' => $baseline,
                               'team' => $active['team_name']], 57);

    return cit_tx($db, function (PDO $db) use ($user, $json, $dis, $res, $active, $ds, $changeRequestId) {
        $db->prepare('INSERT INTO disruptions (external_id, dataset_ref, description, raw_json, sha256, status, base_dataset_version_id, uploaded_by)
                      VALUES (?,?,?,?,?,?,?,?)')
           ->execute([substr((string)($dis['id'] ?? ''), 0, 64) ?: null, substr((string)($dis['dataset'] ?? ''), 0, 64) ?: null,
               substr((string)($dis['description'] ?? ''), 0, 500) ?: null, $json, hash('sha256', $json),
               $res['status'] === 'ok' ? 'proposed' : 'invalid', $ds['version_id'], $user['id']]);
        $did = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO disruption_changes (disruption_id, ordinal, type, target_id, payload) VALUES (?,?,?,?,?)');
        foreach ($dis['changes'] as $i => $ch) {
            $target = $ch['faculty_id'] ?? $ch['room_id'] ?? $ch['session_id'] ?? ($ch['session']['id'] ?? ($ch['faculty']['id'] ?? null));
            $ins->execute([$did, $i, $ch['type'], $target, cit_json($ch)]);
        }
        if ($res['status'] !== 'ok') {
            $db->prepare("INSERT INTO reschedule_proposals (disruption_id, change_request_id, baseline_version_id, status, infeasible_reasons, created_by)
                          VALUES (?,?,?,?,?,?)")
               ->execute([$did, $changeRequestId, $active['id'], 'infeasible', cit_json($res['reasons'] ?? []), $user['id']]);
            $pid = (int)$db->lastInsertId();
            cit_audit((int)$user['id'], 'disruption.infeasible', 'reschedule_proposal', (string)$pid);
            return ['proposal_id' => $pid, 'status' => 'infeasible', 'reasons' => $res['reasons'] ?? []];
        }
        $sched = $res['schedule'];
        $sjson = cit_json($sched);
        $db->prepare("INSERT INTO schedule_versions (dataset_version_id, parent_id, kind, status, team_name, solver, solver_params, solve_seconds,
                      export_json, export_sha256, note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$active['dataset_version_id'], $active['id'], 'repair', 'draft', $active['team_name'], 'ortools-cpsat+greedy',
               cit_json(['repair' => $res['stats'], 'reserved' => (json_decode($active['solver_params'] ?? 'null', true)['reserved'] ?? new stdClass())]),
               $res['stats']['seconds'] ?? null, $sjson, hash('sha256', $sjson), 'Repair for disruption ' . $did, $user['id']]);
        $vid = (int)$db->lastInsertId();
        cit_insert_entries($db, $vid, $res['dataset_after'], $sched['sessions']);
        $sum = $res['summary'];
        $db->prepare("INSERT INTO reschedule_proposals (disruption_id, change_request_id, baseline_version_id, proposed_version_id, status,
                      sessions_moved, time_changed, room_only_changed, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$did, $changeRequestId, $active['id'], $vid, 'pending', $sum['sessions_moved'], $sum['time_changed'], $sum['room_only_changed'], $user['id']]);
        $pid = (int)$db->lastInsertId();
        $ch = $db->prepare('INSERT INTO schedule_changes (proposal_id, session_id, change_kind, from_day, from_period, from_room, to_day, to_period, to_room, reason)
                            VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($res['changes'] as $c) {
            $ch->execute([$pid, $c['session_id'], $c['change_kind'], $c['from'][0] ?? null, $c['from'][1] ?? null, $c['from'][2] ?? null,
                $c['to'][0] ?? null, $c['to'][1] ?? null, $c['to'][2] ?? null, mb_substr($c['reason'], 0, 500)]);
        }
        cit_store_validation($db, $vid, $res['report'], 'disruption', (int)$active['id'], $did);
        cit_approval('reschedule_proposal', (string)$pid, 'proposed', (int)$user['id']);
        cit_audit((int)$user['id'], 'disruption.propose', 'reschedule_proposal', (string)$pid, $sum);
        return ['proposal_id' => $pid, 'status' => 'pending', 'summary' => $sum,
                'hard_violations' => $res['report']['hard_violation_count'], 'soft_total' => $res['report']['soft_total']];
    });
}

function cit_proposal(PDO $db, int $id): array
{
    $st = $db->prepare('SELECT p.*, d.external_id, d.description, d.raw_json, u.display_name AS created_by_name, du.display_name AS decided_by_name
                        FROM reschedule_proposals p LEFT JOIN disruptions d ON d.id = p.disruption_id
                        LEFT JOIN users u ON u.id = p.created_by LEFT JOIN users du ON du.id = p.decided_by WHERE p.id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) cit_fail('not_found', "There is no proposal $id", 404);
    $p['infeasible_reasons'] = json_decode($p['infeasible_reasons'] ?? 'null', true);
    $p['disruption'] = json_decode($p['raw_json'] ?? 'null', true);
    unset($p['raw_json']);
    $st = $db->prepare('SELECT c.*, s.course_id, s.kind, s.faculty_id FROM schedule_changes c LEFT JOIN sessions s ON s.id = c.session_id
                        WHERE c.proposal_id = ? ORDER BY FIELD(c.change_kind, "moved", "room_only", "added", "removed"), c.session_id');
    $st->execute([$id]);
    $p['changes'] = $st->fetchAll();
    $p['validation'] = null;
    if ($p['proposed_version_id']) {
        $st = $db->prepare("SELECT hard_violation_count, hard_by_rule, soft_total, sessions_moved, created_at FROM validation_runs
                            WHERE schedule_version_id = ? AND mode = 'disruption' ORDER BY id DESC LIMIT 1");
        $st->execute([$p['proposed_version_id']]);
        $p['validation'] = $st->fetch() ?: null;
    }
    return $p;
}

function cit_decide_proposal(PDO $db, array $admin, int $id, string $action, ?string $comment): array
{
    $p = cit_proposal($db, $id);
    if ($p['status'] !== 'pending') cit_fail('not_pending', "This proposal is already {$p['status']}", 409);
    if ($action === 'reject') {
        $db->prepare("UPDATE reschedule_proposals SET status = 'rejected', decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_comment = ? WHERE id = ?")
           ->execute([$admin['id'], $comment, $id]);
        $db->prepare("UPDATE disruptions SET status = 'rejected' WHERE id = ?")->execute([$p['disruption_id']]);
        $db->prepare("UPDATE schedule_versions SET status = 'rejected' WHERE id = ?")->execute([$p['proposed_version_id']]);
        if ($p['change_request_id']) $db->prepare("UPDATE change_requests SET state = 'rejected' WHERE id = ?")->execute([$p['change_request_id']]);
        cit_approval('reschedule_proposal', (string)$id, 'rejected', (int)$admin['id'], $comment);
        cit_audit((int)$admin['id'], 'disruption.reject', 'reschedule_proposal', (string)$id);
        return cit_proposal($db, $id);
    }
    if ($action !== 'approve') cit_fail('bad_field', 'action must be approve or reject');

    $active = cit_require_active($db);
    if ((int)$active['id'] !== (int)$p['baseline_version_id']) {
        $db->prepare("UPDATE reschedule_proposals SET status = 'stale' WHERE id = ?")->execute([$id]);
        cit_fail('stale', 'The timetable changed after this proposal was made. Upload the change file again.', 409);
    }
    $base = cit_version_dataset($db, $active);
    $proposed = cit_build_schedule_doc($db, (int)$p['proposed_version_id'], false, false);
    $baseline = cit_build_schedule_doc($db, (int)$active['id'], false, false);
    $val = cit_engine_or_fail(['command' => 'validate', 'dataset' => $base, 'schedule' => $proposed,
                               'disruption' => $p['disruption'], 'baseline' => $baseline], 30);
    if ($val['report']['hard_violation_count'] > 0) {
        cit_fail('invalid_proposal', 'The proposal no longer passes validation, so it was not applied', 422,
            ['violations' => array_slice($val['report']['hard_violations'], 0, 20)]);
    }
    $after = cit_engine_or_fail(['command' => 'apply_disruption', 'dataset' => $base, 'disruption' => $p['disruption']], 20)['dataset'];

    cit_tx($db, function (PDO $db) use ($admin, $p, $active, $after, $val, $id, $comment) {
        $importId = (int)$db->query('SELECT import_id FROM dataset_versions WHERE is_current = 1')->fetchColumn();
        $dv = cit_create_dataset_version($db, cit_json($after), $importId, (int)$active['dataset_version_id'], 'disruption', (int)$admin['id']);
        $vid = (int)$p['proposed_version_id'];
        $db->prepare('UPDATE schedule_versions SET dataset_version_id = ? WHERE id = ?')->execute([$dv, $vid]);
        // exams are not affected by teaching-week changes: carry the plan over
        $db->prepare('INSERT INTO exam_entries SELECT ?, exam_id, day, session FROM exam_entries WHERE schedule_version_id = ?')->execute([$vid, $active['id']]);
        $db->prepare('INSERT INTO exam_entry_rooms SELECT ?, exam_id, room_id, day, session, ordinal FROM exam_entry_rooms WHERE schedule_version_id = ?')
           ->execute([$vid, $active['id']]);
        // keep Admin rejections / cancellations of Spectra requests
        $db->prepare("INSERT INTO booking_decisions (schedule_version_id, request_id, status, reason, effective_category, review_state, reviewed_by, reviewed_at)
                      SELECT ?, request_id, status, reason, effective_category, review_state, reviewed_by, reviewed_at FROM booking_decisions
                      WHERE schedule_version_id = ? AND review_state = 'overridden' AND status = 'rejected'")->execute([$vid, $active['id']]);
        cit_activate($db, $vid, (int)$admin['id']);
        $db->prepare("UPDATE reschedule_proposals SET status = 'approved', decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_comment = ? WHERE id = ?")
           ->execute([$admin['id'], $comment, $id]);
        $db->prepare("UPDATE disruptions SET status = 'applied', result_dataset_version_id = ? WHERE id = ?")->execute([$dv, $p['disruption_id']]);
        if ($p['change_request_id']) $db->prepare("UPDATE change_requests SET state = 'approved' WHERE id = ?")->execute([$p['change_request_id']]);
        cit_store_validation($db, $vid, $val['report'], 'disruption', (int)$active['id'], (int)$p['disruption_id']);
        cit_approval('reschedule_proposal', (string)$id, 'approved', (int)$admin['id'], $comment);
        cit_audit((int)$admin['id'], 'disruption.approve', 'reschedule_proposal', (string)$id,
            ['schedule_version' => $vid, 'dataset_version' => $dv, 'moved' => $val['report']['stability']['sessions_moved'] ?? null]);
    });
    // Spectra decisions are re-made on the new timetable (changes are permanent, Week 7 included)
    cit_recompute_bookings($db, (int)$p['proposed_version_id'], (int)$admin['id']);
    return cit_proposal($db, $id);
}
