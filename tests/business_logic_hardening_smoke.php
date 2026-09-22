<?php
/** Regression gate for durable artifacts and cross-module business logic. */
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? "  ok    " : "  FAIL  ") . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$lint = static function (string $path) use ($root): bool {
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $path) . ' 2>&1', $out, $code);
    return $code === 0;
};

echo "Artifact identity and schema\n";
$migration = $read('core/migrations/145_business_logic_hardening.sql');
$assert('migration adds artifact identities to AP extraction, reconciliation, and payroll',
    substr_count($migration, 'ADD COLUMN artifact_id CHAR(36)') === 3);
$assert('artifact source and lineage identities are database-unique',
    str_contains($migration, 'ADD UNIQUE KEY uq_artifact_source')
    && str_contains($migration, 'ADD UNIQUE KEY uq_artifact_row_edge')
    && str_contains($migration, 'ADD UNIQUE KEY uq_artifact_object_edge'));
$assert('artifact record pointers support BIGINT domain IDs',
    str_contains($migration, 'source_record_id BIGINT UNSIGNED')
    && str_contains($migration, 'target_record_id BIGINT UNSIGNED'));
$assert('migration backfills objects, creation events, and represents edges',
    str_contains($migration, "'ap_invoice_review'")
    && str_contains($migration, "'cash_forecast'")
    && str_contains($migration, "'accounting_reconciliation'")
    && str_contains($migration, "'payroll_review'")
    && str_contains($migration, "'created'")
    && str_contains($migration, "'represents'"));
$accountingArtifactMigration = $read('modules/accounting/migrations/029_reconciliation_artifacts.sql');
$payrollArtifactMigration = $read('modules/payroll/migrations/008_run_artifacts.sql');
$assert('module-owned migrations preserve artifacts on clean installs',
    str_contains($accountingArtifactMigration, 'ADD COLUMN artifact_id CHAR(36)')
    && str_contains($accountingArtifactMigration, "'accounting_reconciliation'")
    && str_contains($payrollArtifactMigration, 'ADD COLUMN artifact_id CHAR(36)')
    && str_contains($payrollArtifactMigration, "'payroll_review'"));

$artifacts = $read('core/ai/artifacts.php');
$assert('artifact creation is source-idempotent and race-safe',
    str_contains($artifacts, 'function artifactEnsureForSource(')
    && str_contains($artifacts, 'function artifactFindBySource(')
    && str_contains($artifacts, "getCode() !== '23000'")
    && str_contains($artifacts, "sourceRecordType,\n            \$sourceRecordId,\n            true"));
$assert('artifact state and immutable history commit atomically',
    str_contains($artifacts, "require_once __DIR__ . '/../tx_helpers.php'")
    && substr_count($artifacts, 'cf_tx_begin($pdo)') >= 3
    && substr_count($artifacts, 'cf_tx_rollback($pdo, $ownsTx)') >= 3);
$assert('artifact updates are idempotent and optimistic',
    str_contains($artifacts, 'AND version = :prior_version')
    && str_contains($artifacts, 'function artifactPatchMatches(')
    && str_contains($artifacts, 'changed concurrently; expected version'));
$assert('approved review artifacts can be explicitly reopened',
    str_contains($artifacts, "'approved' => ['review', 'draft', 'final', 'archived']")
    && str_contains($artifacts, 'function artifactTransitionTo('));
$assert('artifact transitions reject concurrent stale writes',
    str_contains($artifacts, 'AND status = :prior')
    && str_contains($artifacts, 'changed concurrently'));
$assert('lineage writes are idempotent',
    str_contains($artifacts, "'existing' => true")
    && str_contains($artifacts, 'FOR UPDATE')
    && str_contains($artifacts, 'uq_artifact_row_edge') === false);

echo "\nFinancial outputs and transactions\n";
$cash = $read('core/ai/cash_forecast.php');
$assert('cash forecast reads posted bank GL balances',
    str_contains($cash, 'accounting_journal_entry_lines')
    && str_contains($cash, "j.status = 'posted'")
    && !str_contains($cash, 'last_known_balance'));
$assert('cash forecast uses live AR and payroll schema',
    str_contains($cash, 'SUM(amount_due)')
    && str_contains($cash, "'sent','partially_paid'")
    && str_contains($cash, 'net_total_cents')
    && str_contains($cash, 'payroll_pay_periods'));
$assert('cash forecast resolves each financial module scope explicitly',
    str_contains($cash, "cashForecastModuleTenantId('accounting'")
    && str_contains($cash, "cashForecastModuleTenantId('ap'")
    && str_contains($cash, "cashForecastModuleTenantId('billing'")
    && str_contains($cash, "cashForecastModuleTenantId('payroll'"));
$assert('forecast failures cannot become plausible zeroes',
    substr_count($cash, 'catch (\\Throwable') === 1
    && !str_contains($cash, 'Sandbox / table missing')
    && !str_contains($cash, 'catch (\\Throwable $e) { return 0; }'));
$assert('forecast row and artifact commit atomically',
    str_contains($cash, 'cf_tx_begin($pdo)')
    && str_contains($cash, "'cash_forecast'")
    && str_contains($cash, "'represents'"));

$apExtraction = $read('core/ai/ap_extraction.php');
$assert('AP extraction lifecycle changes share transactions with artifact history',
    substr_count($apExtraction, 'cf_tx_begin($pdo)') >= 4
    && str_contains($apExtraction, "'ap_invoice_review'")
    && str_contains($apExtraction, "'produces'"));
$assert('AP draft promotion locks the source run against duplicate bills',
    str_contains($apExtraction, 'apExtractionGet($tenantId, $runId, true)')
    && str_contains($apExtraction, '($forUpdate ? \' FOR UPDATE\' : \'\')'));

$accounting = $read('modules/accounting/lib/accounting.php');
$assert('journal posting participates in outer transactions',
    str_contains($accounting, "require_once __DIR__ . '/../../../core/tx_helpers.php'")
    && substr_count($accounting, 'cf_tx_begin($pdo)') >= 3);
$assert('journal code never rolls back a caller transaction as stale',
    !str_contains($accounting, 'rolling back stale active transaction before begin'));

$reconApi = $read('modules/accounting/api/reconciliations.php');
$assert('reconciliation rows, artifacts, and audit records commit together',
    str_contains($reconApi, 'function accountingReconciliationAtomic(')
    && substr_count($reconApi, 'accountingReconciliationAtomic(') >= 5);

$payrollApi = $read('modules/payroll/api/runs.php');
$assert('payroll creation and completion are artifact-atomic',
    substr_count($payrollApi, 'cf_tx_begin($pdo)') >= 3
    && str_contains($payrollApi, 'payrollRunSyncArtifact('));
$assert('paid payroll cannot commit without its configured cash posting',
    str_contains($payrollApi, '$cashPosting = payrollPostRunCash(')
    && str_contains($payrollApi, 'Could not mark payroll paid:'));

echo "\nWorkflow and graph integrity\n";
$workflow = $read('core/workflow_engine.php');
$assert('workflow decision and domain projection are one transaction',
    str_contains($workflow, 'cf_tx_begin($pdo)')
    && str_contains($workflow, 'cf_tx_rollback($pdo, $ownsTx)')
    && str_contains($workflow, '_workflowSubjectSync('));
$assert('workflow decisions lock the instance and reject duplicate approvers',
    str_contains($workflow, '_workflowFetchRow($tenantId, $instanceId, true)')
    && str_contains($workflow, 'FOR UPDATE')
    && str_contains($workflow, 'function _workflowAssertActorHasNotDecided('));
$assert('workflow escalation cannot count as an approval',
    str_contains($workflow, 'if ($action === \'escalate\')')
    && str_contains($workflow, '// Approve / skip — check quorum on the current step.'));
$assert('missing workflow projection handlers are explicit failures',
    str_contains($workflow, 'Workflow projection handler {$handler} is unavailable'));

foreach ([
    'modules/ap/lib/workflow_sync.php' => 'AP bill {$billId} not found',
    'modules/billing/lib/workflow_sync.php' => 'Billing invoice {$invoiceId} not found',
    'modules/payroll/lib/workflow_sync.php' => 'Payroll run {$runId} not found',
    'modules/treasury/lib/workflow_sync.php' => 'Treasury payment {$paymentId} not found',
] as $path => $failureText) {
    $assert("{$path} rejects missing domain rows", str_contains($read($path), $failureText));
}

$integrity = $read('core/business_integrity.php');
$assert('integrity audit follows module data scopes',
    str_contains($integrity, 'effectiveTenantIdForModule($module, $tenantId)')
    && str_contains($integrity, '$scopeTenant(\'placements\')')
    && str_contains($integrity, '$scopeTenant(\'people\')')
    && str_contains($integrity, '$artifactScopeTenantIds')
    && str_contains($integrity, '$artifactTenantPredicate'));
foreach ([
    'artifact_source_duplicates', 'artifact_edge_duplicates', 'artifact_event_ownership',
    'artifact_lineage_ownership', 'journal_balance', 'posted_event_journal_links',
    'event_registry_coverage', 'event_lineage', 'timesheet_entry_ownership',
    'timesheet_header_totals', 'placement_person_ownership',
    'billing_invoice_totals', 'ap_bill_totals', 'payroll_run_totals',
    'bank_match_integrity', 'reconciliation_balance_integrity',
    'active_placement_rates', 'active_placement_receivables',
    'active_placement_payable_parties', 'active_contractor_vendor_linkage',
    'active_placement_dimension_coverage',
] as $check) {
    $assert("integrity audit includes {$check}", str_contains($integrity, "'{$check}'"));
}

echo "\nSimulation and deployment gates\n";
$simAvailable = is_file($root . '/sim/lib/invariants.php') && is_file($root . '/sim/runner.php');
if ($simAvailable) {
    $invariants = $read('sim/lib/invariants.php');
    $runner = $read('sim/runner.php');
    $assert('simulation uses the real journal-line table and tests AP plus AR parity',
        str_contains($invariants, 'accounting_journal_entry_lines')
        && str_contains($invariants, 'ar_module')
        && str_contains($invariants, 'ar_ledger'));
    $assert('unknown invariants, step errors, and metric mismatches fail a run',
        str_contains($runner, "'unknown_invariant' => \$name")
        && str_contains($runner, "'ok' => false")
        && str_contains($runner, 'scenario_steps_completed')
        && str_contains($runner, 'scenario_expected_metrics'));
    $assert('posting failures propagate out of simulation steps',
        str_contains($runner, 'Posting did not complete:')
        && str_contains($runner, 'failed: {$e->getMessage()}'));
}

if (is_file($root . '/.github/workflows/ci.yml')) {
    $ci = $read('.github/workflows/ci.yml');
    $assert('nightly live simulation is actually scheduled and seeded',
        str_contains($ci, 'schedule:')
        && str_contains($ci, 'php scripts/ci_seed_sim_tenant.php')
        && is_file($root . '/scripts/ci_seed_sim_tenant.php'));
    $seedScript = $read('scripts/ci_seed_sim_tenant.php');
    $assert('live simulation includes tenant hierarchy and auto-reversal schema',
        str_contains($seedScript, 'core/migrations/007_subtenant_provisioning.sql')
        && str_contains($seedScript, 'core/migrations/024_auto_reversing_accruals.sql'));
}

if (is_file($root . '/.github/workflows/deploy-light-workspace.yml')) {
    $deploy = $read('.github/workflows/deploy-light-workspace.yml');
    foreach ([
        'api/admin/business_integrity.php',
        'core/business_integrity.php',
        'core/ai/ap_extraction.php',
        'core/ai/cash_forecast.php',
        'core/migrations/145_business_logic_hardening.sql',
        'modules/accounting/api/reconciliations.php',
        'modules/accounting/lib/reconciliation_artifact.php',
        'modules/accounting/migrations/029_reconciliation_artifacts.sql',
        'modules/payroll/lib/artifacts.php',
        'modules/payroll/migrations/008_run_artifacts.sql',
        'scripts/audit_business_integrity.php',
        'sim/lib',
        'sim/mocks',
        'sim/runner.php',
        'sim/scenarios',
        'tests/business_logic_hardening_smoke.php',
    ] as $path) {
        $assert("production package includes {$path}", str_contains($deploy, $path));
    }
}

foreach ([
    'api/admin/business_integrity.php', 'core/business_integrity.php',
    'core/ai/ap_extraction.php', 'core/ai/artifacts.php', 'core/ai/cash_forecast.php',
    'core/workflow_engine.php', 'modules/accounting/api/reconciliations.php',
    'modules/accounting/lib/accounting.php', 'modules/accounting/lib/reconciliation_artifact.php',
    'modules/payroll/api/runs.php', 'modules/payroll/lib/artifacts.php',
    'scripts/audit_business_integrity.php',
] as $path) {
    $assert("php lint {$path}", $lint($path));
}
if ($simAvailable) {
    $assert('php lint sim/lib/invariants.php', $lint('sim/lib/invariants.php'));
    $assert('php lint sim/runner.php', $lint('sim/runner.php'));
}
if (is_file($root . '/scripts/ci_seed_sim_tenant.php')) {
    $assert('php lint scripts/ci_seed_sim_tenant.php', $lint('scripts/ci_seed_sim_tenant.php'));
}

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
