<?php
/**
 * Time API — CSV bulk upload (HARD_RULES: every primary-entity module MUST).
 * SPEC §5.2, §13 Phase B — ships with Phase A since Core\CsvImportService
 * already exists. Supports already_approved flag (requires time.approve).
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../lib/time.php';
require_once __DIR__ . '/../../staffing/lib/timesheets.php';

use Core\CsvImportService;

CsvImportService::registerSchema('time', [
    'fields' => [
        'entry_id'             => ['label' => 'Entry ID',             'type' => 'integer'],
        // placement_id is the reliable round-trip key from the Placements
        // export. External ID remains available for upstream system feeds.
        // At least one is required per row and validated below.
        'placement_id'          => ['label' => 'Placement ID',          'type' => 'integer'],
        'placement_external_id' => ['label' => 'Placement external ID'],
        'work_date'             => ['label' => 'Work date',             'required' => true, 'type' => 'date'],
        // external_id + source_system: stable per-row id from the
        // source-of-truth timesheet system (JobDiva, Beeline, ATS, etc.)
        // — becomes the upsert key so daily re-imports refresh the
        // same row instead of duplicating. Optional but strongly
        // recommended for any system-driven feed.
        'external_id'           => ['label' => 'External ID (audit / integration)'],
        'source_system'         => ['label' => 'Source system',
                                    'enum'  => ['manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other']],
        'category'               => ['label' => 'Category',              'required' => true,
                                     'enum' => ['regular_billable','regular_nonbillable','OT_billable','OT_nonbillable',
                                                'holiday','vacation','sick','bereavement','unpaid_leave']],
        'hours'                  => ['label' => 'Hours',                 'required' => true, 'type' => 'number'],
        'description'            => ['label' => 'Description'],
    ],
    'unique_within_batch' => ['entry_id', 'external_id'],
]);

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

function timeCsvTimesheetId(int $personId, string $workDate): int
{
    [$periodStart, $periodEnd] = timeWeekBounds($workDate);
    $header = staffingTimesheetFind($personId, $periodStart);
    if ($header && in_array((string) ($header['status'] ?? ''), ['approved', 'payroll_ready', 'billing_ready', 'locked'], true)) {
        throw new \RuntimeException(
            "The week of {$periodStart} is {$header['status']}; import a correction instead of changing a completed week"
        );
    }
    if (!$header) $header = staffingTimesheetUpsert($personId, $periodStart, $periodEnd);
    return (int) $header['id'];
}

if ($method === 'GET' && $action === 'template') {
    rbac_legacy_require($user, 'time.bulk_upload');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="time_template.csv"');
    echo CsvImportService::buildTemplate('time');
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    rbac_legacy_require($user, 'time.bulk_upload');
    $samples = require __DIR__ . '/../../../core/csv_samples.php';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="time_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('time', $samples['time'] ?? []);
    exit;
}


if ($method === 'POST' && $action === 'inspect') {
    rbac_legacy_require($user, 'time.bulk_upload');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('time', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    rbac_legacy_require($user, 'time.bulk_upload');
    require_once __DIR__ . '/../../../core/ai_csv_mapper.php';
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);

    // Read up to 3 sample rows alongside the header.
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $headers = fgetcsv($stream) ?: [];
    $samples = [];
    for ($i = 0; $i < 3; $i++) {
        $row = fgetcsv($stream);
        if ($row === false) break;
        $samples[] = $row;
    }
    fclose($stream);

    $body         = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $alreadyMap   = is_array($body['already_mapped'] ?? null) ? $body['already_mapped'] : [];

    $ins = CsvImportService::inspect('time', $csv);
    try {
        $result = aiSuggestColumnMap([
            'feature_key'    => 'csv.mapping.time',
            'entity_label'   => 'Time entries',
            'schema_fields'  => $ins['fields'],
            'headers'        => $headers,
            'sample_rows'    => $samples,
            'already_mapped' => $alreadyMap,
        ]);
    } catch (AIDisabledException $e) {
        api_error('AI is not enabled for this tenant: ' . $e->getMessage(), 503);
    } catch (\Throwable $e) {
        api_error('AI suggestion failed: ' . $e->getMessage(), 502);
    }
    api_ok($result);
}
if ($method === 'POST' && $action === 'dry_run') {
    rbac_legacy_require($user, 'time.bulk_upload');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $result = CsvImportService::dryRun('time', $csv, $columnMap);

    // Resolve placement_id / placement_external_id against the Placements
    // module's effective tenant and validate the work date before commit.
    if ($result['rows']) {
        $entryIds = [];
        $ids = [];
        $exts = [];
        foreach ($result['rows'] as $row) {
            if (isset($row['entry_id']) && is_int($row['entry_id']) && $row['entry_id'] > 0) {
                $entryIds[] = $row['entry_id'];
            }
            if (isset($row['placement_id']) && is_int($row['placement_id']) && $row['placement_id'] > 0) {
                $ids[] = $row['placement_id'];
            } elseif (trim((string) ($row['placement_external_id'] ?? '')) !== '') {
                $exts[] = trim((string) $row['placement_external_id']);
            }
        }
        $entryIds = array_values(array_unique($entryIds));
        $ids = array_values(array_unique($ids));
        $exts = array_values(array_unique($exts));
        $pdo = getDB();
        $placementsTid = effectiveTenantIdForModule('placements', (int) ($ctx['tenant_id'] ?? currentTenantId())) ?? currentTenantId();
        $byId = [];
        $byExternal = [];
        $entriesById = [];
        if ($entryIds) {
            $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
            $stmt = $pdo->prepare("SELECT id, placement_id, person_id, status,
                                          bill_extracted_at, ap_extracted_at, payroll_extracted_at
                                     FROM time_entries
                                    WHERE tenant_id = ? AND id IN ({$placeholders})");
            $stmt->execute(array_merge([(int) ($ctx['tenant_id'] ?? currentTenantId())], $entryIds));
            foreach ($stmt as $r) $entriesById[(int) $r['id']] = $r;
        }
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, external_id, start_date, end_date FROM placements
                                   WHERE tenant_id = ? AND deleted_at IS NULL AND id IN ({$placeholders})");
            $stmt->execute(array_merge([$placementsTid], $ids));
            foreach ($stmt as $r) $byId[(int) $r['id']] = $r;
        }
        if ($exts) {
            $placeholders = implode(',', array_fill(0, count($exts), '?'));
            $stmt = $pdo->prepare("SELECT id, external_id, start_date, end_date FROM placements
                                   WHERE tenant_id = ? AND deleted_at IS NULL AND external_id IN ({$placeholders})");
            $stmt->execute(array_merge([$placementsTid], $exts));
            foreach ($stmt as $r) $byExternal[(string) $r['external_id']] = $r;
        }
        foreach ($result['rows'] as $rn => $row) {
            $placement = null;
            $hasPlacementId = array_key_exists('placement_id', $row)
                && $row['placement_id'] !== '' && $row['placement_id'] !== null;
            if ($hasPlacementId && is_int($row['placement_id']) && $row['placement_id'] > 0) {
                $placement = $byId[$row['placement_id']] ?? null;
                if (!$placement) {
                    $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                    $result['errors'][$rn][] = "placement_id: {$row['placement_id']} not found in placements";
                }
            } elseif (!$hasPlacementId) {
                $ext = trim((string) ($row['placement_external_id'] ?? ''));
                if ($ext === '') {
                    $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                    $result['errors'][$rn][] = 'either placement_id or placement_external_id is required';
                } else {
                    $placement = $byExternal[$ext] ?? null;
                    if (!$placement) {
                        $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                        $result['errors'][$rn][] = "placement_external_id: '{$ext}' not found in placements";
                    }
                }
            }
            $workDate = (string) ($row['work_date'] ?? '');
            if ($placement && $workDate !== '') {
                if ($workDate < (string) $placement['start_date']) {
                    $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                    $result['errors'][$rn][] = "work_date precedes placement start_date {$placement['start_date']}";
                }
                if (!empty($placement['end_date']) && $workDate > (string) $placement['end_date']) {
                    $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                    $result['errors'][$rn][] = "work_date is after placement end_date {$placement['end_date']}";
                }
            }
            $entryId = isset($row['entry_id']) && is_int($row['entry_id']) ? (int) $row['entry_id'] : 0;
            if ($entryId > 0) {
                $existingEntry = $entriesById[$entryId] ?? null;
                if (!$existingEntry) {
                    $result['errors'][$rn][] = "entry_id: {$entryId} was not found in this workspace";
                } elseif (!in_array($existingEntry['status'], ['draft', 'pending_review', 'rejected'], true)) {
                    $result['errors'][$rn][] = "entry_id: {$entryId} is {$existingEntry['status']} and cannot be changed by CSV";
                } elseif (!empty($existingEntry['bill_extracted_at']) || !empty($existingEntry['ap_extracted_at']) || !empty($existingEntry['payroll_extracted_at'])) {
                    $result['errors'][$rn][] = "entry_id: {$entryId} has already been settled and cannot be changed by CSV";
                } elseif ($placement && (int) $existingEntry['placement_id'] !== (int) $placement['id']) {
                    $result['errors'][$rn][] = "entry_id: {$entryId} belongs to a different placement";
                }
            }
            $hours = (float) ($row['hours'] ?? 0);
            if ($hours <= 0 || $hours > 24) {
                $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                $result['errors'][$rn][] = 'hours must be greater than 0 and no more than 24';
            }
        }
        $result['error_count'] = count($result['errors']);
    }
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    rbac_legacy_require($user, 'time.bulk_upload');
    $preApproved = !empty($_GET['already_approved']);
    if ($preApproved) rbac_legacy_require($user, 'time.approve');

    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $skipInvalid    = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);
    $placementsTid = effectiveTenantIdForModule('placements', (int) ($ctx['tenant_id'] ?? currentTenantId())) ?? currentTenantId();

    $result = CsvImportService::commit('time', $csv, function (array $row) use ($user, $preApproved, $updateExisting, $placementsTid) {
        $pdo = getDB();
        $placementId = isset($row['placement_id']) && is_int($row['placement_id']) ? (int) $row['placement_id'] : 0;
        $placementExternalId = trim((string) ($row['placement_external_id'] ?? ''));
        if ($placementId <= 0 && $placementExternalId === '') {
            throw new \RuntimeException('either placement_id or placement_external_id is required');
        }
        $stmt = $pdo->prepare(
            'SELECT id, person_id, start_date, end_date FROM placements
              WHERE tenant_id = :tenant_id AND deleted_at IS NULL AND '
            . ($placementId > 0 ? 'id = :lookup' : 'external_id = :lookup') . ' LIMIT 1'
        );
        $stmt->execute([
            'tenant_id' => $placementsTid,
            'lookup' => $placementId > 0 ? $placementId : $placementExternalId,
        ]);
        $pl = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$pl) {
            throw new \RuntimeException($placementId > 0
                ? "placement_id not found: {$placementId}"
                : "placement_external_id not found: {$placementExternalId}");
        }
        if ($row['work_date'] < $pl['start_date']) {
            throw new \RuntimeException("work_date precedes placement start_date {$pl['start_date']}");
        }
        if (!empty($pl['end_date']) && $row['work_date'] > $pl['end_date']) {
            throw new \RuntimeException("work_date is after placement end_date {$pl['end_date']}");
        }
        $hours = (float) ($row['hours'] ?? 0);
        if ($hours <= 0 || $hours > 24) {
            throw new \RuntimeException('hours must be greater than 0 and no more than 24');
        }

        // Resolve/create the weekly containers so an import does not require
        // an operator to seed periods or repair disconnected time later.
        $periodId = timeOpenPeriodIdForDate((string) $row['work_date']);
        $timesheetId = timeCsvTimesheetId((int) $pl['person_id'], (string) $row['work_date']);

        $entryId      = isset($row['entry_id']) && is_int($row['entry_id']) ? (int) $row['entry_id'] : 0;
        $externalId   = isset($row['external_id'])   && $row['external_id']   !== '' ? (string) $row['external_id']   : null;
        $sourceSystem = isset($row['source_system']) && $row['source_system'] !== '' ? (string) $row['source_system'] : 'manual';

        // Update-existing: prefer (source_system, external_id) when the
        // row carries one (system-driven feeds reposting a corrected
        // entry); fall back to (placement, person, work_date, category)
        // for legacy / manual rows.
        // Only allow updating entries that are NOT yet approved — once approved
        // the entry is part of an audit-locked time bundle and must be voided
        // explicitly, not silently overwritten.
        $existing = null;
        if ($entryId > 0) {
            if (!$updateExisting) {
                throw new \RuntimeException("entry_id {$entryId} identifies an existing row; enable Update existing rows");
            }
            $existing = scopedFind(
                'SELECT id, status, placement_id, person_id, timesheet_id,
                        bill_extracted_at, ap_extracted_at, payroll_extracted_at
                   FROM time_entries
                  WHERE tenant_id = :tenant_id AND id = :id',
                ['id' => $entryId]
            );
            if (!$existing) throw new \RuntimeException("entry_id not found: {$entryId}");
            if ((int) $existing['placement_id'] !== (int) $pl['id'] || (int) $existing['person_id'] !== (int) $pl['person_id']) {
                throw new \RuntimeException("entry_id {$entryId} belongs to a different placement or person");
            }
        } elseif ($externalId !== null) {
            $existing = scopedFind(
                'SELECT id, status, placement_id, person_id, timesheet_id,
                        bill_extracted_at, ap_extracted_at, payroll_extracted_at
                   FROM time_entries
                  WHERE tenant_id = :tenant_id AND source_system = :s AND external_id = :e',
                ['s' => $sourceSystem, 'e' => $externalId]
            );
        }
        if (!$existing && $updateExisting) {
            $existing = scopedFind(
                'SELECT id, status, placement_id, person_id, timesheet_id,
                        bill_extracted_at, ap_extracted_at, payroll_extracted_at
                   FROM time_entries
                  WHERE tenant_id = :tenant_id
                    AND placement_id = :pl AND person_id = :p
                    AND work_date = :wd AND category = :cat',
                [
                    'pl'  => (int) $pl['id'],
                    'p'   => (int) $pl['person_id'],
                    'wd'  => $row['work_date'],
                    'cat' => $row['category'],
                ]
            );
        }
        if ($existing) {
            if (!in_array($existing['status'], ['draft', 'pending_review', 'rejected'], true)) {
                throw new \RuntimeException("entry is {$existing['status']} and cannot be updated by CSV");
            }
            if (!empty($existing['bill_extracted_at']) || !empty($existing['ap_extracted_at']) || !empty($existing['payroll_extracted_at'])) {
                throw new \RuntimeException('entry has already been settled and cannot be updated by CSV');
            }
        }

        $dailyHours = scopedFind(
            'SELECT COALESCE(SUM(hours), 0) AS h FROM time_entries
              WHERE tenant_id = :tenant_id AND person_id = :person_id AND work_date = :work_date
                AND status != "superseded"'
                . ($existing ? ' AND id != :existing_id' : ''),
            array_filter([
                'person_id' => (int) $pl['person_id'],
                'work_date' => $row['work_date'],
                'existing_id' => $existing ? (int) $existing['id'] : null,
            ], static fn ($value) => $value !== null)
        );
        if (((float) ($dailyHours['h'] ?? 0) + $hours) > 24.0) {
            throw new \RuntimeException('total hours for this person and work date would exceed 24');
        }

        $oldTimesheetId = (int) ($existing['timesheet_id'] ?? 0);
        $payload = [
            'placement_id'  => (int) $pl['id'],
            'person_id'     => (int) $pl['person_id'],
            'period_id'     => $periodId,
            'timesheet_id'  => $timesheetId,
            'work_date'     => $row['work_date'],
            'external_id'   => $externalId,
            'source_system' => $sourceSystem,
            'category'      => $row['category'],
            'hours'         => $hours,
            'description'  => $row['description'] ?? null,
            'source'       => 'bulk_upload',
            'status'       => $preApproved ? 'approved' : 'pending_review',
        ] + timeCategoryRowMeta((string) $row['category']);

        if ($preApproved) {
            $snap = timeResolveRateSnapshot((int) $pl['id'], $row['work_date']);
            if (!$snap) throw new \RuntimeException("No approved rate covers {$row['work_date']} for this placement");
            $payload['rate_snapshot_id']    = (int) $snap['id'];
            $payload['approved_by_user_id'] = $user['id'] ?? null;
            $payload['approved_at']         = date('Y-m-d H:i:s');
            $payload['approved_via']        = 'bulk_pre_approved';
        }

        if ($existing) {
            scopedUpdate('time_entries', (int) $existing['id'], $payload);
            $resultId = (int) $existing['id'];
        } else {
            $payload['created_by_user_id'] = $user['id'] ?? null;
            $resultId = scopedInsert('time_entries', $payload);
        }
        // Per-entry approval audit (P1.a). The CSV-pre-approved path
        // transitions entries straight to status='approved' without
        // going through the manual two-eye gate; emit a per-row audit
        // so downstream dashboards see consistent granularity.
        if ($preApproved) {
            timeEntryApprovedEmit($resultId, $payload, 'bulk_pre_approved', [
                'approver_user_id' => $user['id'] ?? null,
                'source'           => 'bulk_upload',
            ]);
        }
        timeReconcileTimesheetHeader($timesheetId);
        if ($oldTimesheetId > 0 && $oldTimesheetId !== $timesheetId) {
            timeReconcileTimesheetHeader($oldTimesheetId);
        }
        return $resultId;
    }, [
        'skip_invalid' => $skipInvalid,
        'column_map' => $columnMap,
        'atomic' => true,
    ]);

    timeAudit('time.bulk.uploaded', [
        'entries_count'   => $result['imported_count'],
        'skipped'         => $result['skipped_count'],
        'pre_approved'    => $preApproved,
        'update_existing' => $updateExisting,
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
