<?php
/**
 * Time API — entries (the atomic record)
 *
 *   GET    /api/time/entries                    → list with filters
 *   GET    /api/time/entries?id=N               → get one
 *   POST   /api/time/entries                    → create draft
 *   PATCH  /api/time/entries?id=N               → update (draft/pending_review/rejected only)
 *   POST   /api/time/entries?action=submit&id=N   → draft → pending_review
 *   POST   /api/time/entries?action=approve&id=N  → pending_review → approved (locks rate_snapshot_id)
 *   POST   /api/time/entries?action=reject&id=N   → pending_review → rejected
 *   POST   /api/time/entries?action=correct&id=N  → create new entry, old → superseded
 *
 * SPEC: /app/modules/time/SPEC.md §5.1, §9
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/time.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

// ─── Actions (POST) ───
if ($method === 'POST' && $action !== '') {
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $entry = timeEntryGet($id);
    if (!$entry) api_error('Entry not found', 404);

    if ($action === 'submit') {
        _timeRequireEntryWriteAccess($user, $entry);
        if ($entry['status'] !== 'draft') api_error('Only draft entries can be submitted', 409, ['status' => $entry['status']]);
        scopedUpdate('time_entries', $id, ['status' => 'pending_review']);
        $updatedEntry = timeEntryGet($id);
        timeAudit('time.entry.submitted', ['entry_id' => $id], $id, [
            'before' => $entry,
            'after' => $updatedEntry,
        ]);
        api_ok(['ok' => true, 'entry' => $updatedEntry]);
    }

    if ($action === 'approve') {
        rbac_legacy_require($user, 'time.approve');
        // SPEC §4: two-eye control — approver MUST NOT be the entry's creator/submitter.
        if ($entry['created_by_user_id'] && (int) $entry['created_by_user_id'] === (int) ($user['id'] ?? 0)) {
            api_error('Two-eye control: you cannot approve your own entry', 403);
        }
        if ($entry['status'] !== 'pending_review') api_error('Only pending_review entries can be approved', 409, ['status' => $entry['status']]);

        $snap = timeResolveRateSnapshot((int) $entry['placement_id'], $entry['work_date']);
        if (!$snap) api_error("No approved rate covering {$entry['work_date']} for this placement. Approve a rate first.", 422);

        scopedUpdate('time_entries', $id, [
            'status'             => 'approved',
            'rate_snapshot_id'   => (int) $snap['id'],
            'approved_by_user_id'=> $user['id'] ?? null,
            'approved_at'        => date('Y-m-d H:i:s'),
            'approved_via'       => 'manual',
        ]);
        // Per-entry approval audit (P1.a — accrual-at-approval companion).
        // No GL write: bundle accrual owns recognition; this emits the
        // audit_log row downstream dashboards subscribe to.
        $approvedEntry = timeEntryGet($id) ?? $entry;
        timeEntryApprovedEmit((int) $id, $approvedEntry, 'manual', [
            'before' => $entry,
            'approver_user_id' => $user['id'] ?? null,
        ]);
        api_ok(['ok' => true, 'entry' => $approvedEntry]);
    }

    if ($action === 'reject') {
        rbac_legacy_require($user, 'time.reject');
        if ($entry['status'] !== 'pending_review') api_error('Only pending_review entries can be rejected', 409);
        $body = api_json_body();
        api_require_fields($body, ['reason']);
        scopedUpdate('time_entries', $id, ['status' => 'rejected', 'rejected_reason' => $body['reason']]);
        $updatedEntry = timeEntryGet($id);
        timeAudit('time.entry.rejected', ['entry_id' => $id, 'reason' => $body['reason']], $id, [
            'before' => $entry,
            'after' => $updatedEntry,
        ]);
        api_ok(['ok' => true, 'entry' => $updatedEntry]);
    }

    if ($action === 'correct') {
        rbac_legacy_require($user, 'time.entry.manage');
        if ($entry['status'] !== 'approved') api_error('Only approved entries can be corrected (supersede)', 409);
        $body = api_json_body();
        api_require_fields($body, ['correction_reason']);

        $pdo = getDB();
        $pdo->beginTransaction();
        try {
            // New draft entry inherits placement/person/period, default status=draft, source=manual_entry
            $newId = scopedInsert('time_entries', [
                'placement_id'       => (int) $entry['placement_id'],
                'person_id'          => (int) $entry['person_id'],
                'period_id'          => (int) $entry['period_id'],
                'work_date'          => $body['work_date']         ?? $entry['work_date'],
                'category'           => $body['category']          ?? $entry['category'],
                'hours'              => $body['hours']             ?? $entry['hours'],
                'description'        => $body['description']       ?? $entry['description'],
                'source'             => 'manual_entry',
                'status'             => 'draft',
                'correction_reason'  => $body['correction_reason'],
                'created_by_user_id' => $user['id'] ?? null,
            ]);
            scopedUpdate('time_entries', $id, ['status' => 'superseded', 'superseded_by_id' => $newId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            api_error('Correct failed: ' . $e->getMessage(), 500);
        }
        timeAudit('time.entry.superseded', ['entry_id' => $id, 'by_entry_id' => $newId, 'reason' => $body['correction_reason']], $id, [
            'before' => $entry,
            'after' => [
                'superseded_entry' => timeEntryGet($id),
                'new_entry' => timeEntryGet($newId),
            ],
        ]);
        api_ok(['ok' => true, 'superseded_entry_id' => $id, 'new_entry_id' => $newId]);
    }

    api_error('Unknown action', 400);
}

// ─── GET ───
if ($method === 'GET') {
    rbac_legacy_require($user, 'time.view');
    $id = (int) api_query('id', 0);
    if ($id > 0) {
        $row = timeEntryGet($id);
        if (!$row) api_error('Not found', 404);
        api_ok(['entry' => $row]);
    }
    api_ok(timeEntriesList([
        'period_id'    => $_GET['period_id']    ?? null,
        'placement_id' => $_GET['placement_id'] ?? null,
        'person_id'    => $_GET['person_id']    ?? null,
        'status'       => $_GET['status']       ?? null,
        'source'       => $_GET['source']       ?? null,
        'category'     => $_GET['category']     ?? null,
        'work_date'    => $_GET['work_date']    ?? null,
        'work_date_from' => $_GET['work_date_from'] ?? null,
        'work_date_to'   => $_GET['work_date_to']   ?? null,
        'page'         => $_GET['page']         ?? 1,
        'per_page'     => $_GET['per_page']     ?? 50,
    ]));
}

// ─── POST (create) ───
if ($method === 'POST') {
    $body = api_json_body();
    api_require_fields($body, ['placement_id','work_date','category','hours']);

    if (!in_array($body['category'], TIME_CATEGORIES, true)) {
        api_error('Invalid category', 422, ['allowed' => TIME_CATEGORIES]);
    }

    // Resolve placement (for person_id denorm + tenant check)
    $placement = scopedFind(
        'SELECT id, person_id, start_date, end_date FROM placements
         WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL',
        ['id' => (int) $body['placement_id']]
    );
    if (!$placement) api_error('placement_id not found in this tenant', 422);

    // work_date must fall inside placement active window
    if ($body['work_date'] < $placement['start_date']) {
        api_error('work_date precedes placement start_date', 422);
    }
    if ($placement['end_date'] && $body['work_date'] > $placement['end_date']) {
        api_error('work_date is after placement end_date', 422);
    }

    // Enforce self vs. behalf-of
    $isSelf = (int) $placement['person_id'] === (int) ($user['person_id'] ?? 0);
    if ($isSelf) {
        rbac_legacy_require($user, 'time.entry.self');
    } else {
        rbac_legacy_require($user, 'time.entry.manage');
    }
    if (array_key_exists('status', $body) && (string) $body['status'] !== 'draft') {
        api_error('status cannot be set during create; use submit/approve/reject actions', 422);
    }

    // Resolve or create period
    $periodId = (int) ($body['period_id'] ?? 0);
    if ($periodId <= 0) {
        $period = scopedFind(
            'SELECT id FROM time_periods
             WHERE tenant_id = :tenant_id AND start_date <= :wd AND end_date >= :wd AND status != "closed"
             ORDER BY start_date DESC LIMIT 1',
            ['wd' => $body['work_date']]
        );
        if (!$period) api_error('No open period covers this work_date. Create a period first.', 422);
        $periodId = (int) $period['id'];
    }

    // 24h cap (soft)
    $sum = scopedFind(
        'SELECT COALESCE(SUM(hours), 0) AS h FROM time_entries
         WHERE tenant_id = :tenant_id AND person_id = :pid AND work_date = :wd AND status != "superseded"',
        ['pid' => (int) $placement['person_id'], 'wd' => $body['work_date']]
    );
    if (((float) ($sum['h'] ?? 0) + (float) $body['hours']) > 24.0) {
        api_error('Total hours across all entries for this person on this date would exceed 24', 422);
    }

    $id = scopedInsert('time_entries', [
        'placement_id'       => (int) $body['placement_id'],
        'person_id'          => (int) $placement['person_id'],
        'period_id'          => $periodId,
        'work_date'          => $body['work_date'],
        'category'           => $body['category'],
        'custom_category_id' => $body['custom_category_id'] ?? null,
        'hours'              => (float) $body['hours'],
        'description'        => $body['description'] ?? null,
        'source'             => $body['source']      ?? 'manual_entry',
        'status'             => 'draft',
        'created_by_user_id' => $user['id'] ?? null,
    ]);
    $createdEntry = timeEntryGet($id);
    timeAudit('time.entry.created', ['entry_id' => $id, 'placement_id' => (int) $body['placement_id'], 'category' => $body['category']], $id, [
        'after' => $createdEntry,
    ]);
    api_ok(['entry' => $createdEntry], 201);
}

// ─── PATCH ───
if ($method === 'PATCH') {
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $entry = timeEntryGet($id);
    if (!$entry) api_error('Not found', 404);
    if (!in_array($entry['status'], ['draft','pending_review','rejected'], true)) {
        api_error('Only draft/pending_review/rejected entries can be edited. Approved entries require correction.', 409, ['status' => $entry['status']]);
    }
    rbac_legacy_require($user, 'time.entry.manage');

    $body = api_json_body();
    if (array_key_exists('status', $body)) {
        api_error('status transitions must use submit/approve/reject actions', 422);
    }
    foreach (['id','tenant_id','placement_id','person_id','period_id',
              'rate_snapshot_id','approved_by_user_id','approved_at','approved_via',
              'rejected_reason','superseded_by_id','created_by_user_id','created_at',
              'status'] as $k) unset($body[$k]);
    if (isset($body['category']) && !in_array($body['category'], TIME_CATEGORIES, true)) api_error('Invalid category', 422);
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('time_entries', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    $updatedEntry = timeEntryGet($id);
    timeAudit('time.entry.updated', ['entry_id' => $id, 'fields' => array_keys($body)], $id, [
        'before' => $entry,
        'after' => $updatedEntry,
    ]);
    api_ok(['entry' => $updatedEntry]);
}

api_error('Method not allowed', 405);

function _timeRequireEntryWriteAccess(array $user, array $entry): void
{
    $isCreator = !empty($entry['created_by_user_id'])
        && (int) $entry['created_by_user_id'] === (int) ($user['id'] ?? 0);
    $isOwnPerson = !empty($entry['person_id'])
        && (int) $entry['person_id'] === (int) ($user['person_id'] ?? 0);
    if ($isCreator || $isOwnPerson) {
        rbac_legacy_require($user, 'time.entry.self');
        return;
    }
    rbac_legacy_require($user, 'time.entry.manage');
}
