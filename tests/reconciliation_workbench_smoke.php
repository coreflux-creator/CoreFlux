<?php
/**
 * Smoke — Reconciliation workbench (3-pane UI on top of the Slice 4
 * Mercury reconciliation engine).
 *
 * Locks in:
 *   1. New helper mercuryReconciliationUnmatched() exists, tenant-scoped,
 *      filters Settled+unreconciled, caps the LIMIT.
 *   2. /api/mercury_reconciliation.php exposes ?action=unmatched and
 *      ?action=workbench (one-shot for the UI).
 *   3. ReconciliationWorkbench.jsx renders three panes with the right
 *      data-testids and an empty state for each.
 *   4. TreasuryModule route + tab nav wired.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/mercury_reconciliation.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$svc  = (string) file_get_contents('/app/core/mercury_reconciliation.php');
$apiF = (string) file_get_contents('/app/api/mercury_reconciliation.php');
$ui   = (string) file_get_contents('/app/modules/treasury/ui/ReconciliationWorkbench.jsx');
$tmod = (string) file_get_contents('/app/modules/treasury/ui/TreasuryModule.jsx');

echo "\n1. Core helper — mercuryReconciliationUnmatched()\n";
$a('function exported', function_exists('mercuryReconciliationUnmatched'));
$a('tenant-scoped (WHERE pi.tenant_id = :t)',
    str_contains($svc, 'WHERE pi.tenant_id = :t'));
$a('filters Settled state',
    str_contains($svc, 'AND pi.state IN ("Settled")'));
$a('only NULL reconciled_at',
    str_contains($svc, 'AND pi.reconciled_at IS NULL'));
$a('orders oldest-unmatched first',
    str_contains($svc, 'ORDER BY pi.payout_settled_at ASC'));
$a('LIMIT bounded to 1..500',
    str_contains($svc, '(int) max(1, min(500, $limit))'));
$a('JOIN recipient name for the UI',
    str_contains($svc, 'mercury_recipients r ON r.id = pi.recipient_id'));

echo "\n2. API endpoint — new actions\n";
$a('?action=unmatched routes through helper',
    str_contains($apiF, "\$action === 'unmatched'")
    && str_contains($apiF, 'mercuryReconciliationUnmatched($tenantId, $limit)'));
$a('?action=workbench bundles stats+unmatched+matched+discrepancy',
    str_contains($apiF, "\$action === 'workbench'")
    && str_contains($apiF, "'stats'        => mercuryReconciliationStats(\$tenantId)")
    && str_contains($apiF, "'unmatched'    => mercuryReconciliationUnmatched(\$tenantId, 100)")
    && str_contains($apiF, "'matched'      => mercuryReconciliationMatches(\$tenantId, null, 'matched')")
    && str_contains($apiF, "'discrepancy'  => mercuryReconciliationMatches(\$tenantId, null, 'discrepancy')"));
$a('both new actions require accounting.bank.view',
    substr_count($apiF, 'if (!$canView) api_error') >= 4);

echo "\n3. UI — three-pane layout\n";
$a('section testid present',
    str_contains($ui, 'data-testid="reconciliation-workbench"'));
$a('Refresh + Run auto-match buttons',
    str_contains($ui, 'data-testid="reconciliation-refresh-btn"')
    && str_contains($ui, 'data-testid="reconciliation-run-btn"'));
$a('confirmation prompt before run',
    str_contains($ui, 'window.confirm(\'Run the auto-matcher'));
$a('stats grid rendered (4 tiles)',
    str_contains($ui, 'testid="recon-stat-unreconciled"')
    && str_contains($ui, 'testid="recon-stat-reconciled"')
    && str_contains($ui, 'testid="recon-stat-discrepancies"')
    && str_contains($ui, 'testid="recon-stat-oldest"'));
$a('three panes by data-testid',
    str_contains($ui, 'testid="recon-pane-unmatched"')
    && str_contains($ui, 'testid="recon-pane-discrepancy"')
    && str_contains($ui, 'testid="recon-pane-reconciled"'));
$a('per-pane row id pattern',
    str_contains($ui, '`recon-unmatched-${row.id}`')
    && str_contains($ui, '`recon-discrepancy-${row.id}`')
    && str_contains($ui, '`recon-matched-${row.id}`'));
$a('empty-state copy for each pane',
    str_contains($ui, 'testid="recon-empty-unmatched"')
    && str_contains($ui, 'testid="recon-empty-discrepancy"')
    && str_contains($ui, 'testid="recon-empty-reconciled"'));
$a('calls the workbench bundled endpoint',
    str_contains($ui, "/api/mercury_reconciliation.php?action=workbench"));
$a('post action=run on auto-match click',
    str_contains($ui, "/api/mercury_reconciliation.php?action=run"));
$a('amount formatted via Intl currency',
    str_contains($ui, "toLocaleString('en-US', { style: 'currency'"));

echo "\n4. TreasuryModule wiring\n";
$a('ReconciliationWorkbench imported',
    str_contains($tmod, "import ReconciliationWorkbench from './ReconciliationWorkbench'"));
$a('Route /reconciliation registered',
    str_contains($tmod, '<Route path="reconciliation"'));
$a('Treasury tab link added',
    str_contains($tmod, '<TreasuryTab to="reconciliation"'));

echo "\n5. PHP syntax\n";
foreach ([
    '/app/core/mercury_reconciliation.php',
    '/app/api/mercury_reconciliation.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Reconciliation workbench smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
