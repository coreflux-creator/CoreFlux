<?php
/**
 * Smoke — "rates still say draft" catch-up affordance + soft-skip
 * telemetry, AND payroll/billing readiness name JOIN fix.
 *
 * Operator reports after deploying the auto-approve work:
 *   "rates still say draft"               → existing placements were
 *      promoted BEFORE the side effect shipped, so they never received
 *      the auto-approve. Plus: when the soft-RBAC-skip fires (operator
 *      lacked `placements.financials.approve`), there was no audit
 *      breadcrumb explaining why nothing happened.
 *   "payroll readiness by number?"        → readiness UI shows
 *      "Person #5", "Person #7" instead of names. Root cause: same
 *      cross-tenant JOIN bug as placementGet — readiness JOINs people
 *      on `p.tenant_id = t.tenant_id` (timesheet's tenant), but in a
 *      sub-tenant under `'people' => 'shared'`, people live under the
 *      parent so the JOIN silently misses.
 *
 * Fixes locked in:
 *   - rate_approve.php emits a `placement.rates.auto_approve_skipped_no_permission`
 *     audit when the soft-RBAC skip fires.
 *   - rates.php exposes `?action=approve_all_for_placement` — a one-
 *     click catch-up for placements promoted pre-fix.
 *   - PlacementDetail RatesTab shows an "Approve all N drafts" button
 *     in the tab header when any draft rate exists.
 *   - staffing/api/readiness.php loads sub_tenants.php and binds
 *     people / placements / clients JOINs to
 *     `effectiveTenantIdForModule(...)`.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$ROOT = realpath(__DIR__ . '/..');
$rateAppr  = (string) file_get_contents("{$ROOT}/modules/placements/lib/rate_approve.php");
$ratesApi  = (string) file_get_contents("{$ROOT}/modules/placements/api/rates.php");
$detail    = (string) file_get_contents("{$ROOT}/modules/placements/ui/PlacementDetail.jsx");
$readiness = (string) file_get_contents("{$ROOT}/modules/staffing/api/readiness.php");

echo "\n1. Soft-skip telemetry — auto-approve helper audits the why\n";
$a('placement.rates.auto_approve_skipped_no_permission audit emitted',
   str_contains($rateAppr, "placementsAudit('placement.rates.auto_approve_skipped_no_permission'"));
$a('audit includes user_id + rbac function name in reason',
   str_contains($rateAppr, "'reason'       => 'rbac_legacy_can(placements.financials.approve)=false'")
   && str_contains($rateAppr, "'user_id'      => (int) (\$user['id'] ?? 0)"));

echo "\n2. Catch-up endpoint: ?action=approve_all_for_placement\n";
$a('rates.php declares the catch-up POST action',
   str_contains($ratesApi, "if (\$method === 'POST' && \$action === 'approve_all_for_placement')"));
$a('catch-up requires placements.financials.approve',
   (bool) preg_match("/action === 'approve_all_for_placement'.*?rbac_legacy_require\(\\\$user, 'placements\\.financials\\.approve'\)/s", $ratesApi));
$a('catch-up requires placement_id query param',
   (bool) preg_match("/action === 'approve_all_for_placement'.*?api_query\('placement_id', 0\)/s", $ratesApi));
$a('catch-up reuses placementsAutoApproveDraftRates helper',
   (bool) preg_match("/action === 'approve_all_for_placement'.*?placementsAutoApproveDraftRates\(\\\$pid, \\\$user\)/s", $ratesApi));
$a('catch-up emits placement.rates.approve_all_clicked audit',
   str_contains($ratesApi, "placementsAudit('placement.rates.approve_all_clicked'"));
$a('catch-up response returns approved count',
   str_contains($ratesApi, "api_ok(['ok' => true, 'approved' => \$count]);"));

echo "\n3. RatesTab UI — Approve all N drafts button\n";
$a('approveAllDrafts handler defined',
   str_contains($detail, 'const approveAllDrafts = async () => {'));
$a('handler POSTs to the catch-up endpoint',
   str_contains($detail, '/modules/placements/api/rates.php?action=approve_all_for_placement&placement_id=${pid}'));
$a('draftCount derived from rates filter',
   str_contains($detail, "const draftCount = rates.filter(r => !r.approved_at).length;"));
$a('button only renders when draftCount > 0',
   str_contains($detail, '{draftCount > 0 && (')
   && str_contains($detail, 'data-testid="rates-approve-all-drafts"'));
$a('button label pluralises correctly',
   str_contains($detail, "Approve all {draftCount} draft{draftCount === 1 ? '' : 's'}"));
$a('button has explanatory title tooltip',
   str_contains($detail, 'chain-based margin snapshot + audit as per-row approval'));

echo "\n4. Staffing readiness — fix cross-tenant JOIN drift\n";
$a('readiness.php requires core/sub_tenants.php',
   str_contains($readiness, "require_once __DIR__ . '/../../../core/sub_tenants.php';"));
$a('resolves people + placements effective tenants at top of file',
   str_contains($readiness, "\$peopleTid     = effectiveTenantIdForModule('people')     ?? currentTenantId();")
   && str_contains($readiness, "\$placementsTid = effectiveTenantIdForModule('placements') ?? currentTenantId();"));
$a('payroll JOIN binds p.tenant_id = :people_tid (not t.tenant_id)',
   str_contains($readiness, 'LEFT JOIN people p ON p.id = t.person_id AND p.tenant_id = :people_tid'));
$a('payroll JOIN no longer uses t.tenant_id for people',
   !preg_match('/LEFT JOIN people p ON p\.id = t\.person_id AND p\.tenant_id = t\.tenant_id/', $readiness));
$a('billing JOIN (with revenue) binds pl.tenant_id = :placements_tid',
   substr_count($readiness, 'JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = :placements_tid') >= 2);
$a('billing JOIN no longer uses t.tenant_id for placements',
   !preg_match('/JOIN placements pl ON pl\.id = te\.placement_id AND pl\.tenant_id = t\.tenant_id/', $readiness));
$a('clients JOIN binds c.tenant_id = :placements_tid_c',
   substr_count($readiness, 'LEFT JOIN staffing_clients c ON c.id = pl.client_id AND c.tenant_id = :placements_tid_c') >= 2);
$a('clients JOIN no longer uses t.tenant_id',
   !preg_match('/LEFT JOIN staffing_clients c ON c\.id = pl\.client_id AND c\.tenant_id = t\.tenant_id/', $readiness));
$a('payroll fallback "Person #N" path preserved as last-resort label',
   str_contains($readiness, "?: ('Person #' . \$pid)"));

echo "\n5. PHP syntax\n";
foreach ([
    'modules/placements/lib/rate_approve.php',
    'modules/placements/api/rates.php',
    'modules/staffing/api/readiness.php',
] as $rel) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    $a("php -l {$rel}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Catch-up approve + readiness name JOIN smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
