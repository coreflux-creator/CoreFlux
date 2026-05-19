<?php
/**
 * AP API — bill evidence bundle (C4).
 *
 *   GET  /api/ap/bill_evidence?bill_id=N
 *   POST /api/ap/bill_evidence?action=build&bill_id=N
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/evidence_bundle.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$tenantId  = (int) $ctx['tenant_id'];
$method    = api_method();
$action    = (string) (api_query('action') ?? '');
$billId    = (int) (api_query('bill_id') ?? 0);
if (!$billId) api_error('bill_id required', 422);

if ($method === 'GET') {
    rbac_legacy_require($user, 'ap.view');
    $row = apGetEvidenceBundle($tenantId, $billId);
    if (!$row) api_error('Bundle not built yet — POST ?action=build first', 404);
    api_ok(['bill_id' => $billId, 'evidence' => $row]);
}

if ($method === 'POST' && $action === 'build') {
    rbac_legacy_require($user, 'ap.bill.create');
    $row = apBuildEvidenceBundle($tenantId, $billId, (int) ($user['id'] ?? 0));
    if (!$row) api_error('Bill not found', 404);
    api_ok(['bill_id' => $billId, 'evidence' => $row]);
}

api_error('Unknown method/action', 405);
