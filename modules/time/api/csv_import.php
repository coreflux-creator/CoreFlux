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

use Core\CsvImportService;

CsvImportService::registerSchema('time', [
    'fields' => [
        'placement_external_id' => ['label' => 'Placement external ID', 'required' => true],
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
    'unique_within_batch' => ['external_id'],
]);

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

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

    // Resolve placement_external_id → placement_id in current tenant
    if ($result['rows']) {
        $exts = array_unique(array_filter(array_column($result['rows'], 'placement_external_id')));
        if ($exts) {
            $placeholders = implode(',', array_fill(0, count($exts), '?'));
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT external_id, id FROM placements
                                   WHERE tenant_id = ? AND deleted_at IS NULL AND external_id IN ({$placeholders})");
            // Placement lookup uses the *placements* module scope —
            // a sub-tenant under shared placement scope sees the
            // master's placements, so binding the raw session
            // tenant_id would always miss in that mode.
            $placementsTid = effectiveTenantIdForModule('placements') ?? currentTenantId();
            $stmt->execute(array_merge([$placementsTid], $exts));
            $map = [];
            foreach ($stmt as $r) $map[$r['external_id']] = (int) $r['id'];
            foreach ($result['rows'] as $rn => $row) {
                $ext = $row['placement_external_id'] ?? '';
                if ($ext && !isset($map[$ext])) {
                    $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                    $result['errors'][$rn][] = "placement_external_id: '{$ext}' not found in placements";
                }
            }
            $result['error_count'] = count($result['errors']);
        }
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

    $result = CsvImportService::commit('time', $csv, function (array $row) use ($user, $preApproved, $updateExisting) {
        $pl = scopedFind('SELECT id, person_id FROM placements WHERE tenant_id = :tenant_id AND external_id = :ext AND deleted_at IS NULL',
            ['ext' => $row['placement_external_id']]);
        if (!$pl) throw new \RuntimeException("placement not found: {$row['placement_external_id']}");

        // Resolve period
        $period = scopedFind(
            'SELECT id FROM time_periods WHERE tenant_id = :tenant_id AND start_date <= :wd AND end_date >= :wd AND status != "closed"
             ORDER BY start_date DESC LIMIT 1',
            ['wd' => $row['work_date']]
        );
        if (!$period) throw new \RuntimeException("No open period covers work_date {$row['work_date']}");

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
        if ($externalId !== null) {
            $existing = scopedFind(
                'SELECT id, status FROM time_entries
                  WHERE tenant_id = :tenant_id AND source_system = :s AND external_id = :e',
                ['s' => $sourceSystem, 'e' => $externalId]
            );
            if ($existing && $existing['status'] === 'approved') {
                throw new \RuntimeException("entry already approved — cannot update; void first");
            }
        }
        if (!$existing && $updateExisting) {
            $existing = scopedFind(
                'SELECT id, status FROM time_entries
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
            if ($existing && $existing['status'] === 'approved') {
                throw new \RuntimeException("entry already approved — cannot update; void first");
            }
        }

        $payload = [
            'placement_id'  => (int) $pl['id'],
            'person_id'     => (int) $pl['person_id'],
            'period_id'     => (int) $period['id'],
            'work_date'     => $row['work_date'],
            'external_id'   => $externalId,
            'source_system' => $sourceSystem,
            'category'      => $row['category'],
            'hours'         => (float) $row['hours'],
            'description'  => $row['description'] ?? null,
            'source'       => 'bulk_upload',
            'status'       => $preApproved ? 'approved' : 'pending_review',
        ];

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
        return $resultId;
    }, ['skip_invalid' => $skipInvalid, 'column_map' => $columnMap]);

    timeAudit('time.bulk.uploaded', [
        'entries_count'   => $result['imported_count'],
        'skipped'         => $result['skipped_count'],
        'pre_approved'    => $preApproved,
        'update_existing' => $updateExisting,
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
