<?php
/**
 * Placements API — reports (Phase A: expiring + active-by-client).
 * SPEC §6.3.
 *
 *   GET /api/placements/reports?type=expiring&days=30
 *   GET /api/placements/reports?type=active_by_client
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/report_builder.php';
require_once __DIR__ . '/../lib/placements.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$userId = (int) ($user['id'] ?? 0);
if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'placements.view');

$type = $_GET['type'] ?? '';

if ($type === 'expiring') {
    $days = max(1, (int) ($_GET['days'] ?? 30));
    try {
        [$definition, $cutoff] = placementsExpiringReportDefinition($days, $tenantId);
        $result = reportBuilderRunDefinition($definition, $tenantId);
        reportBuilderAudit($tenantId, $userId ?: null, 'reports.custom.executed', null, [
            'dataset' => $definition['dataset'] ?? null,
            'columns' => array_column($result['columns'] ?? [], 'field'),
            'row_count' => $result['row_count'] ?? 0,
            'source' => 'module_preset',
            'preset_key' => 'placements.expiring_soon',
            'module_id' => 'placements',
            'report_type' => 'expiring',
            'days' => $days,
        ]);
        api_ok([
            'rows' => placementsRowsFromReportBuilder($result),
            'cutoff' => $cutoff,
            'days' => $days,
            'source' => 'report_builder',
            'preset_key' => 'placements.expiring_soon',
        ]);
    } catch (ReportBuilderException $e) {
        api_error($e->getMessage(), 422);
    }
}

if ($type === 'active_by_client') {
    $rows = scopedQuery(
        'SELECT COALESCE(end_client_name, "(unset)") AS end_client_name,
                COUNT(*) AS active_count
         FROM placements
         WHERE tenant_id = :tenant_id AND deleted_at IS NULL AND status = "active"
         GROUP BY end_client_name
         ORDER BY active_count DESC, end_client_name ASC'
    );
    api_ok(['rows' => $rows]);
}

api_error('Unknown report type. Use ?type=expiring or ?type=active_by_client', 400);

function placementsExpiringReportDefinition(int $days, int $tenantId): array
{
    $cutoff = date('Y-m-d', strtotime("+{$days} days"));
    $preset = reportBuilderPresetGet('placements.expiring_soon', $tenantId);
    if (!$preset) {
        throw new ReportBuilderException('Expiring placement report preset is unavailable');
    }
    $definition = (array) ($preset['definition'] ?? []);
    $definition['filters'][] = [
        'field' => 'expiring_date',
        'operator' => 'less_than_or_equal',
        'value' => $cutoff,
    ];
    return [$definition, $cutoff];
}

function placementsRowsFromReportBuilder(array $result): array
{
    $rows = [];
    foreach ((array) ($result['rows'] ?? []) as $row) {
        $rows[] = [
            'id' => (int) ($row['placement_id'] ?? 0),
            'title' => $row['title'] ?? null,
            'status' => $row['status'] ?? null,
            'start_date' => $row['start_date'] ?? null,
            'end_date' => $row['end_date'] ?? null,
            'due_date' => $row['due_date'] ?? null,
            'expiring_date' => $row['expiring_date'] ?? ($row['due_date'] ?? ($row['end_date'] ?? null)),
            'end_client_name' => $row['end_client_name'] ?? null,
            'engagement_type' => $row['engagement_type'] ?? null,
            'first_name' => $row['person_first_name'] ?? null,
            'last_name' => $row['person_last_name'] ?? null,
            'person_name' => $row['person_name'] ?? null,
            'email_primary' => $row['person_email'] ?? null,
        ];
    }
    return $rows;
}
