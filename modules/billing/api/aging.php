<?php
/**
 * Billing API — AR aging snapshot (computed on-read in Phase A0).
 *
 *   GET /api/billing/aging?as_of=YYYY-MM-DD
 *
 * SPEC: /app/modules/billing/SPEC.md §5.5.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/billing.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() === 'GET') {
    RBAC::requirePermission($user, 'billing.view');
    $asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) api_error('as_of must be YYYY-MM-DD', 422);
    api_ok(['as_of' => $asOf, 'rows' => billingComputeAging($tid, $asOf)]);
}
api_error('Method not allowed', 405);
