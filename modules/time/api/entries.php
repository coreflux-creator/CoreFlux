<?php
/**
 * Time API — entries (the atomic record)
 *
 *   GET    /api/time/entries                    → list with filters
 *   GET    /api/time/entries?id=N               → get one
 *   POST   /api/time/entries                    → create draft
 *   PATCH  /api/time/entries?id=N               → update (draft/pending_review/rejected only)
 *   POST   /api/time/entries?action=bulk_create  → create draft[] atomically
 *   POST   /api/time/entries?action=submit&id=N   → draft → pending_review
 *   POST   /api/time/entries?action=bulk_submit   → draft[] → pending_review atomically
 *   POST   /api/time/entries?action=approve&id=N  → pending_review → approved (locks rate_snapshot_id)
 *   POST   /api/time/entries?action=reject&id=N   → pending_review → rejected
 *   POST   /api/time/entries?action=correct&id=N  → create new entry, old → superseded
 *
 * SPEC: /app/modules/time/SPEC.md §5.1, §9
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/time.php';
require_once __DIR__ . '/../../staffing/lib/timesheets.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

function timeApiDate(mixed $value, string $label = 'work_date'): string
{
    $value = trim((string) $value);
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        api_error("{$label} must be a valid YYYY-MM-DD date", 422);
    }
    return $value;
}

function timeApiHours(mixed $value): float
{
    if (!is_numeric($value)) api_error('hours must be numeric', 422);
    $hours = (float) $value;
    if (!is_finite($hours) || $hours <= 0 || $hours > 24) {
        api_error('hours must be greater than 0 and no more than 24', 422);
    }
    return $hours;
}

function timeApiPlacement(int $placementId, string $workDate): array
{
    $placementsTenantId = effectiveTenantIdForModule('placements', currentTenantId()) ?? currentTenantId();
    $stmt = getDB()->prepare(
        'SELECT id, person_id, start_date, end_date FROM placements
         WHERE tenant_id = :placements_tid AND id = :id AND deleted_at IS NULL'
    );
    $stmt->execute(['id' => $placementId, 'placements_tid' => $placementsTenantId]);
    $placement = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    if (!$placement) api_error('placement_id not found in this tenant', 422);
    if ($workDate < (string) $placement['start_date']) {
        api_error('work_date precedes placement start_date', 422);
    }
    if (!empty($placement['end_date']) && $workDate > (string) $placement['end_date']) {
        api_error('work_date is after placement end_date', 422);
    }
    return $placement;
}

function timeApiOpenPeriodId(string $workDate): int
{
    try {
        return timeOpenPeriodIdForDate($workDate);
    } catch (\RuntimeException $e) {
        api_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 409);
    }
}

function timeApiAssertDailyHours(int $personId, string $workDate, float $hours, int $excludeEntryId = 0): void
{
    $params = ['pid' => $personId, 'wd' => $workDate];
    $exclude = '';
    if ($excludeEntryId > 0) {
        $exclude = ' AND id != :exclude_id';
        $params['exclude_id'] = $excludeEntryId;
    }
    $sum = scopedFind(
        "SELECT COALESCE(SUM(hours), 0) AS h FROM time_entries
         WHERE tenant_id = :tenant_id AND person_id = :pid AND work_date = :wd
           AND status != 'superseded'{$exclude}",
        $params
    );
    if ((float) ($sum['h'] ?? 0) + $hours > 24.0) {
        api_error('Total hours across all entries for this person on this date would exceed 24', 422);
    }
}

function timeApiAssertNotSettled(array $entry): void
{
    foreach (['bill', 'ap', 'payroll'] as $target) {
        if (!empty($entry["{$target}_extracted_at"]) || !empty($entry["{$target}_extracted_ref"])) {
            api_error('This time entry is already used downstream. Correct or reverse the destination record before editing it.', 409);
        }
    }
}

function timeApiWeekBounds(string $workDate): array
{
    return timeWeekBounds($workDate);
}

function timeApiAssertWeekAcceptsDraft(int $personId, string $workDate): void
{
    [$start] = timeApiWeekBounds($workDate);
    $header = staffingTimesheetFind($personId, $start);
    if (!$header) return;
    $status = (string) ($header['status'] ?? 'draft');
    if (in_array($status, ['approved', 'payroll_ready', 'billing_ready', 'locked'], true)) {
        api_error(
            "The week of {$start} is {$status}. Use the correction workflow instead of adding draft time to a completed week.",
            409
        );
    }
}

function timeApiAssertPeriodAcceptsDraft(string $workDate): void
{
    $period = scopedFind(
        'SELECT id, status FROM time_periods
         WHERE tenant_id = :tenant_id AND start_date <= :wd_lo AND end_date >= :wd_hi
         ORDER BY start_date DESC LIMIT 1',
        ['wd_lo' => $workDate, 'wd_hi' => $workDate]
    );
    if ($period && ($period['status'] ?? '') !== 'open') {
        api_error("The time period covering {$workDate} is {$period['status']} and cannot accept changes", 409);
    }
}

function timeApiEnsureDraftTimesheet(int $personId, string $workDate, int $userId): int
{
    [$start, $end] = timeApiWeekBounds($workDate);
    $header = staffingTimesheetFind($personId, $start);
    if (!$header) {
        $header = staffingTimesheetUpsert($personId, $start, $end);
    } elseif (in_array((string) ($header['status'] ?? ''), ['submitted', 'rejected'], true)) {
        $header = staffingTimesheetReopen($userId, (int) $header['id'], 'new draft time added');
    }
    if (($header['status'] ?? 'draft') !== 'draft') {
        throw new \RuntimeException("The week of {$start} is {$header['status']} and cannot accept draft time");
    }
    return (int) $header['id'];
}

function timeApiCategoryMeta(string $category): array
{
    return timeCategoryRowMeta($category);
}

function timeApiNormaliseNewEntry(array $body, array $user, bool $forceDraft = false): array
{
    foreach (['placement_id', 'work_date', 'category', 'hours'] as $field) {
        if (!array_key_exists($field, $body) || $body[$field] === '') {
            api_error("{$field} required", 422);
        }
    }
    $category = (string) $body['category'];
    if (!in_array($category, TIME_CATEGORIES, true)) {
        api_error('Invalid category', 422, ['allowed' => TIME_CATEGORIES]);
    }
    $workDate = timeApiDate($body['work_date']);
    $hours = timeApiHours($body['hours']);
    $placement = timeApiPlacement((int) $body['placement_id'], $workDate);
    $isSelf = (int) $placement['person_id'] === (int) ($user['person_id'] ?? 0);
    rbac_legacy_require($user, $isSelf ? 'time.entry.self' : 'time.entry.manage');

    $source = (string) ($body['source'] ?? 'manual_entry');
    if (!in_array($source, ['ai_inbox', 'bulk_upload', 'manual_entry', 'client_portal_paste'], true)) {
        api_error('Invalid source', 422);
    }
    $status = $forceDraft ? 'draft' : (string) ($body['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'pending_review'], true)) {
        api_error('New time entries can only be draft or pending_review', 422);
    }
    $sourceRefId = $body['source_ref_id'] ?? null;
    if ($sourceRefId !== null && $sourceRefId !== '' && (!is_numeric($sourceRefId) || (int) $sourceRefId <= 0)) {
        api_error('source_ref_id must be a positive integer', 422);
    }
    timeApiAssertPeriodAcceptsDraft($workDate);
    timeApiAssertWeekAcceptsDraft((int) $placement['person_id'], $workDate);

    return [
        'placement_id' => (int) $placement['id'],
        'person_id' => (int) $placement['person_id'],
        'work_date' => $workDate,
        'category' => $category,
        'custom_category_id' => $body['custom_category_id'] ?? null,
        'hours' => $hours,
        'description' => isset($body['description']) ? trim((string) $body['description']) : null,
        'source' => $source,
        'source_ref_id' => ($sourceRefId === null || $sourceRefId === '') ? null : (int) $sourceRefId,
        'status' => $status,
    ] + timeApiCategoryMeta($category);
}

function timeApiInsertNewEntry(array $entry, array $user, bool $batch = false): array
{
    $periodId = timeApiOpenPeriodId($entry['work_date']);
    $timesheetId = timeApiEnsureDraftTimesheet(
        (int) $entry['person_id'],
        (string) $entry['work_date'],
        (int) ($user['id'] ?? 0)
    );
    $id = scopedInsert('time_entries', [
        'placement_id' => $entry['placement_id'],
        'person_id' => $entry['person_id'],
        'period_id' => $periodId,
        'timesheet_id' => $timesheetId,
        'work_date' => $entry['work_date'],
        'category' => $entry['category'],
        'hour_type' => $entry['hour_type'],
        'billable' => $entry['billable'],
        'payable' => $entry['payable'],
        'custom_category_id' => $entry['custom_category_id'],
        'hours' => $entry['hours'],
        'description' => $entry['description'],
        'source' => $entry['source'],
        'source_ref_id' => $entry['source_ref_id'],
        'status' => $entry['status'],
        'created_by_user_id' => $user['id'] ?? null,
    ]);
    timeAudit('time.entry.created', [
        'entry_id' => $id,
        'placement_id' => $entry['placement_id'],
        'category' => $entry['category'],
        'batch' => $batch,
    ], $id);
    return ['id' => $id, 'timesheet_id' => $timesheetId];
}

function timeApiSyncTimesheetTotals(array $timesheetIds): void
{
    foreach (array_values(array_unique(array_map('intval', $timesheetIds))) as $timesheetId) {
        if ($timesheetId <= 0) continue;
        timeReconcileTimesheetHeader($timesheetId);
    }
}

// ─── Actions (POST) ───
if ($method === 'POST' && $action !== '') {
    if ($action === 'bulk_create') {
        $body = api_json_body();
        $rows = is_array($body['entries'] ?? null) ? array_values($body['entries']) : [];
        if (!$rows) api_error('entries[] required', 422);
        if (count($rows) > 500) api_error('Too many entries (max 500 per batch)', 422);
        $documentId = (int) ($body['document_id'] ?? 0);
        if ($documentId > 0) {
            $document = scopedFind(
                'SELECT id, extraction_status FROM time_uploaded_documents
                 WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
                ['id' => $documentId]
            );
            if (!$document) api_error('Uploaded timesheet document not found', 404);
            if (($document['extraction_status'] ?? '') === 'consumed') {
                api_error('This uploaded timesheet has already been saved', 409);
            }
        }

        $normalised = [];
        $batchHours = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) api_error('Every entry must be an object', 422, ['row' => $index + 1]);
            $entry = timeApiNormaliseNewEntry($row, $user, true);
            if ($documentId > 0 && ($entry['source'] !== 'ai_inbox' || (int) ($entry['source_ref_id'] ?? 0) !== $documentId)) {
                api_error('Every uploaded entry must reference the same source document', 422, ['row' => $index + 1]);
            }
            $normalised[] = $entry;
            $key = $entry['person_id'] . '|' . $entry['work_date'];
            $batchHours[$key] = ($batchHours[$key] ?? 0.0) + (float) $entry['hours'];
        }

        foreach ($batchHours as $key => $newHours) {
            [$personId, $workDate] = explode('|', $key, 2);
            $current = scopedFind(
                "SELECT COALESCE(SUM(hours), 0) AS h FROM time_entries
                 WHERE tenant_id = :tenant_id AND person_id = :pid AND work_date = :wd
                   AND status != 'superseded'",
                ['pid' => (int) $personId, 'wd' => $workDate]
            );
            if ((float) ($current['h'] ?? 0) + $newHours > 24.0) {
                api_error("Total hours for person #{$personId} on {$workDate} would exceed 24", 422);
            }
        }

        $pdo = getDB();
        $pdo->beginTransaction();
        try {
            if ($documentId > 0) {
                $lock = $pdo->prepare(
                    'SELECT extraction_status FROM time_uploaded_documents
                     WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE'
                );
                $lock->execute(['tenant_id' => currentTenantId(), 'id' => $documentId]);
                $lockedStatus = $lock->fetchColumn();
                if ($lockedStatus === false) throw new \RuntimeException('Uploaded timesheet document not found');
                if ($lockedStatus === 'consumed') throw new \RuntimeException('This uploaded timesheet has already been saved');
            }
            foreach ($batchHours as $key => $newHours) {
                [$personId, $workDate] = explode('|', $key, 2);
                $lockHours = $pdo->prepare(
                    "SELECT hours FROM time_entries
                     WHERE tenant_id = :tenant_id AND person_id = :person_id AND work_date = :work_date
                       AND status != 'superseded' FOR UPDATE"
                );
                $lockHours->execute([
                    'tenant_id' => currentTenantId(),
                    'person_id' => (int) $personId,
                    'work_date' => $workDate,
                ]);
                $existingHours = array_sum(array_map('floatval', $lockHours->fetchAll(\PDO::FETCH_COLUMN)));
                if ($existingHours + $newHours > 24.0) {
                    throw new \RuntimeException("Total hours for person #{$personId} on {$workDate} would exceed 24");
                }
            }
            $created = [];
            $timesheetIds = [];
            foreach ($normalised as $entry) {
                $saved = timeApiInsertNewEntry($entry, $user, true);
                $created[] = $saved;
                $timesheetIds[] = (int) $saved['timesheet_id'];
            }
            timeApiSyncTimesheetTotals($timesheetIds);
            if ($documentId > 0) {
                $consume = $pdo->prepare(
                    'UPDATE time_uploaded_documents
                        SET extraction_status = "consumed", consumed_at = NOW(), consumed_entry_count = :count
                      WHERE tenant_id = :tenant_id AND id = :id'
                );
                $consume->execute([
                    'count' => count($created),
                    'tenant_id' => currentTenantId(),
                    'id' => $documentId,
                ]);
                timeAudit('time.upload.consumed', [
                    'document_id' => $documentId,
                    'entry_count' => count($created),
                    'atomic' => true,
                ], $documentId);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            api_error('No entries were saved: ' . $e->getMessage(), 422);
        }

        $entries = [];
        foreach ($created as $saved) {
            $row = timeEntryGet((int) $saved['id']);
            if ($row) $entries[] = $row;
        }
        api_ok([
            'ok' => true,
            'created' => count($created),
            'entry_ids' => array_column($created, 'id'),
            'timesheet_ids' => array_values(array_unique($timesheetIds)),
            'entries' => $entries,
            'document_consumed' => $documentId > 0,
        ], 201);
    }

    if ($action === 'submit' || $action === 'bulk_submit') {
        rbac_legacy_require_any($user, ['time.entry.self', 'time.entry.manage']);
        $body = $action === 'bulk_submit' ? api_json_body() : [];
        $ids = $action === 'bulk_submit'
            ? (is_array($body['ids'] ?? null) ? $body['ids'] : [])
            : [(int) api_query('id', 0)];
        if (!$ids || max(array_map('intval', $ids)) <= 0) {
            api_error($action === 'bulk_submit' ? 'ids[] required' : 'id required', 422);
        }
        try {
            $result = timeSubmitEntries($ids, $user, rbac_legacy_can($user, 'time.entry.manage'));
        } catch (\RuntimeException $e) {
            api_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 422);
        }
        api_ok(['ok' => true] + $result);
    }

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
        timeApiAssertNotSettled($entry);
        $body = api_json_body();
        api_require_fields($body, ['correction_reason']);

        $workDate = timeApiDate($body['work_date'] ?? $entry['work_date']);
        $category = (string) ($body['category'] ?? $entry['category']);
        if (!in_array($category, TIME_CATEGORIES, true)) {
            api_error('Invalid category', 422, ['allowed' => TIME_CATEGORIES]);
        }
        $hours = timeApiHours($body['hours'] ?? $entry['hours']);
        $placement = timeApiPlacement((int) $entry['placement_id'], $workDate);
        timeApiAssertDailyHours((int) $placement['person_id'], $workDate, $hours, $id);
        $periodId = timeApiOpenPeriodId($workDate);

        $pdo = getDB();
        $pdo->beginTransaction();
        try {
            // New draft entry inherits placement/person/period, default status=draft, source=manual_entry
            $newId = scopedInsert('time_entries', [
                'placement_id'       => (int) $entry['placement_id'],
                'person_id'          => (int) $entry['person_id'],
                'period_id'          => $periodId,
                'work_date'          => $workDate,
                'category'           => $category,
                'hours'              => $hours,
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
    $entry = timeApiNormaliseNewEntry($body, $user);
    timeApiAssertDailyHours((int) $entry['person_id'], (string) $entry['work_date'], (float) $entry['hours']);

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $lockHours = $pdo->prepare(
            "SELECT hours FROM time_entries
             WHERE tenant_id = :tenant_id AND person_id = :person_id AND work_date = :work_date
               AND status != 'superseded' FOR UPDATE"
        );
        $lockHours->execute([
            'tenant_id' => currentTenantId(),
            'person_id' => (int) $entry['person_id'],
            'work_date' => (string) $entry['work_date'],
        ]);
        $existingHours = array_sum(array_map('floatval', $lockHours->fetchAll(\PDO::FETCH_COLUMN)));
        if ($existingHours + (float) $entry['hours'] > 24.0) {
            throw new \RuntimeException('Total hours across all entries for this person on this date would exceed 24');
        }
        $saved = timeApiInsertNewEntry($entry, $user);
        timeApiSyncTimesheetTotals([(int) $saved['timesheet_id']]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_error('Entry was not saved: ' . $e->getMessage(), 422);
    }
    api_ok(['entry' => timeEntryGet((int) $saved['id'])], 201);
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
    timeApiAssertNotSettled($entry);
    $isSelf = (int) $entry['person_id'] === (int) ($user['person_id'] ?? 0);
    rbac_legacy_require($user, $isSelf ? 'time.entry.self' : 'time.entry.manage');

    $body = api_json_body();
    $allowed = ['work_date','category','hours','description','custom_category_id'];
    $update = array_intersect_key($body, array_flip($allowed));
    if (!$update) api_error('No editable fields supplied', 422);

    $workDate = timeApiDate($update['work_date'] ?? $entry['work_date']);
    $category = (string) ($update['category'] ?? $entry['category']);
    if (!in_array($category, TIME_CATEGORIES, true)) api_error('Invalid category', 422, ['allowed' => TIME_CATEGORIES]);
    $hours = timeApiHours($update['hours'] ?? $entry['hours']);
    $placement = timeApiPlacement((int) $entry['placement_id'], $workDate);
    timeApiAssertDailyHours((int) $placement['person_id'], $workDate, $hours, $id);

    $update['work_date'] = $workDate;
    $update['category'] = $category;
    $update['hours'] = $hours;
    $update['period_id'] = timeApiOpenPeriodId($workDate);
    if ($entry['status'] !== 'draft') {
        $update['status'] = 'draft';
        $update['rejected_reason'] = null;
    }
    scopedUpdate('time_entries', $id, $update);
    timeReconcileTimesheetHeader((int) ($entry['timesheet_id'] ?? 0));
    timeAudit('time.entry.updated', ['entry_id' => $id, 'fields' => array_keys($update)], $id);
    api_ok(['entry' => timeEntryGet($id)]);
}

api_error('Method not allowed', 405);
