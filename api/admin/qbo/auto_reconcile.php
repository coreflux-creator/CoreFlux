<?php
/**
 * GET  /api/admin/qbo/auto_reconcile.php
 *      → Returns the tenant's current auto-reconcile-paid-out-of-band flag.
 *        { enabled: bool }
 *
 * POST /api/admin/qbo/auto_reconcile.php
 *      Body: { enabled: bool }
 *      → Sets the flag. When true, the qbo_two_way_sync cron will close
 *        `paid_out_of_band` drift rows by automatically creating a
 *        matching billing_payment / ap_payment row and allocating it
 *        to the open CoreFlux invoice / bill.
 *
 *      Body (optional): { run_now: true }
 *      → Synchronously executes one auto-reconcile pass for the tenant
 *        and returns the counters. Useful from the Integration Triage UI
 *        when the operator just flipped the toggle.
 *
 * RBAC: master_admin / tenant_admin / wildcard.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../../core/qbo/client.php';
require_once __DIR__ . '/../../../core/qbo/auto_reconcile.php';

$ctx = api_require_auth();
rbac_legacy_require_any($currentUser ?? $ctx, ['master_admin', 'tenant_admin', '*']);

$tenantId = (int) ($ctx['tenant_id'] ?? 0);
$userId   = (int) ($ctx['user']['id'] ?? $ctx['user_id'] ?? 0);
if ($tenantId <= 0) { http_response_code(400); api_error('tenant required', 400); }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    api_ok([
        'enabled' => qboAutoReconcileEnabled($tenantId),
    ]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $enabled = !empty($body['enabled']);
    try {
        qboAutoReconcileSet($tenantId, $enabled, $userId);
    } catch (\Throwable $e) {
        http_response_code(400);
        api_error($e->getMessage(), 400);
    }
    $result = ['enabled' => $enabled];
    if (!empty($body['run_now']) && $enabled) {
        $result['run_now'] = qboAutoReconcileTenant($tenantId, $userId);
    }
    api_ok($result);
}

http_response_code(405);
api_error('GET or POST only', 405);
