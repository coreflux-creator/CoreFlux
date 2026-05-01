<?php
/**
 * AP API — 1099-NEC ledger.
 *
 *   GET  /api/ap/1099?tax_year=YYYY        → current ledger rows
 *   POST /api/ap/1099?action=rebuild&tax_year=YYYY  → recomputes from cleared payments
 *
 * Phase A0 = ledger rollup only. Actual 1099-NEC PDF generation + IRS
 * e-file deferred to Phase A1/B.
 *
 * SPEC: /app/modules/ap/SPEC.md §5.5, §3.9.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/ap.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    RBAC::requirePermission($user, 'ap.1099.view');
    $year = (int) ($_GET['tax_year'] ?? date('Y'));
    if ($year < 2000 || $year > 2100) api_error('invalid tax_year', 422);
    $rows = scopedQuery(
        'SELECT id, tax_year, vendor_name, vendor_type, tax_id_last4, total_paid, requires_1099_nec, computed_at, submitted_to_irs_at
         FROM ap_1099_ledger
         WHERE tenant_id = :tenant_id AND tax_year = :y
         ORDER BY total_paid DESC',
        ['y' => $year]
    );
    $totals = array_reduce($rows, function ($acc, $r) {
        $acc['vendors']++;
        $acc['total_paid'] += (float) $r['total_paid'];
        if ($r['requires_1099_nec']) $acc['requires_nec']++;
        return $acc;
    }, ['vendors' => 0, 'total_paid' => 0.0, 'requires_nec' => 0]);
    api_ok(['tax_year' => $year, 'rows' => $rows, 'totals' => $totals]);
}

if ($method === 'POST' && $action === 'rebuild') {
    RBAC::requirePermission($user, 'ap.1099.generate');
    $year = (int) ($_GET['tax_year'] ?? date('Y'));
    if ($year < 2000 || $year > 2100) api_error('invalid tax_year', 422);
    $summary = apBuild1099Ledger($tid, $year);
    apAudit('ap.1099.ledger_built', ['tax_year' => $year, 'summary' => $summary], null);
    api_ok(['tax_year' => $year, 'summary' => $summary]);
}

api_error('Method not allowed', 405);
