<?php
/**
 * Time API - CSV bulk import.
 *
 * Canonical input is one row per placement, date, and time type. The date may
 * be a daily work date or an upstream timesheet's week-ending date.
 * The placement supplies the person; the importer creates/links the weekly
 * staffing timesheet and time period so imported rows appear everywhere.
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
        // Entry ID is intentionally omitted from the simple template but kept
        // mappable so a full export can be corrected and imported again.
        'entry_id'             => ['label' => 'Entry ID', 'type' => 'integer'],
        'placement_id'          => ['label' => 'Placement ID', 'type' => 'integer'],
        // Kept for compatibility with older exports and upstream systems.
        'placement_external_id' => ['label' => 'Placement external ID'],
        'work_date'             => ['label' => 'Work date', 'required' => true, 'type' => 'date'],
        'hours'                 => ['label' => 'Hours', 'required' => true, 'type' => 'number'],
        'hour_type'             => ['label' => 'Time type',
                                    'enum' => ['regular','overtime','doubletime','holiday','pto','sick',
                                               'bereavement','unpaid','nonbillable']],
        // Legacy category remains mappable; hour_type is the canonical field.
        'category'              => ['label' => 'Category',
                                    'enum' => ['regular_billable','regular_nonbillable','OT_billable','OT_nonbillable',
                                               'holiday','vacation','sick','bereavement','unpaid_leave']],
        'description'           => ['label' => 'Description'],
        'external_id'           => ['label' => 'External ID (source row)'],
        'source_system'         => ['label' => 'Source system',
                                    'enum' => ['manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other']],
    ],
    'unique_within_batch' => ['entry_id', 'external_id'],
]);

function timeCsvRequireUpload(array $user): void
{
    rbac_legacy_require_any($user, ['time.bulk_upload', 'staffing.time.create', 'staffing.timesheets.write']);
}

function timeCsvRequireApprove(array $user): void
{
    rbac_legacy_require_any($user, ['time.approve', 'staffing.time.approve']);
}

function timeCsvStream(string $filename, array $headers, array $rows = []): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, $headers, ',', '"', '');
    foreach ($rows as $row) fputcsv($fp, $row, ',', '"', '');
    fclose($fp);
    exit;
}

function timeCsvCategoryToHourType(?string $category): string
{
    return match ((string) $category) {
        'OT_billable', 'OT_nonbillable' => 'overtime',
        'holiday'                       => 'holiday',
        'vacation'                      => 'pto',
        'sick'                          => 'sick',
        'bereavement'                   => 'bereavement',
        'unpaid_leave'                  => 'unpaid',
        'regular_nonbillable'           => 'nonbillable',
        default                         => 'regular',
    };
}

function timeCsvHourTypeToCategory(string $hourType): string
{
    return STAFFING_HOUR_TYPE_TO_CATEGORY[$hourType] ?? 'regular_billable';
}

function timeCsvNormalizeRow(array $row): array
{
    $hourType = trim((string) ($row['hour_type'] ?? ''));
    if ($hourType === '') $hourType = timeCsvCategoryToHourType($row['category'] ?? null);
    $row['hour_type'] = $hourType;
    $row['category'] = timeCsvHourTypeToCategory($hourType);
    $row['source_system'] = trim((string) ($row['source_system'] ?? '')) ?: 'manual';
    return $row;
}

/** @return array{by_id: array<int,array>, by_external: array<string,array>} */
function timeCsvPlacementLookup(array $rows): array
{
    $ids = [];
    $externalIds = [];
    foreach ($rows as $row) {
        if (is_int($row['placement_id'] ?? null) && $row['placement_id'] > 0) $ids[] = $row['placement_id'];
        $ext = trim((string) ($row['placement_external_id'] ?? ''));
        if ($ext !== '') $externalIds[] = $ext;
    }
    $ids = array_values(array_unique($ids));
    $externalIds = array_values(array_unique($externalIds));
    if (!$ids && !$externalIds) return ['by_id' => [], 'by_external' => []];

    // Placements and People can be shared from a parent workspace. Resolve
    // each module's effective tenant instead of assuming the Time tenant owns
    // those records.
    $params = [
        'placements_tid' => effectiveTenantIdForModule('placements') ?? currentTenantId(),
        'people_tid'     => effectiveTenantIdForModule('people') ?? currentTenantId(),
    ];
    $or = [];
    if ($ids) {
        $holders = [];
        foreach ($ids as $i => $id) {
            $key = 'pid_' . $i;
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }
        $or[] = 'p.id IN (' . implode(',', $holders) . ')';
    }
    if ($externalIds) {
        $holders = [];
        foreach ($externalIds as $i => $externalId) {
            $key = 'pext_' . $i;
            $holders[] = ':' . $key;
            $params[$key] = $externalId;
        }
        $or[] = 'p.external_id IN (' . implode(',', $holders) . ')';
    }

    $stmt = getDB()->prepare(
        'SELECT p.id, p.external_id, p.person_id, p.title, p.end_client_name,
                p.status AS placement_status, p.start_date, p.end_date, p.actual_end_date,
                CONCAT_WS(" ", pe.first_name, pe.last_name) AS person_name,
                pe.email_primary AS person_email
           FROM placements p
      LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = :people_tid
          WHERE p.tenant_id = :placements_tid
            AND p.deleted_at IS NULL
            AND (' . implode(' OR ', $or) . ')'
    );
    $stmt->execute($params);

    $byId = [];
    $byExternal = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $placement) {
        $placement['id'] = (int) $placement['id'];
        $placement['person_id'] = (int) $placement['person_id'];
        $byId[$placement['id']] = $placement;
        if (!empty($placement['external_id'])) $byExternal[(string) $placement['external_id']] = $placement;
    }
    return ['by_id' => $byId, 'by_external' => $byExternal];
}

function timeCsvAddError(array &$result, int $rowNumber, string $message): void
{
    $result['errors'][$rowNumber] = $result['errors'][$rowNumber] ?? [];
    $result['errors'][$rowNumber][] = $message;
}

/** @return array<int,array> */
function timeCsvEntryLookup(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        if (is_int($row['entry_id'] ?? null) && $row['entry_id'] > 0) $ids[] = $row['entry_id'];
    }
    $ids = array_values(array_unique($ids));
    if (!$ids) return [];

    $holders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = getDB()->prepare(
        "SELECT id, placement_id, person_id, status, work_date,
                bill_extracted_at, ap_extracted_at, payroll_extracted_at
           FROM time_entries
          WHERE tenant_id = ? AND id IN ({$holders})"
    );
    $stmt->execute(array_merge([(int) currentTenantId()], $ids));
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $entry) {
        $byId[(int) $entry['id']] = $entry;
    }
    return $byId;
}

function timeCsvDryRun(string $csv, ?array $columnMap): array
{
    $result = CsvImportService::dryRun('time', $csv, $columnMap);
    foreach ($result['rows'] as $rowNumber => $row) {
        $result['rows'][$rowNumber] = timeCsvNormalizeRow($row);
    }
    $placements = timeCsvPlacementLookup($result['rows']);
    $entries = timeCsvEntryLookup($result['rows']);
    $seen = [];

    foreach ($result['rows'] as $rowNumber => $row) {
        $placementById = null;
        if (is_int($row['placement_id'] ?? null) && $row['placement_id'] > 0) {
            $placementById = $placements['by_id'][$row['placement_id']] ?? null;
        }
        $externalId = trim((string) ($row['placement_external_id'] ?? ''));
        $placementByExternal = $externalId !== '' ? ($placements['by_external'][$externalId] ?? null) : null;

        if ($placementById && $placementByExternal && $placementById['id'] !== $placementByExternal['id']) {
            timeCsvAddError($result, (int) $rowNumber, 'Placement ID and Placement external ID refer to different placements');
            continue;
        }
        $placement = $placementById ?: $placementByExternal;
        if (!$placement) {
            if (!empty($row['placement_id'])) {
                timeCsvAddError($result, (int) $rowNumber, "placement_id: '{$row['placement_id']}' was not found");
            } elseif ($externalId !== '') {
                timeCsvAddError($result, (int) $rowNumber, "placement_external_id: '{$externalId}' was not found");
            } else {
                timeCsvAddError($result, (int) $rowNumber, 'Placement ID is required');
            }
            continue;
        }

        $row['placement_id'] = (int) $placement['id'];
        $row['placement_external_id'] = $placement['external_id'] ?? $externalId;
        $row['person_name'] = $placement['person_name'] ?: 'Person #' . $placement['person_id'];
        $row['person_email'] = $placement['person_email'] ?? '';
        $row['placement_title'] = $placement['title'] ?? '';
        $row['end_client_name'] = $placement['end_client_name'] ?? '';
        $result['rows'][$rowNumber] = $row;

        $entryId = is_int($row['entry_id'] ?? null) ? (int) $row['entry_id'] : 0;
        if ($entryId > 0) {
            $entry = $entries[$entryId] ?? null;
            if (!$entry) {
                timeCsvAddError($result, (int) $rowNumber, "entry_id: '{$entryId}' was not found in this workspace");
            } elseif ((int) $entry['placement_id'] !== (int) $placement['id']
                || (int) $entry['person_id'] !== (int) $placement['person_id']) {
                timeCsvAddError($result, (int) $rowNumber, "entry_id: '{$entryId}' belongs to a different placement or person");
            } elseif (!in_array((string) $entry['status'], ['draft', 'pending_review', 'rejected'], true)) {
                timeCsvAddError($result, (int) $rowNumber, "entry_id: '{$entryId}' is {$entry['status']} and cannot be changed by CSV");
            } elseif (!empty($entry['bill_extracted_at']) || !empty($entry['ap_extracted_at']) || !empty($entry['payroll_extracted_at'])) {
                timeCsvAddError($result, (int) $rowNumber, "entry_id: '{$entryId}' has already been settled and cannot be changed by CSV");
            }
        }

        if ((int) $placement['person_id'] <= 0) {
            timeCsvAddError($result, (int) $rowNumber, 'The placement is not linked to a person');
        }
        if (($placement['placement_status'] ?? '') === 'cancelled') {
            timeCsvAddError($result, (int) $rowNumber, 'The placement is cancelled');
        }
        $workDate = (string) ($row['work_date'] ?? '');
        if ($workDate !== '' && !empty($placement['start_date']) && $workDate < $placement['start_date']) {
            timeCsvAddError($result, (int) $rowNumber, "Work date is before placement start date {$placement['start_date']}");
        }
        $placementEnd = $placement['actual_end_date'] ?: $placement['end_date'];
        if ($workDate !== '' && $placementEnd && $workDate > $placementEnd) {
            timeCsvAddError($result, (int) $rowNumber, "Work date is after placement end date {$placementEnd}");
        }

        $hours = (float) ($row['hours'] ?? 0);
        if ($hours <= 0) timeCsvAddError($result, (int) $rowNumber, 'Hours must be greater than zero');
        if ($hours > 168) timeCsvAddError($result, (int) $rowNumber, 'Hours cannot exceed 168 for one imported row');

        if ($workDate !== '') {
            $key = $placement['id'] . '|' . $workDate . '|' . $row['hour_type'];
            if (isset($seen[$key])) {
                timeCsvAddError(
                    $result,
                    (int) $rowNumber,
                    "Duplicate placement/date/time type in this file (also row {$seen[$key]})"
                );
            } else {
                $seen[$key] = $rowNumber;
            }
        }
    }
    $result['error_count'] = count($result['errors']);
    return $result;
}

/** @return array{start:string,end:string} */
function timeCsvWeekBounds(string $workDate): array
{
    [$start, $end] = timeWeekBounds($workDate);
    return ['start' => $start, 'end' => $end];
}

function timeCsvEnsurePeriod(string $workDate, array $bounds): int
{
    return timeOpenPeriodIdForDate($workDate);
}

function timeCsvRefreshTimesheet(int $timesheetId, int $userId): void
{
    timeReconcileTimesheetHeader($timesheetId);
}

function timeCsvReferenceRows(): array
{
    $stmt = getDB()->prepare(
        'SELECT p.id AS placement_id, p.external_id AS placement_external_id,
                CONCAT_WS(" ", pe.first_name, pe.last_name) AS person_name,
                pe.email_primary AS person_email, p.title, p.end_client_name,
                p.status, p.start_date, COALESCE(p.actual_end_date, p.end_date) AS end_date
           FROM placements p
      LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = :people_tid
          WHERE p.tenant_id = :placements_tid AND p.deleted_at IS NULL
          ORDER BY FIELD(p.status, "active", "ended", "draft", "cancelled"), person_name, p.id'
    );
    $stmt->execute([
        'placements_tid' => effectiveTenantIdForModule('placements') ?? currentTenantId(),
        'people_tid'     => effectiveTenantIdForModule('people') ?? currentTenantId(),
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'template') {
    timeCsvRequireUpload($user);
    timeCsvStream('time_import_by_placement.csv', [
        'Placement ID', 'Work date', 'Hours', 'Time type', 'Description',
        'External ID (source row)', 'Source system',
    ]);
}

if ($method === 'GET' && $action === 'sample') {
    timeCsvRequireUpload($user);
    // This endpoint intentionally uses live placement IDs instead of the
    // generic CsvImportService::buildSample fixture, so the downloaded sample
    // validates in the tenant that requested it.
    $references = timeCsvReferenceRows();
    $sampleRows = [];
    foreach (array_slice($references, 0, 2) as $i => $placement) {
        $date = date('Y-m-d');
        if (!empty($placement['start_date']) && $date < $placement['start_date']) $date = $placement['start_date'];
        if (!empty($placement['end_date']) && $date > $placement['end_date']) $date = $placement['end_date'];
        $sampleRows[] = [
            $placement['placement_id'], $date, $i === 0 ? '8.00' : '7.50', 'regular',
            'Client work', 'sample-' . $placement['placement_id'] . '-' . $date, 'manual',
        ];
    }
    if (!$sampleRows) {
        $sampleRows[] = ['1001', date('Y-m-d'), '8.00', 'regular', 'Client work', 'source-row-001', 'manual'];
    }
    timeCsvStream('time_import_sample.csv', [
        'Placement ID', 'Work date', 'Hours', 'Time type', 'Description',
        'External ID (source row)', 'Source system',
    ], $sampleRows);
}

if ($method === 'GET' && $action === 'placement_reference') {
    timeCsvRequireUpload($user);
    $rows = array_map(static fn (array $row): array => array_values($row), timeCsvReferenceRows());
    timeCsvStream('placement_id_reference_' . date('Y-m-d') . '.csv', [
        'Placement ID', 'Placement external ID', 'Person name', 'Person email',
        'Title', 'End client', 'Status', 'Start date', 'End date',
    ], $rows);
}

if ($method === 'POST' && $action === 'inspect') {
    timeCsvRequireUpload($user);
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('time', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    timeCsvRequireUpload($user);
    require_once __DIR__ . '/../../../core/ai_csv_mapper.php';
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);

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

    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $alreadyMap = is_array($body['already_mapped'] ?? null) ? $body['already_mapped'] : [];
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
    } catch (Throwable $e) {
        api_error('AI suggestion failed: ' . $e->getMessage(), 502);
    }
    api_ok($result);
}

if ($method === 'POST' && $action === 'dry_run') {
    timeCsvRequireUpload($user);
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(timeCsvDryRun($csv, CsvImportService::readRequestColumnMap()));
}

if ($method === 'POST' && $action === 'commit') {
    timeCsvRequireUpload($user);
    $preApproved = !empty($_GET['already_approved']);
    if ($preApproved) timeCsvRequireApprove($user);

    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $skipInvalid = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);
    $dry = timeCsvDryRun($csv, $columnMap);

    if (!$skipInvalid && $dry['error_count'] > 0) {
        api_ok([
            'imported_count' => 0,
            'skipped_count'  => $dry['row_count'],
            'errors'         => $dry['errors'],
            'ids'            => [],
            'message'        => 'Validation errors present; commit aborted. Fix the rows or choose Skip invalid rows.',
        ]);
    }

    $placements = timeCsvPlacementLookup($dry['rows']);
    $errors = $dry['errors'];
    $ids = [];
    $imported = 0;
    $skipped = 0;
    $touchedTimesheets = [];
    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);

    try {
        foreach ($dry['rows'] as $rowNumber => $row) {
            if (isset($errors[$rowNumber])) {
                $skipped++;
                continue;
            }
            $pdo->exec('SAVEPOINT time_csv_row');
            try {
                $placement = $placements['by_id'][(int) $row['placement_id']] ?? null;
                if (!$placement) throw new RuntimeException("Placement #{$row['placement_id']} was not found");

                $workDate = (string) $row['work_date'];
                $hourType = (string) $row['hour_type'];
                $personId = (int) $placement['person_id'];
                $entryId = is_int($row['entry_id'] ?? null) ? (int) $row['entry_id'] : 0;
                $externalId = trim((string) ($row['external_id'] ?? '')) ?: null;
                $sourceSystem = (string) ($row['source_system'] ?? 'manual');
                $hours = (float) ($row['hours'] ?? 0);
                if ($hours <= 0 || $hours > 168) {
                    throw new RuntimeException('Hours must be greater than zero and cannot exceed 168');
                }

                $existing = null;
                if ($entryId > 0) {
                    if (!$updateExisting) {
                        throw new RuntimeException("Entry #{$entryId} identifies an existing row; keep Update existing rows enabled");
                    }
                    $existing = scopedFind(
                        'SELECT id, status, placement_id, person_id, work_date, timesheet_id,
                                bill_extracted_at, ap_extracted_at, payroll_extracted_at
                           FROM time_entries
                          WHERE tenant_id = :tenant_id AND id = :id',
                        ['id' => $entryId]
                    );
                    if (!$existing) throw new RuntimeException("Entry #{$entryId} was not found in this workspace");
                    if ((int) $existing['placement_id'] !== (int) $placement['id']
                        || (int) $existing['person_id'] !== $personId) {
                        throw new RuntimeException("Entry #{$entryId} belongs to a different placement or person");
                    }
                } elseif ($externalId !== null) {
                    $existing = scopedFind(
                        'SELECT id, status, placement_id, person_id, work_date, timesheet_id,
                                bill_extracted_at, ap_extracted_at, payroll_extracted_at
                           FROM time_entries
                           WHERE tenant_id = :tenant_id AND source_system = :s AND external_id = :e',
                        ['s' => $sourceSystem, 'e' => $externalId]
                    );
                    if ($existing && ((int) $existing['placement_id'] !== (int) $placement['id'] || $existing['work_date'] !== $workDate)) {
                        throw new RuntimeException('Source row ID already belongs to a different placement or date');
                    }
                }
                if (!$existing) {
                    $existing = scopedFind(
                        'SELECT id, status, placement_id, person_id, work_date, timesheet_id,
                                bill_extracted_at, ap_extracted_at, payroll_extracted_at
                           FROM time_entries
                           WHERE tenant_id = :tenant_id AND placement_id = :pl AND person_id = :p
                             AND work_date = :wd AND category = :cat AND status != "superseded"
                          ORDER BY id DESC LIMIT 1',
                        [
                            'pl'  => (int) $placement['id'],
                            'p'   => $personId,
                            'wd'  => $workDate,
                            'cat' => timeCsvHourTypeToCategory($hourType),
                        ]
                    );
                }
                if ($existing && !$updateExisting) {
                    throw new RuntimeException('An entry already exists for this placement, date, and time type; enable Update existing rows to replace it');
                }
                if ($existing && !in_array((string) $existing['status'], ['draft', 'pending_review', 'rejected'], true)) {
                    throw new RuntimeException("Entry #{$existing['id']} is {$existing['status']} and cannot be updated by CSV");
                }
                if ($existing && (!empty($existing['bill_extracted_at'])
                    || !empty($existing['ap_extracted_at'])
                    || !empty($existing['payroll_extracted_at']))) {
                    throw new RuntimeException("Entry #{$existing['id']} has already been settled and cannot be updated by CSV");
                }

                $bounds = timeCsvWeekBounds($workDate);
                $periodHours = scopedFind(
                    'SELECT COALESCE(SUM(hours), 0) AS hours
                       FROM time_entries
                      WHERE tenant_id = :tenant_id AND person_id = :person_id
                        AND work_date BETWEEN :period_start AND :period_end
                        AND status != "superseded"'
                        . ($existing ? ' AND id != :existing_id' : ''),
                    array_filter([
                        'person_id' => $personId,
                        'period_start' => $bounds['start'],
                        'period_end' => $bounds['end'],
                        'existing_id' => $existing ? (int) $existing['id'] : null,
                    ], static fn ($value) => $value !== null)
                );
                if (((float) ($periodHours['hours'] ?? 0) + $hours) > 168.0) {
                    throw new RuntimeException("The week of {$bounds['start']} would exceed 168 total hours");
                }
                if ($hours <= 24.0) {
                    $dailyHours = scopedFind(
                        'SELECT COALESCE(SUM(hours), 0) AS hours
                           FROM time_entries
                          WHERE tenant_id = :tenant_id AND person_id = :person_id
                            AND work_date = :work_date AND status != "superseded"'
                            . ($existing ? ' AND id != :existing_id' : ''),
                        array_filter([
                            'person_id' => $personId,
                            'work_date' => $workDate,
                            'existing_id' => $existing ? (int) $existing['id'] : null,
                        ], static fn ($value) => $value !== null)
                    );
                    if (((float) ($dailyHours['hours'] ?? 0) + $hours) > 24.0) {
                        throw new RuntimeException("Total daily time on {$workDate} would exceed 24 hours");
                    }
                }

                $rateSnapshot = null;
                if ($preApproved) {
                    $rateSnapshot = timeResolveRateSnapshot((int) $placement['id'], $workDate);
                    if (!$rateSnapshot) {
                        throw new RuntimeException("No approved rate covers {$workDate} for placement #{$placement['id']}");
                    }
                }

                $periodId = timeCsvEnsurePeriod($workDate, $bounds);
                $timesheet = staffingTimesheetUpsert($personId, $bounds['start'], $bounds['end']);
                $timesheetId = (int) $timesheet['id'];
                if (($timesheet['status'] ?? '') === 'locked') {
                    throw new RuntimeException("The week of {$bounds['start']} is locked");
                }
                if (!$preApproved && in_array($timesheet['status'] ?? '', ['approved','payroll_ready','billing_ready'], true)) {
                    throw new RuntimeException("The week of {$bounds['start']} is already {$timesheet['status']}; reopen it before importing a correction");
                }

                $category = timeCsvHourTypeToCategory($hourType);
                $payload = [
                    'placement_id'  => (int) $placement['id'],
                    'person_id'     => $personId,
                    'period_id'     => $periodId,
                    'timesheet_id'  => $timesheetId,
                    'work_date'     => $workDate,
                    'external_id'   => $externalId,
                    'source_system' => $sourceSystem,
                    'hour_type'     => $hourType,
                    'category'      => $category,
                    'billable'      => in_array($hourType, ['regular','overtime','doubletime'], true) ? 1 : 0,
                    'payable'       => 1,
                    'hours'         => $hours,
                    'description'   => trim((string) ($row['description'] ?? '')) ?: null,
                    'source'        => 'bulk_upload',
                    'status'        => $preApproved ? 'approved' : 'pending_review',
                ];
                if ($preApproved) {
                    $payload['rate_snapshot_id'] = (int) $rateSnapshot['id'];
                    $payload['approved_by_user_id'] = $user['id'] ?? null;
                    $payload['approved_at'] = date('Y-m-d H:i:s');
                    $payload['approved_via'] = 'bulk_pre_approved';
                }

                if ($existing) {
                    scopedUpdate('time_entries', (int) $existing['id'], $payload);
                    $resultId = (int) $existing['id'];
                    if (!empty($existing['timesheet_id'])) $touchedTimesheets[(int) $existing['timesheet_id']] = true;
                } else {
                    $payload['created_by_user_id'] = $user['id'] ?? null;
                    $resultId = (int) scopedInsert('time_entries', $payload);
                }
                $touchedTimesheets[$timesheetId] = true;
                $ids[$rowNumber] = $resultId;
                $imported++;

                if ($preApproved) {
                    timeEntryApprovedEmit($resultId, $payload, 'bulk_pre_approved', [
                        'approver_user_id' => $user['id'] ?? null,
                        'source'           => 'bulk_upload',
                    ]);
                }
                $pdo->exec('RELEASE SAVEPOINT time_csv_row');
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT time_csv_row');
                $errors[$rowNumber] = $errors[$rowNumber] ?? [];
                $errors[$rowNumber][] = 'Import skipped: ' . $e->getMessage();
                $skipped++;
            }
        }

        if (!$skipInvalid && $errors) {
            cf_tx_rollback($pdo, $ownsTxn);
            $result = [
                'imported_count' => 0,
                'skipped_count'  => $dry['row_count'],
                'errors'         => $errors,
                'ids'            => [],
                'timesheet_ids'  => [],
                'message'        => 'No rows were imported because one or more rows failed business validation.',
            ];
            timeAudit('time.bulk.uploaded', [
                'entries_count'   => 0,
                'skipped'         => $dry['row_count'],
                'timesheet_count' => 0,
                'pre_approved'    => $preApproved,
                'update_existing' => $updateExisting,
            ]);
            api_ok($result);
        }

        foreach (array_keys($touchedTimesheets) as $timesheetId) {
            timeCsvRefreshTimesheet((int) $timesheetId, (int) ($user['id'] ?? 0));
        }
        cf_tx_commit($pdo, $ownsTxn);
    } catch (Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        api_error('Time import failed: ' . $e->getMessage(), 500);
    }

    $result = [
        'imported_count' => $imported,
        'skipped_count'  => $skipped,
        'errors'         => $errors,
        'ids'            => $ids,
        'timesheet_ids'  => array_map('intval', array_keys($touchedTimesheets)),
    ];
    timeAudit('time.bulk.uploaded', [
        'entries_count'   => $imported,
        'skipped'         => $skipped,
        'timesheet_count' => count($touchedTimesheets),
        'pre_approved'    => $preApproved,
        'update_existing' => $updateExisting,
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|sample|placement_reference|inspect|dry_run|commit', 400);
