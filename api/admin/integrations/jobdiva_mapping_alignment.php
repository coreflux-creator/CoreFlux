<?php
/**
 * JobDiva mapping alignment cockpit API.
 *
 * GET  -> report canonical mappings, mirror-only payloads, graph issues.
 * POST action=repair_client_links -> backfill placements.client_id from the
 * canonical end-client company/staffing client bridge.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/jobdiva/mapping_alignment.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tid = (int) $ctx['tenant_id'];
$method = api_method();
$action = strtolower(str_replace('-', '_', (string) (api_query('action') ?? 'report')));

if ($method === 'GET') {
    rbac_legacy_require_any($user, [
        'tenant_admin.integrations',
        'integrations.jobdiva.view',
    ]);
    $limit = (int) (api_query('sample_limit') ?? 25);
    api_ok(jobdivaMappingAlignmentReport($tid, ['sample_limit' => $limit]));
}

if ($method === 'POST' && $action === 'repair_client_links') {
    rbac_legacy_require_any($user, [
        'tenant_admin.integrations',
        'integrations.jobdiva.manage',
    ]);
    $body = api_json_body();
    $limit = isset($body['limit']) ? (int) $body['limit'] : 500;
    $result = jobdivaMappingRepairStaffingClientLinks($tid, isset($user['id']) ? (int) $user['id'] : null, $limit);
    api_ok(['ok' => $result['failed'] === 0, 'repair' => $result]);
}

api_error('Method not allowed', 405);
