<?php
/** Shared CSV parsing and directory preview; writes belong to csv_intake.php. */
require_once __DIR__.'/../../../core/CsvImportService.php';
require_once __DIR__.'/../../../core/RBAC.php';
require_once __DIR__.'/native_review.php';
use Core\CsvImportService;

CsvImportService::registerSchema('time', [
    'fields' => [
        // Entry ID is intentionally omitted from the simple template but kept
        // mappable so a full export can be corrected and imported again.
        'entry_id'             => ['label' => 'Entry ID', 'type' => 'integer'],
        // CsvImportService's integer coercion accepts both PL-760 and 760.
        'placement_id'          => ['label' => 'Placement ID', 'type' => 'integer', 'reference_type' => 'placement'],
        // Kept for compatibility with older exports and upstream systems.
        'placement_external_id' => [
            'label' => 'Source placement ID (optional)',
            'aliases' => ['Placement external ID'],
        ],
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
        'source_approver_email' => ['label'=>'External approver email','type'=>'email'],
        'source_approved_at' => ['label'=>'External approval time'],
        'source_approval_reference' => ['label'=>'External approval reference'],
        'external_id'           => ['label' => 'External ID (source row)'],
        'source_system'         => ['label' => 'Source system',
                                    'enum' => ['manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','quanta','other']],
    ],
    'unique_within_batch' => ['entry_id', 'external_id'],
]);

function timeCsvRequireUpload(array $user): void
{
    rbac_legacy_require_any($user, ['time.bulk_upload', 'staffing.time.create', 'staffing.timesheets.write']);
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

function timeCsvPlacementCode($placementId): string
{
    return 'PL-' . (int) $placementId;
}

function timeCsvNormalizeRow(array $row): array
{
    $hourType = trim((string) ($row['hour_type'] ?? ''));
    if ($hourType === '') $hourType = timeCsvCategoryToHourType($row['category'] ?? null);
    $row['hour_type'] = $hourType;
    $row['category'] = timeCsvHourTypeToCategory($hourType);
    $row['_source_system_supplied'] = trim((string)($row['source_system']??''))!=='';
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
        'placements_tid' => StaffingNativeTimeEdit::owner('placements') ?? effectiveTenantIdForModule('placements') ?? currentTenantId(),
        'people_tid'     => StaffingNativeTimeEdit::owner('people') ?? effectiveTenantIdForModule('people') ?? currentTenantId(),
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
            AND (' . implode(' OR ', $or) . ')' . (StaffingNativeTimeEdit::active()?' FOR UPDATE':'')
    );
    $stmt->execute($params);

    $byId = [];
    $byExternal = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $placement) {
        $placement['id'] = (int) $placement['id'];
        $placement['person_id'] = (int) $placement['person_id'];
        $byId[$placement['id']] = $placement;
        if (!empty($placement['external_id'])) {
            $key=(string)$placement['external_id'];
            $byExternal[$key]=array_key_exists($key,$byExternal)?['ambiguous'=>true]:$placement;
        }
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
          WHERE tenant_id = ? AND id IN ({$holders})".(StaffingNativeTimeEdit::active()?' FOR UPDATE':'')
    );
    $stmt->execute(array_merge([staffingTimeWorkspace()], $ids));
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

        if (!empty($placementByExternal['ambiguous'])) {timeCsvAddError($result,(int)$rowNumber,'Source placement ID matches multiple placements; use the exact Core placement number.');continue;}
        if ($placementById && $placementByExternal && $placementById['id'] !== $placementByExternal['id']) {
            timeCsvAddError($result, (int) $rowNumber, 'Placement ID and Source placement ID refer to different placements');
            continue;
        }
        $placement = $placementById ?: $placementByExternal;
        if (!$placement) {
            if (!empty($row['placement_id'])) {
                timeCsvAddError($result, (int) $rowNumber, "Placement ID '{$row['placement_id']}' was not found");
            } elseif ($externalId !== '') {
                timeCsvAddError($result, (int) $rowNumber, "Source placement ID '{$externalId}' was not found");
            } else {
                timeCsvAddError($result, (int) $rowNumber, 'Placement ID is required');
            }
            continue;
        }

        $row['placement_id'] = (int) $placement['id'];
        $row['placement_display_id'] = timeCsvPlacementCode($placement['id']);
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

function timeCsvRefreshTimesheet(int $timesheetId, int $userId): array
{
    timeReconcileTimesheetHeader($timesheetId, $userId);
    return staffingTimesheetRecordArtifactEvent($timesheetId, 'timesheet.entries_imported', $userId, [
        'source' => 'bulk_upload',
    ]);
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
        'placements_tid' => StaffingNativeTimeEdit::owner('placements') ?? effectiveTenantIdForModule('placements') ?? currentTenantId(),
        'people_tid'     => StaffingNativeTimeEdit::owner('people') ?? effectiveTenantIdForModule('people') ?? currentTenantId(),
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

