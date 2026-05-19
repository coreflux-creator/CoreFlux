<?php
/**
 * Mercury Slice 4 — Reconciliation smoke.
 *
 * Coverage:
 *   - Migration 051: reconciliation_matches + funding_transfers tables,
 *     idempotent ALTER for payment_instructions.reconciled_at, UNIQUEs.
 *   - Service contract (mercury_reconciliation.php): mercuryReconcileOne
 *     match/discrepancy/missing branches, idempotent record_match upsert,
 *     graceful degrade when migration not applied.
 *   - API endpoint: 3 actions (stats / matches / run), RBAC split.
 *   - Worker contract.
 *   - UI: reconciliation tile + "Reconcile now" button.
 *   - Functional reconciliation pipeline via SQLite in-memory? NO — we
 *     don't have MySQL. So we test the matching LOGIC via pure-function
 *     contract checks + functional end-to-end via temp-DB-stub is OOS.
 *     Instead: validate exhaustive grep for state-handling branches.
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$c = fn (string $h, string $n): bool => strpos($h, $n) !== false;

// ----------------------------------------------------------------- Migration
echo "Migration 051_mercury_reconciliation.sql\n";
$migPath = __DIR__ . '/../core/migrations/051_mercury_reconciliation.sql';
$a('migration file exists', is_file($migPath));
$mig = (string) file_get_contents($migPath);
$a('reconciliation_matches table',               $c($mig, 'CREATE TABLE IF NOT EXISTS reconciliation_matches'));
$a('outcome ENUM (matched/discrepancy/missing)',
    $c($mig, "ENUM('matched','discrepancy','missing_mercury_txn')"));
$a('leg ENUM (funding/payout)',                  $c($mig, "ENUM('funding','payout')"));
$a('UNIQUE on (tenant, instruction, leg, outcome, txn_pk)',
    $c($mig, 'UNIQUE KEY uq_rm_instruction_leg (tenant_id, instruction_id, leg, outcome, mercury_txn_pk)'));
$a('expected vs observed amount columns',
    $c($mig, 'expected_amount_cents') && $c($mig, 'observed_amount_cents'));
$a('discrepancy_reason VARCHAR',                 $c($mig, 'discrepancy_reason     VARCHAR(255)'));
$a('funding_transfers table',                    $c($mig, 'CREATE TABLE IF NOT EXISTS funding_transfers'));
$a('funding_transfers UNIQUE per instruction',
    $c($mig, 'UNIQUE KEY uq_ft_instruction (tenant_id, instruction_id)'));
$a('payment_instructions.reconciled_at idempotent ALTER',
    $c($mig, "TABLE_NAME='payment_instructions' AND COLUMN_NAME='reconciled_at'")
    && $c($mig, 'ADD COLUMN reconciled_at DATETIME NULL AFTER payout_settled_at'));
$a('utf8mb4_unicode_ci (no 0900_ai_ci)',
    $c($mig, 'utf8mb4_unicode_ci') && stripos($mig, 'utf8mb4_0900_ai_ci') === false);

// ----------------------------------------------------------------- Service contract
echo "\ncore/mercury_reconciliation.php\n";
$svcPath = __DIR__ . '/../core/mercury_reconciliation.php';
$a('service file exists', is_file($svcPath));
$svc = (string) file_get_contents($svcPath);
$a('requires mercury_payments (for mpTransition)',
    $c($svc, "require_once __DIR__ . '/mercury_payments.php'"));
$a('mercuryReconcileTenant() exported',          $c($svc, 'function mercuryReconcileTenant'));
$a('mercuryReconcileOne() exported',             $c($svc, 'function mercuryReconcileOne'));
$a('mercuryRecordMatch() exported',              $c($svc, 'function mercuryRecordMatch'));
$a('mercuryUpsertFundingTransfer() exported',    $c($svc, 'function mercuryUpsertFundingTransfer'));
$a('mercuryReconciliationStats() exported',      $c($svc, 'function mercuryReconciliationStats'));
$a('mercuryReconciliationMatches() exported',    $c($svc, 'function mercuryReconciliationMatches'));
$a('only walks Settled+reconciled_at IS NULL',
    $c($svc, "state = \"Settled\" AND reconciled_at IS NULL"));
$a('oldest-first ordering for fairness',
    $c($svc, 'ORDER BY payout_settled_at ASC'));
$a('LIMIT 500 per pass (prevents runaway)',      $c($svc, 'LIMIT 500'));
$a('missing payout_mercury_txn_id branch',
    $c($svc, "'no payout_mercury_txn_id on instruction'"));
$a('missing mercury_transactions row branch',
    $c($svc, "'mercury_transactions row not yet synced (cron sync lag)'"));
$a('amount mismatch produces discrepancy',
    $c($svc, '$observed !== $expected'));
$a('currency mismatch produces discrepancy',
    $c($svc, 'strcasecmp($expectedCur, $observedCur) !== 0'));
$a('uses ABS for mercury signed amounts',        $c($svc, 'abs((int) $found[\'amount_cents\'])'));
$a('matched branch advances to Reconciled',
    $c($svc, "mpTransition(\$tenantId, \$piId, 'Reconciled'"));
$a('reconciled_at column set on transition',
    $c($svc, "'reconciled_at' => date('Y-m-d H:i:s')"));
$a('transition failure does not crash worker',
    $c($svc, '// If the transition fails'));
$a('record_match uses ON DUPLICATE KEY UPDATE (idempotent)',
    $c($svc, 'ON DUPLICATE KEY UPDATE') && $c($svc, 'matched_at            = NOW()'));
$a('funding_transfers upsert idempotent on instruction_id',
    $c($svc, 'INSERT INTO funding_transfers'));
$a('stats query computes settled_unreconciled + reconciled_total',
    $c($svc, 'state = "Settled"    AND reconciled_at IS NULL') &&
    $c($svc, 'state = "Reconciled"'));
$a('stats reports oldest_unreconciled',          $c($svc, "'oldest_unreconciled'"));
$a('matches list supports outcome filter allowlist',
    $c($svc, "['matched','discrepancy','missing_mercury_txn']"));
$a('graceful degrade when migration absent (try/catch)',
    substr_count($svc, '} catch (\Throwable $e) {') >= 3);
$a('NEVER hits Mercury (doc + verify)',
    $c($svc, 'NEVER hits Mercury') && !$c($svc, 'mercuryCall'));

// ----------------------------------------------------------------- API
echo "\napi/mercury_reconciliation.php\n";
$apiPath = __DIR__ . '/../api/mercury_reconciliation.php';
$a('API file exists', is_file($apiPath));
$api = (string) file_get_contents($apiPath);
$a('GET ?action=stats',                          $c($api, "\$action === 'stats'"));
$a('GET ?action=matches with outcome/instruction filter',
    $c($api, "\$action === 'matches'") &&
    $c($api, "!empty(\$_GET['instruction_id'])") &&
    $c($api, "!empty(\$_GET['outcome'])"));
$a('POST ?action=run gated by manage perm',
    $c($api, "\$action === 'run'") && $c($api, '!$canManage'));
$a('reads accept view OR manage perm',
    $c($api, "rbac_legacy_can(\$user, 'accounting.bank.view')") &&
    $c($api, "rbac_legacy_can(\$user, 'accounting.bank.manage')"));
$a('run emits mercury.reconciliation.run audit',  $c($api, 'mercury.reconciliation.run'));
$a('rejects other methods/actions',              $c($api, "Method/action not allowed"));

// ----------------------------------------------------------------- Worker
echo "\ncron/mercury_reconciliation.php\n";
$crPath = __DIR__ . '/../cron/mercury_reconciliation.php';
$a('worker exists', is_file($crPath));
$cr = (string) file_get_contents($crPath);
$a('worker selects only tenants with unreconciled Settled rows',
    $c($cr, 'state = "Settled" AND reconciled_at IS NULL'));
$a('worker calls mercuryReconcileTenant per tenant',
    $c($cr, 'mercuryReconcileTenant($tid)'));
$a('graceful skip when migration absent',
    $c($cr, 'migration 051 not applied yet'));
$a('per-tenant try/catch (one bad tenant ≠ abort)',
    $c($cr, '$fail++'));
$a('exit code reflects per-tenant failures',     $c($cr, 'exit($fail > 0 ? 1 : 0)'));

// ----------------------------------------------------------------- UI
echo "\nUI — MercuryPayments.jsx reconciliation tile\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/MercuryPayments.jsx');
$a('reads stats via useApi',
    $c($ui, "useApi('/api/mercury_reconciliation.php?action=stats')"));
$a('runReconciliation POSTs to action=run',
    $c($ui, '/api/mercury_reconciliation.php?action=run'));
$a('reconciliation tile testid',                 $c($ui, 'data-testid="mercury-reconciliation-tile"'));
$a('Reconcile-now button testid',                $c($ui, 'data-testid="mercury-reconciliation-run-btn"'));
$a('4 KPI tiles wired',
    $c($ui, 'testid="recon-kpi-pending"') &&
    $c($ui, 'testid="recon-kpi-reconciled"') &&
    $c($ui, 'testid="recon-kpi-discrepancies"') &&
    $c($ui, 'testid="recon-kpi-missing"'));
$a('oldest-unreconciled lag display',            $c($ui, 'data-testid="mercury-reconciliation-lag"'));
$a('reloads list + reconStats after run',
    $c($ui, 'list.reload();') && $c($ui, 'reconStats.reload();'));
$a('ReconKpi helper exported',                   $c($ui, 'function ReconKpi('));

// ----------------------------------------------------------------- Syntax sanity
echo "\nSyntax sanity (php -l)\n";
foreach ([
    'core/mercury_reconciliation.php',
    'api/mercury_reconciliation.php',
    'cron/mercury_reconciliation.php',
] as $rel) {
    $out = []; $rc = 0;
    @exec('php -l ' . escapeshellarg(__DIR__ . '/../' . $rel) . ' 2>&1', $out, $rc);
    $a("php -l {$rel}", $rc === 0);
}

echo "\n=========================================\n";
// ----------------------------------------------------------------- Funding-leg reconciliation extension
echo "\nFunding-leg reconciliation (extension)\n";
$a('mercuryReconcileFundingLeg() exported',      $c($svc, 'function mercuryReconcileFundingLeg'));
$a('tenant engine fans out to funding-leg pass',
    $c($svc, 'mercuryReconcileFundingLeg($tenantId)'));
$a('tenant engine returns funding_matched + funding_discrepancies + funding_missing',
    $c($svc, "'funding_matched'") && $c($svc, "'funding_discrepancies'") && $c($svc, "'funding_missing'"));
$a('walks rows with non-empty funding_mercury_txn_id',
    $c($svc, 'funding_mercury_txn_id IS NOT NULL'));
$a('funding pass records leg=funding outcomes',
    $c($svc, "'funding', 'matched'") && $c($svc, "'funding', 'discrepancy'") && $c($svc, "'funding', 'missing_mercury_txn'"));
$a('funding amount mismatch reason',             $c($svc, 'funding amount mismatch'));
$a('funding currency mismatch reason',           $c($svc, 'funding currency mismatch'));
$a('funding leg DOES NOT drive state transitions',
    !preg_match('/mpTransition\([^;]*\bfunding\b/i', $svc));

echo "\n=========================================\n";
echo "Mercury Slice 4 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);