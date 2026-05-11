<?php
/**
 * Smoke: Billing — Recurring invoice contracts (flat-fee MRR).
 *
 * Static contract test + live unit tests for the pure date-math + proration
 * helpers in modules/billing/lib/recurring.php.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

echo "Migration 008_recurring_contracts.sql\n";
$migPath = __DIR__ . '/../modules/billing/migrations/008_recurring_contracts.sql';
$mig = (string) file_get_contents($migPath);
$a('migration file exists',                            is_file($migPath));
$a('billing_invoice_contracts table',                  str_contains($mig, 'CREATE TABLE IF NOT EXISTS billing_invoice_contracts'));
$a('frequency enum monthly/quarterly/annual',          str_contains($mig, "ENUM('monthly','quarterly','annual')"));
$a('status enum active/paused/ended',                  str_contains($mig, "ENUM('active','paused','ended')"));
$a('proration_policy enum full/prorate/skip_first',    str_contains($mig, "ENUM('full','prorate','skip_first')"));
$a('day_of_period column',                             str_contains($mig, 'day_of_period INT UNSIGNED NOT NULL DEFAULT 1'));
$a('next_due_at tracker',                              str_contains($mig, 'next_due_at'));
$a('idx for cron lookup',                              str_contains($mig, 'idx_bic_tenant_status_next (tenant_id, status, next_due_at)'));
$a('adds source_contract_id to billing_invoices',      str_contains($mig, "TABLE_NAME='billing_invoices' AND COLUMN_NAME='source_contract_id'"));
$a('idempotent (info_schema guards)',                  substr_count($mig, 'information_schema') >= 2);

echo "\nLibrary helpers: lib/recurring.php\n";
$libPath = __DIR__ . '/../modules/billing/lib/recurring.php';
require_once $libPath;
foreach (['billingRecurringComputeNextDue','billingRecurringComputePeriodForGeneration','billingRecurringProrationFactor','billingRecurringGenerateInvoice','billingRecurringEligibleContracts','billingRecurringPreviewNextN'] as $fn) {
    $a("fn: {$fn}",                                    function_exists($fn));
}

echo "\nbillingRecurringComputeNextDue() — date math\n";
$mc = ['frequency' => 'monthly', 'day_of_period' => 15];
$a('monthly 2026-01-15 → 2026-02-15',                  billingRecurringComputeNextDue($mc, '2026-01-15') === '2026-02-15');
$a('monthly 2026-12-15 → 2027-01-15 (year roll)',      billingRecurringComputeNextDue($mc, '2026-12-15') === '2027-01-15');
$mc31 = ['frequency' => 'monthly', 'day_of_period' => 31];
$a('monthly dom=31 Feb clamp → 2026-02-28',            billingRecurringComputeNextDue($mc31, '2026-01-31') === '2026-02-28');
$qc = ['frequency' => 'quarterly', 'day_of_period' => 1];
$a('quarterly 2026-01-01 → 2026-04-01',                billingRecurringComputeNextDue($qc, '2026-01-01') === '2026-04-01');
$ac = ['frequency' => 'annual', 'day_of_period' => 1];
$a('annual 2026-01-01 → 2027-01-01',                   billingRecurringComputeNextDue($ac, '2026-01-01') === '2027-01-01');

echo "\nbillingRecurringComputePeriodForGeneration()\n";
$p = billingRecurringComputePeriodForGeneration(['frequency' => 'monthly'], '2026-02-01');
$a('monthly period: 02-01 → 02-28',                    $p['period_start'] === '2026-02-01' && $p['period_end'] === '2026-02-28');
$p = billingRecurringComputePeriodForGeneration(['frequency' => 'quarterly'], '2026-01-01');
$a('quarterly period: 01-01 → 03-31',                  $p['period_start'] === '2026-01-01' && $p['period_end'] === '2026-03-31');
$p = billingRecurringComputePeriodForGeneration(['frequency' => 'annual'], '2026-01-01');
$a('annual period: 01-01 → 12-31',                     $p['period_start'] === '2026-01-01' && $p['period_end'] === '2026-12-31');

echo "\nbillingRecurringProrationFactor()\n";
$a('full = 1.0 always',                                billingRecurringProrationFactor('full', '2026-02-01', '2026-02-28', '2026-02-15') === 1.0);
$a('skip_first when start mid-period = 0.0',           billingRecurringProrationFactor('skip_first', '2026-02-01', '2026-02-28', '2026-02-15') === 0.0);
$a('skip_first when start = period_start = 1.0',       billingRecurringProrationFactor('skip_first', '2026-02-01', '2026-02-28', '2026-02-01') === 1.0);
$f = billingRecurringProrationFactor('prorate', '2026-02-01', '2026-02-28', '2026-02-15');
$a('prorate Feb 15→28 ≈ 14/28 = 0.5',                  abs($f - 0.5) < 0.05);
$a('prorate w/ start = period_start = 1.0',            billingRecurringProrationFactor('prorate', '2026-02-01', '2026-02-28', '2026-02-01') === 1.0);

echo "\nbillingRecurringPreviewNextN()\n";
$c = ['frequency' => 'monthly', 'day_of_period' => 1, 'start_date' => '2026-02-01', 'next_due_at' => '2026-02-01', 'end_date' => null];
$peek = billingRecurringPreviewNextN($c, 3);
$a('preview 3 monthly dates from Feb',                 $peek === ['2026-02-01', '2026-03-01', '2026-04-01']);
$cE = $c; $cE['end_date'] = '2026-03-15';
$peekE = billingRecurringPreviewNextN($cE, 5);
$a('preview respects end_date (truncates)',            count($peekE) === 2 && $peekE[1] === '2026-03-01');

echo "\nAPI: api/recurring_contracts.php\n";
$apiSrc = (string) file_get_contents(__DIR__ . '/../modules/billing/api/recurring_contracts.php');
$a('parses',                                           (int) shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../modules/billing/api/recurring_contracts.php') . ' >/dev/null 2>&1; echo $?') === 0);
$a('GET list with optional ?status filter',            str_contains($apiSrc, "\$method === 'GET' && empty(\$_GET['id'])") && str_contains($apiSrc, "in_array(\$st, ['active', 'paused', 'ended']"));
$a('GET detail with preview_next_3',                   str_contains($apiSrc, "billingRecurringPreviewNextN(\$row, 3)"));
$a('POST create requires fields',                      str_contains($apiSrc, "api_require_fields(\$body, ['client_name', 'contract_name', 'frequency', 'amount', 'start_date'])"));
$a('POST validates frequency enum',                    str_contains($apiSrc, "['monthly','quarterly','annual']"));
$a("?action=pause/resume/end/update",                  str_contains($apiSrc, "in_array(\$action, ['update','pause','resume','end']"));
$a('?action=generate_now',                             str_contains($apiSrc, "\$action === 'generate_now'"));
$a('generate_now refuses non-active status',           str_contains($apiSrc, "Cannot generate from status"));
$a('day_of_period clamped 1..31',                      str_contains($apiSrc, 'max(1, min(31, (int)'));
$a('write requires billing.invoice.create',            substr_count($apiSrc, "RBAC::requirePermission(\$user, 'billing.invoice.create')") >= 3);

echo "\nCron: scripts/billing_recurring_generate.php\n";
$cronSrc = (string) file_get_contents(__DIR__ . '/../scripts/billing_recurring_generate.php');
$a('parses',                                           (int) shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../scripts/billing_recurring_generate.php') . ' >/dev/null 2>&1; echo $?') === 0);
$a('iterates tenants with eligible contracts',         str_contains($cronSrc, "SELECT DISTINCT tenant_id FROM billing_invoice_contracts"));
$a('uses billingRecurringEligibleContracts()',         str_contains($cronSrc, 'billingRecurringEligibleContracts($tid, $asOf)'));
$a('handles existed/skipped (idempotency)',            str_contains($cronSrc, "\$res['existed'] || !empty(\$res['skipped'])"));
$a('resolves AR users by role',                        str_contains($cronSrc, "'ar_clerk','ar_manager','billing','admin','master_admin','manager'"));
$a('idempotency key per (tenant, user, day)',          str_contains($cronSrc, "'billing-recurring-' . \$tid . '-' . \$u['id'] . '-' . \$asOf"));

echo "\nUI: ui/RecurringContracts.jsx + BillingModule nav\n";
$uiSrc = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/RecurringContracts.jsx');
foreach (['billing-recurring-contracts','billing-contracts-new','billing-contracts-table'] as $tid) {
    $a("testid: {$tid}",                               str_contains($uiSrc, "data-testid=\"{$tid}\""));
}
foreach (['generate', 'pause', 'resume', 'end', 'edit'] as $action) {
    $a("row action: {$action}",                        str_contains($uiSrc, "billing-contract-{$action}-"));
}
$a('preview_next_3 rendered',                          str_contains($uiSrc, 'billing-contract-preview-'));
$a('modal create + edit',                              str_contains($uiSrc, 'New recurring contract') && str_contains($uiSrc, 'Edit:'));
$a('proration policy field',                           str_contains($uiSrc, 'billing-contract-form-proration'));

$modSrc = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/BillingModule.jsx');
$a('imports RecurringContracts',                       str_contains($modSrc, "import RecurringContracts from './RecurringContracts'"));
$a('routes /contracts',                                str_contains($modSrc, '<Route path="contracts" element={<RecurringContracts />}'));
$a('nav adds Contracts',                               str_contains($modSrc, "label: 'Contracts'"));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
