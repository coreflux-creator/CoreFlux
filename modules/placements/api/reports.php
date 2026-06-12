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
        placementsReportBuilderAudit($tenantId, $userId, 'expiring', 'placements.expiring_soon', $definition, $result, ['days' => $days]);
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
    try {
        $definition = placementsPresetDefinition('placements.active_by_client', $tenantId);
        $result = reportBuilderRunDefinition($definition, $tenantId);
        placementsReportBuilderAudit($tenantId, $userId, 'active_by_client', 'placements.active_by_client', $definition, $result);
        api_ok([
            'rows' => placementsActiveClientRowsFromReportBuilder($result),
            'source' => 'report_builder',
            'preset_key' => 'placements.active_by_client',
        ]);
    } catch (ReportBuilderException $e) {
        api_error($e->getMessage(), 422);
    }
}

api_error('Unknown report type. Use ?type=expiring or ?type=active_by_client', 400);

function placementsExpiringReportDefinition(int $days, int $tenantId): array
{
    $cutoff = date('Y-m-d', strtotime("+{$days} days"));
    $definition = placementsPresetDefinition('placements.expiring_soon', $tenantId);
    $definition['filters'][] = [
        'field' => 'expiring_date',
        'operator' => 'less_than_or_equal',
        'value' => $cutoff,
    ];
    return [$definition, $cutoff];
}

function placementsPresetDefinition(string $presetKey, int $tenantId): array
{
    $preset = reportBuilderPresetGet($presetKey, $tenantId);
    if (!$preset) {
        throw new ReportBuilderException("Placement report preset '{$presetKey}' is unavailable");
    }
    return (array) ($preset['definition'] ?? []);
}

function placementsReportBuilderAudit(int $tenantId, int $userId, string $reportType, string $presetKey, array $definition, array $result, array $extra = []): void
{
    reportBuilderAudit($tenantId, $userId ?: null, 'reports.custom.executed', null, array_merge([
        'dataset' => $definition['dataset'] ?? null,
        'columns' => array_column($result['columns'] ?? [], 'field'),
        'row_count' => $result['row_count'] ?? 0,
        'source_row_count' => $result['source_row_count'] ?? null,
        'source' => 'module_preset',
        'preset_key' => $presetKey,
        'module_id' => 'placements',
        'report_type' => $reportType,
    ], $extra));
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

function placementsActiveClientRowsFromReportBuilder(array $result): array
{
    $rows = [];
    foreach ((array) ($result['rows'] ?? []) as $row) {
        $client = $row['end_client_name'] ?? null;
        $rows[] = [
            'end_client_name' => $client === null ? '(unset)' : $client,
            'active_count' => (int) ($row['placement_count'] ?? 0),
        ];
    }
    return $rows;
}
