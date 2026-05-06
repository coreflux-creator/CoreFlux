<?php
/**
 * AP API — vendor risk (C3).
 *
 *   GET  /api/ap/vendor_risk?vendor_id=N         → cached risk evaluation
 *   POST /api/ap/vendor_risk?action=recompute&vendor_id=N
 *   GET  /api/ap/vendor_risk?action=high_risk    → all vendors at level=high
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/vendor_risk.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$tenantId  = (int) $ctx['tenant_id'];
$method    = api_method();
$action    = (string) (api_query('action') ?? '');

if ($method === 'GET' && $action === 'high_risk') {
    RBAC::requirePermission($user, 'ap.view');
    $rows = scopedQuery(
        "SELECT vr.*, v.name AS vendor_name
           FROM ap_vendor_risk vr
           LEFT JOIN ap_vendors v ON v.id = vr.vendor_id AND v.tenant_id = vr.tenant_id
          WHERE vr.tenant_id = :tenant_id AND vr.risk_level IN ('medium','high')
          ORDER BY vr.risk_score DESC"
    );
    api_ok(['high_risk' => $rows]);
}

if ($method === 'GET') {
    RBAC::requirePermission($user, 'ap.view');
    $vendorId = (int) (api_query('vendor_id') ?? 0);
    if (!$vendorId) api_error('vendor_id required', 422);
    $risk = apVendorRiskFor($tenantId, $vendorId);
    api_ok(['vendor_id' => $vendorId, 'risk' => $risk]);
}

if ($method === 'POST' && $action === 'recompute') {
    RBAC::requirePermission($user, 'ap.bills.approve_admin');
    $vendorId = (int) (api_query('vendor_id') ?? 0);
    if (!$vendorId) api_error('vendor_id required', 422);
    $risk = apVendorRiskRecompute($tenantId, $vendorId);
    api_ok(['vendor_id' => $vendorId, 'risk' => $risk]);
}

api_error('Unknown method/action', 405);
