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
    if ($action === 'bulk_approve' || $action === 'bulk_reject') {
        $body = api_json_body();
        $ids = is_array($body['ids'] ?? null)
            ? array_values(array_unique(array_filter(array_map('intval', $body['ids']), static fn ($id) => $id > 0)))
            : [];
        if (!$ids) api_error('ids[] required', 422);
        if (count($ids) > 500) api_error('Too many ids (max 500 per call)', 422);

        $isApprove = $action === 'bulk_approve';
        if ($isApprove) {
            rbac_legacy_require($user, 'time.approve');
        } else {
            rbac_legacy_require($user, 'time.reject');
            if (trim((string) ($body['reason'] ?? '')) === '') api_error('reason required', 422);
        }

        $succeeded = 0;
        $failed = 0;
        $results = [];
        foreach ($ids as $entryId) {
            try {
                if ($isApprove) {
                    timeApproveEntry($entryId, $user, 'bulk_review');
                } else {
                    timeRejectEntry($entryId, $user, (string) $body['reason']);
                }
                $succeeded++;
                $results[] = ['id' => $entryId, 'ok' => true];
            } catch (\Throwable $e) {
                $failed++;
                $results[] = ['id' => $entryId, 'ok' => false, 'reason' => $e->getMessage()];
            }
        }
        api_ok([
            'ok' => true,
            'action' => $action,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $entry = timeEntryGet($id);
    if (!$entry) api_error('Entry not found', 404);

    if ($action === 'submit') {
        if ($entry['status'] !== 'draft') api_error('Only draft entries can be submitted', 409, ['status' => $entry['status']]);
        scopedUpdate('time_entries', $id, ['status' => 'pending_review']);
        timeAudit('time.entry.submitted', ['entry_id' => $id], $id);
        api_ok(['ok' => true, 'entry' => timeEntryGet($id)]);
    }

    if ($action === 'approve') {
        rbac_legacy_require($user, 'time.approve');
        try {
            $approvedEntry = timeApproveEntry($id, $user, 'manual');
        } catch (\RuntimeException $e) {
            api_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 422);
        }
        api_ok(['ok' => true, 'entry' => $approvedEntry]);
    }

    if ($action === 'reject') {
        rbac_legacy_require($user, 'time.reject');
        $body = api_json_body();
        api_require_fields($body, ['reason']);
        try {
            $rejectedEntry = timeRejectEntry($id, $user, (string) $body['reason']);
        } catch (\RuntimeException $e) {
            api_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 422);
        }
        api_ok(['ok' => true, 'entry' => $rejectedEntry]);
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
        timeAudit('time.entry.superseded', ['entry_id' => $id, 'by_entry_id' => $newId, 'reason' => $body['correction_reason']], $id);
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
    $placementsTenantId = effectiveTenantIdForModule('placements', (int) ($ctx['tenant_id'] ?? currentTenantId())) ?? currentTenantId();
    $placementStmt = getDB()->prepare(
        'SELECT id, person_id, start_date, end_date FROM placements
         WHERE tenant_id = :placements_tid AND id = :id AND deleted_at IS NULL'
    );
    $placementStmt->execute(['id' => (int) $body['placement_id'], 'placements_tid' => $placementsTenantId]);
    $placement = $placementStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
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
        'status'             => $body['status']      ?? 'draft',
        'created_by_user_id' => $user['id'] ?? null,
    ]);
    timeAudit('time.entry.created', ['entry_id' => $id, 'placement_id' => (int) $body['placement_id'], 'category' => $body['category']], $id);
    api_ok(['entry' => timeEntryGet($id)], 201);
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
    foreach (['id','tenant_id','placement_id','person_id','period_id',
              'rate_snapshot_id','approved_by_user_id','approved_at','approved_via',
              'superseded_by_id','created_by_user_id','created_at'] as $k) unset($body[$k]);
    if (isset($body['category']) && !in_array($body['category'], TIME_CATEGORIES, true)) api_error('Invalid category', 422);
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('time_entries', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    timeAudit('time.entry.updated', ['entry_id' => $id, 'fields' => array_keys($body)], $id);
    api_ok(['entry' => timeEntryGet($id)]);
}

api_error('Method not allowed', 405);
