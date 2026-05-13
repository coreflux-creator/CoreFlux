<?php
/**
 * Time API — CSV bulk upload (HARD_RULES: every primary-entity module MUST).
 * SPEC §5.2, §13 Phase B — ships with Phase A since Core\CsvImportService
 * already exists. Supports already_approved flag (requires time.approve).
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../lib/time.php';

use Core\CsvImportService;

CsvImportService::registerSchema('time', [
    'fields' => [
        'placement_external_id' => ['label' => 'Placement external ID', 'required' => true],
        'work_date'             => ['label' => 'Work date',             'required' => true, 'type' => 'date'],
        'category'               => ['label' => 'Category',              'required' => true,
                                     'enum' => ['regular_billable','regular_nonbillable','OT_billable','OT_nonbillable',
                                                'holiday','vacation','sick','bereavement','unpaid_leave']],
        'hours'                  => ['label' => 'Hours',                 'required' => true, 'type' => 'number'],
        'description'            => ['label' => 'Description'],
    ],
]);

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'template') {
    RBAC::requirePermission($user, 'time.bulk_upload');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="time_template.csv"');
    echo CsvImportService::buildTemplate('time');
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    RBAC::requirePermission($user, 'time.bulk_upload');
    $samples = require __DIR__ . '/../../../core/csv_samples.php';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="time_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('time', $samples['time'] ?? []);
    exit;
}


if ($method === 'POST' && $action === 'inspect') {
    RBAC::requirePermission($user, 'time.bulk_upload');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('time', $csv));
}
if ($method === 'POST' && $action === 'dry_run') {
    RBAC::requirePermission($user, 'time.bulk_upload');
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
            $stmt->execute(array_merge([currentTenantId()], $exts));
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
    RBAC::requirePermission($user, 'time.bulk_upload');
    $preApproved = !empty($_GET['already_approved']);
    if ($preApproved) RBAC::requirePermission($user, 'time.approve');

    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $skipInvalid = !empty($_GET['skip_invalid']);

    $result = CsvImportService::commit('time', $csv, function (array $row) use ($user, $preApproved) {
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

        $insert = [
            'placement_id' => (int) $pl['id'],
            'person_id'    => (int) $pl['person_id'],
            'period_id'    => (int) $period['id'],
            'work_date'    => $row['work_date'],
            'category'     => $row['category'],
            'hours'        => (float) $row['hours'],
            'description'  => $row['description'] ?? null,
            'source'       => 'bulk_upload',
            'status'       => $preApproved ? 'approved' : 'pending_review',
            'created_by_user_id' => $user['id'] ?? null,
        ];

        if ($preApproved) {
            $snap = timeResolveRateSnapshot((int) $pl['id'], $row['work_date']);
            if (!$snap) throw new \RuntimeException("No approved rate covers {$row['work_date']} for this placement");
            $insert['rate_snapshot_id']    = (int) $snap['id'];
            $insert['approved_by_user_id'] = $user['id'] ?? null;
            $insert['approved_at']         = date('Y-m-d H:i:s');
            $insert['approved_via']        = 'bulk_pre_approved';
        }
        return scopedInsert('time_entries', $insert);
    }, ['skip_invalid' => $skipInvalid, 'column_map' => $columnMap]);

    timeAudit('time.bulk.uploaded', [
        'entries_count' => $result['imported_count'],
        'skipped'       => $result['skipped_count'],
        'pre_approved'  => $preApproved,
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
