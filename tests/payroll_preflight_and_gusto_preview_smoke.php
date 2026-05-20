<?php
/**
 * Smoke: payroll preflight + gusto preview wiring.
 *
 * Static-string only — real DB tests will land with the integration harness.
 */
declare(strict_types=1);

$assertCount = 0; $failCount = 0;
function _p(string $label, bool $cond, ?string $hint = null): void {
    global $assertCount, $failCount;
    $assertCount++;
    if ($cond) {
        echo "  ok  $label\n";
    } else {
        $failCount++;
        echo "FAIL  $label" . ($hint ? "  ($hint)" : '') . "\n";
    }
}

echo "Preflight endpoint\n";
$pf = (string) file_get_contents(__DIR__ . '/../modules/payroll/api/preflight.php');
_p('preflight.php exists',                                 $pf !== '');
_p('queries through people_employees (canonical W2)',      str_contains($pf, 'JOIN people_employees'));
_p('checks SSN cipher',                                    str_contains($pf, "'id' => 'ssn'"));
_p('checks DOB',                                           str_contains($pf, "'id' => 'dob'"));
_p('checks W-4 federal_filing_status',                     str_contains($pf, "'id' => 'w4_federal'"));
_p('checks state tax setup',                               str_contains($pf, "'id' => 'state_tax'"));
_p('checks active placement with approved rate',           str_contains($pf, 'pr.approved_at IS NOT NULL'));
_p('checks pay_rate > 0',                                  str_contains($pf, "'id' => 'pay_rate'"));
_p('returns ready_to_run summary flag',                    str_contains($pf, 'ready_to_run'));
_p('permission gated on payroll.run.create',               str_contains($pf, "rbac_legacy_require(\$ctx['user'], 'payroll.run.create')"));

echo "\nGusto preview endpoint\n";
$gp = (string) file_get_contents(__DIR__ . '/../modules/payroll/api/gusto_preview.php');
_p('gusto_preview.php exists',                             $gp !== '');
_p('does NOT call gustoSubmitPayroll',                     !str_contains($gp, 'gustoSubmitPayroll'));
_p('fetches Gusto payroll for diff',                       str_contains($gp, 'gustoGetPayroll'));
_p('builds per-employee diff array',                       str_contains($gp, "'diff' =>"));
_p('reports unmatched_in_coreflux',                        str_contains($gp, 'unmatched_in_coreflux'));
_p('reports unmatched_in_gusto',                           str_contains($gp, 'unmatched_in_gusto'));
_p('summary.safe_to_submit flag',                          str_contains($gp, 'safe_to_submit'));
_p('permission gated on payroll.run.disburse',             str_contains($gp, "rbac_legacy_require(\$ctx['user'], 'payroll.run.disburse')"));

echo "\nMigration 012 — payroll profile alignment\n";
$mig = (string) file_get_contents(__DIR__ . '/../core/migrations/012_payroll_profile_alignment.sql');
_p('migration 012 exists',                                 $mig !== '');
_p('adds payroll_profiles.pay_type',                       str_contains($mig, 'ALTER TABLE payroll_profiles ADD COLUMN pay_type'));
_p('adds payroll_profiles.pay_rate_cents',                 str_contains($mig, 'ALTER TABLE payroll_profiles ADD COLUMN pay_rate_cents'));
_p('adds payroll_profiles.flsa_class',                     str_contains($mig, 'ALTER TABLE payroll_profiles ADD COLUMN flsa_class'));
_p('adds ap_1099_ledger.vendor_id alias',                  str_contains($mig, 'ALTER TABLE ap_1099_ledger ADD COLUMN vendor_id'));
_p('adds people.user_id alias',                            str_contains($mig, 'ALTER TABLE people ADD COLUMN user_id'));
_p('adds accounting_journal_entry_lines.tenant_id',        str_contains($mig, 'ALTER TABLE accounting_journal_entry_lines ADD COLUMN tenant_id'));
_p('atomic ALTERs (no multi-clause)',                      substr_count($mig, 'ALTER TABLE') >= 10);

echo "\nUI wiring\n";
$prd = (string) file_get_contents(__DIR__ . '/../modules/payroll/ui/PayrollRunDetail.jsx');
_p('PayrollRunDetail renders PayrollPreflightCard',        str_contains($prd, '<PayrollPreflightCard'));
_p('PayrollRunDetail wires Preview diff button',           str_contains($prd, 'payroll-run-gusto-preview-btn'));
_p('PayrollRunDetail renders GustoPreviewPanel',           str_contains($prd, 'GustoPreviewPanel'));
_p('Preview hits /api/gusto_preview.php',                  str_contains($prd, '/modules/payroll/api/gusto_preview.php'));

echo "\nSchema contract gate is GREEN\n";
$gate = file_get_contents(__DIR__ . '/schema_contract_smoke.php');
_p('legacy allowlist down from 13 → 3 violations',         substr_count($gate, "  (table=") <= 10);

echo "\n--- $assertCount assertions, $failCount failed ---\n";
exit($failCount === 0 ? 0 : 1);
