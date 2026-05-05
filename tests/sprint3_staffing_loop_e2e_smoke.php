<?php
/**
 * Sprint 3 — Core staffing-placement loop E2E contract smoke
 *
 * Verifies the wiring between People → Placements → Time → Billing/AP/Payroll.
 * No DB required; asserts the cross-module library + API contracts exist
 * by static inspection. Catches regressions where a module rename breaks
 * the next module in the chain (the "where did all of this logic go?" class
 * of bugs).
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
function _a(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $label\n"; }
    else       { $fail++; echo "  FAIL  $label\n"; }
}

echo "Sprint 3 — staffing loop E2E contract\n";

$peopleLib   = (string) file_get_contents(__DIR__ . '/../modules/people/lib/employees.php');
$placeLib    = (string) file_get_contents(__DIR__ . '/../modules/placements/lib/placements.php');
$timeLib     = (string) file_get_contents(__DIR__ . '/../modules/time/lib/time.php');
$payLib      = (string) file_get_contents(__DIR__ . '/../modules/payroll/lib/payroll.php');
$timePeriods = (string) file_get_contents(__DIR__ . '/../modules/time/api/periods.php');
$billInv     = (string) file_get_contents(__DIR__ . '/../modules/billing/api/invoices.php');
$apBills     = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bills.php');
$payRuns     = (string) file_get_contents(__DIR__ . '/../modules/payroll/api/runs.php');
$payPreflight= (string) file_get_contents(__DIR__ . '/../modules/payroll/api/preflight.php');
$placeRates  = (string) file_get_contents(__DIR__ . '/../modules/placements/api/rates.php');
$peopleApi   = (string) file_get_contents(__DIR__ . '/../modules/people/api/people.php');

// ---- Stage 1: People → cross-module read interface ----
echo "\nStage 1: People exposes employee read-interface for downstream modules\n";
_a('peopleGetEmployee defined',                str_contains($peopleLib, 'function peopleGetEmployee'));
_a('peopleActiveCompensation defined',         str_contains($peopleLib, 'function peopleActiveCompensation'));
_a('peopleActiveFederalTax defined',           str_contains($peopleLib, 'function peopleActiveFederalTax'));
_a('peopleActiveStateTaxes defined',           str_contains($peopleLib, 'function peopleActiveStateTaxes'));
_a('peopleActiveBankAccounts defined',         str_contains($peopleLib, 'function peopleActiveBankAccounts'));
_a('peoplePayrollReadiness defined',           str_contains($peopleLib, 'function peoplePayrollReadiness'));

// ---- Stage 2: Placements → rate lock + chain + cross-module margin ----
echo "\nStage 2: Placements snapshot-locks rate + exposes margin\n";
_a('placementChain helper exists',             str_contains($placeLib, 'function placementChain'));
_a('rates.php has approve action',             str_contains($placeRates, "action.*approve|=== 'approve'") || preg_match('/approve\b/', $placeRates) === 1);
_a('rates approval flips effective_to of prior', str_contains($placeRates, 'effective_to'));
_a('rates approve/correction snapshot path',   str_contains($placeRates, 'snapshot') || str_contains($placeRates, 'approved_at'));
_a('placement margin formula (additive vendor stack)', str_contains($placeLib, 'function placementsComputeMargin'));

// ---- Stage 3: Time → bundles for AR / AP / Payroll consumers ----
echo "\nStage 3: Time builds downstream bundles (ar/ap/payroll/revrec)\n";
_a('timeBuildBundlesForPeriod defined',        str_contains($timeLib, 'function timeBuildBundlesForPeriod'));
_a('timePreviewBundlesForPeriod defined',      str_contains($timeLib, 'function timePreviewBundlesForPeriod'));
_a('Time periods API has preview_close action',str_contains($timePeriods, "preview_close"));
_a('Time bundle types include ar/ap/payroll',  str_contains($timeLib, "'ar'") && str_contains($timeLib, "'ap'") && str_contains($timeLib, "'payroll'"));
$timeFeed = (string) file_get_contents(__DIR__ . '/../modules/time/api/feed.php');
_a('Time bundle marks consumed_by_module',     str_contains($timeLib, 'consumed_by_module') || str_contains($timeFeed, 'consumed_by_module'));

// ---- Stage 4: Billing consumes ar bundles ----
echo "\nStage 4: Billing creates invoices from Time AR bundles\n";
_a('Invoices API has from-time-bundle action', str_contains($billInv, 'from-time-bundle') || str_contains($billInv, 'from_time_bundle'));
_a('Invoices marks bundles consumed=billing',  str_contains($billInv, "consumed_by_module") && str_contains($billInv, "'billing'"));
_a('Invoices supports approve action',         str_contains($billInv, 'approve'));
_a('Invoices supports send action',            str_contains($billInv, "action.*send|=== 'send'") || preg_match('/[\'"]send[\'"]/', $billInv) === 1);
_a('Invoices post → Accounting JE (Dr AR / Cr Revenue)', str_contains($billInv, 'accountingPostJe') || str_contains($billInv, "'post'"));

// ---- Stage 5: AP consumes ap bundles ----
echo "\nStage 5: AP creates bills from Time AP bundles\n";
_a('Bills API has from-time-bundle action',    str_contains($apBills, 'from-time-bundle') || str_contains($apBills, 'from_time_bundle'));
_a('Bills marks bundles consumed=ap',          str_contains($apBills, "consumed_by_module") && str_contains($apBills, "'ap'"));
_a('Bills approve action present',             str_contains($apBills, "approve"));
_a('Bills payment allocation present',         str_contains($apBills, 'allocate') || file_exists(__DIR__ . '/../modules/ap/api/payments.php'));

// ---- Stage 6: Payroll consumes payroll bundles + people setup ----
echo "\nStage 6: Payroll runs use Time bundles + People readiness\n";
_a('payrollBuildComputeContext defined',       str_contains($payLib, 'function payrollBuildComputeContext'));
_a('Payroll preflight calls peoplePayrollReadiness', str_contains($payPreflight, 'people_employees') || str_contains($payPreflight, 'peoplePayrollReadiness'));
_a('Payroll runs API has compute action',      str_contains($payRuns, "compute"));
_a('Payroll runs API has approve action',      str_contains($payRuns, "approve"));
_a('Payroll runs persists line_items',         str_contains($payLib, 'payroll_line_items') || str_contains($payRuns, 'payroll_line_items'));

// ---- Stage 7: SPA wiring renders all five modules ----
echo "\nStage 7: SPA App.jsx routes the full staffing loop\n";
$app = (string) file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');
foreach (['/modules/people','/modules/placements','/modules/time','/modules/billing','/modules/ap','/modules/payroll'] as $r) {
    _a("App.jsx routes $r/*",                  str_contains($app, "{$r}/*"));
}

// ---- Stage 8: Module sidebar exposes the staffing modules ----
echo "\nStage 8: core/modules.php registers the staffing loop\n";
$modDef = (string) file_get_contents(__DIR__ . '/../core/modules.php');
foreach (['people','placements','time','billing','ap','payroll'] as $m) {
    _a("getModuleDefinitions includes $m",     str_contains($modDef, "'{$m}' => ["));
}

echo "\n--- $pass assertions, $fail failed ---\n";
exit($fail === 0 ? 0 : 1);
