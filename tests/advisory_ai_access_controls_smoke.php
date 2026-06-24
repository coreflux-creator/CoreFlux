<?php
/**
 * Smoke: advisory AI access controls.
 *
 * User-triggered advisory, extraction, mapping, and narrative endpoints must
 * require both the source-domain permission and ai.use. Deterministic reads can
 * still return base data, but AI enrichment must be suppressed without ai.use.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;

$read = static function (string $rel) use ($ROOT): string {
    $path = $ROOT . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    return (string) file_get_contents($path);
};

$a = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    {$name}\n"; }
    else     { $fail++; echo "  FAIL  {$name}\n"; }
};

$lint = static function (string $rel) use ($ROOT): bool {
    $path = $ROOT . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $out = (string) shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    return str_contains($out, 'No syntax errors detected');
};

$routeSlice = static function (string $src, string $needle, int $length = 1200): string {
    $pos = strpos($src, $needle);
    return $pos === false ? '' : substr($src, $pos, $length);
};

$hasGate = static function (string $src, string $perm): bool {
    return str_contains($src, "rbac_legacy_require(\$user, '{$perm}')")
        || str_contains($src, "rbac_legacy_require(\$ctx['user'], '{$perm}')");
};

$assertRouteGate = static function (string $label, string $rel, string $needle, string $domainPerm) use ($read, $routeSlice, $hasGate, $a): void {
    $src = $read($rel);
    $slice = $routeSlice($src, $needle);
    $a("{$label}: route present", $slice !== '');
    $a("{$label}: domain gate {$domainPerm}", $hasGate($slice, $domainPerm) || str_contains($slice, $domainPerm));
    $a("{$label}: ai.use gate", $hasGate($slice, 'ai.use'));
};

echo "Advisory AI access controls\n";

$lintFiles = [
    'api/ai_agents.php',
    'api/audit_anomaly.php',
    'api/cfo_annotate.php',
    'api/line_ai_suggest.php',
    'api/reports_ai_explain.php',
    'api/tax_mapping_ai_suggest.php',
    'api/workflow_ai.php',
    'modules/accounting/api/bank_ai.php',
    'modules/accounting/api/close_ai.php',
    'modules/accounting/api/intercompany.php',
    'modules/accounting/api/reconciliations.php',
    'modules/ap/api/bills.php',
    'modules/ap/api/bills_csv_import.php',
    'modules/ap/api/csv_import.php',
    'modules/ap/api/expenses.php',
    'modules/ap/api/payments_csv_import.php',
    'modules/ap/api/vendors.php',
    'modules/billing/api/csv_import.php',
    'modules/billing/api/dunning.php',
    'modules/billing/api/invoices.php',
    'modules/billing/api/payments_csv_import.php',
    'modules/payroll/api/ai_run_summary.php',
    'modules/payroll/api/anomalies.php',
    'modules/people/api/ai_missing_fields.php',
    'modules/people/api/ai_setup_email.php',
    'modules/people/api/ai_summary.php',
    'modules/people/api/csv_import.php',
    'modules/placements/api/chain.php',
    'modules/placements/api/csv_import.php',
    'modules/staffing/api/ai_insights.php',
    'modules/staffing/api/csv_import.php',
    'modules/time/api/csv_import.php',
    'modules/time/api/intake.php',
    'modules/time/api/settlement.php',
    'modules/time/api/upload.php',
    'modules/treasury/api/account_transactions.php',
];
foreach ($lintFiles as $rel) {
    $a("parses {$rel}", $lint($rel));
}

echo "\nRoot advisory endpoints\n";
$aiAgents = $read('api/ai_agents.php');
$a('AI agents surface requires accounting.je.view', $hasGate($aiAgents, 'accounting.je.view'));
$a('AI agents run requires ai.use', $hasGate($routeSlice($aiAgents, "action === 'run'"), 'ai.use'));
$a('CFO annotate requires ai.use', $hasGate($read('api/cfo_annotate.php'), 'ai.use'));
$a('Audit anomaly requires ai.use', $hasGate($read('api/audit_anomaly.php'), 'ai.use'));
$a('Workflow AI requires ai.use', $hasGate($read('api/workflow_ai.php'), 'ai.use'));
$a('Reports AI requires reports.view + ai.use',
    $hasGate($read('api/reports_ai_explain.php'), 'reports.view')
    && $hasGate($read('api/reports_ai_explain.php'), 'ai.use'));
$a('Line AI suggest keeps AP/Billing domain gates + ai.use',
    str_contains($read('api/line_ai_suggest.php'), "'ap.bill.create'")
    && str_contains($read('api/line_ai_suggest.php'), "'billing.invoice.create'")
    && $hasGate($read('api/line_ai_suggest.php'), 'ai.use'));
$a('Tax mapping AI requires accounting.je.create + ai.use',
    $hasGate($read('api/tax_mapping_ai_suggest.php'), 'accounting.je.create')
    && $hasGate($read('api/tax_mapping_ai_suggest.php'), 'ai.use'));

echo "\nModule advisory and extraction endpoints\n";
$assertRouteGate('Staffing weekly memo', 'modules/staffing/api/ai_insights.php', "action !== 'weekly_memo'", 'staffing.reports.view');
$a('Payroll run summary requires payroll.view + ai.use',
    $hasGate($read('modules/payroll/api/ai_run_summary.php'), 'payroll.view')
    && $hasGate($read('modules/payroll/api/ai_run_summary.php'), 'ai.use'));
$a('Payroll anomaly AI enrichment is conditional on ai.use',
    str_contains($read('modules/payroll/api/anomalies.php'), "if (\$ai) rbac_legacy_require(\$user, 'ai.use');"));
$a('Accounting close AI requires period view + ai.use',
    $hasGate($read('modules/accounting/api/close_ai.php'), 'accounting.period.view')
    && $hasGate($read('modules/accounting/api/close_ai.php'), 'ai.use'));
$a('Accounting bank AI requires JE create + ai.use',
    $hasGate($read('modules/accounting/api/bank_ai.php'), 'accounting.je.create')
    && $hasGate($read('modules/accounting/api/bank_ai.php'), 'ai.use'));
$assertRouteGate('Intercompany AI narrative', 'modules/accounting/api/intercompany.php', "action === 'narrate_elimination'", 'accounting.reports.view');
$assertRouteGate('Reconciliation AI narrative', 'modules/accounting/api/reconciliations.php', "action === 'generate_ai_narrative'", 'accounting.bank.reconcile');
$a('People AI summary requires people.view + ai.use',
    $hasGate($read('modules/people/api/ai_summary.php'), 'people.view')
    && $hasGate($read('modules/people/api/ai_summary.php'), 'ai.use'));
$a('People missing-fields AI requires PII view + ai.use',
    $hasGate($read('modules/people/api/ai_missing_fields.php'), 'people.pii.view')
    && $hasGate($read('modules/people/api/ai_missing_fields.php'), 'ai.use'));
$a('People setup-email AI requires manage/PII + ai.use',
    $hasGate($read('modules/people/api/ai_setup_email.php'), 'people.manage')
    && $hasGate($read('modules/people/api/ai_setup_email.php'), 'people.pii.view')
    && $hasGate($read('modules/people/api/ai_setup_email.php'), 'ai.use'));

$assertRouteGate('AP bill receipt extract', 'modules/ap/api/bills.php', "action === 'extract_receipt'", 'ap.bill.create');
$assertRouteGate('AP bill PDF extract', 'modules/ap/api/bills.php', "action === 'extract_from_pdf'", 'ap.bill.create');
$assertRouteGate('AP payment-run suggest', 'modules/ap/api/bills.php', "action === 'suggest-payment-run'", 'ap.payment.create');
$assertRouteGate('AP expense receipt extract', 'modules/ap/api/expenses.php', "action === 'extract_receipt'", 'ap.expense.submit');
$assertRouteGate('AP vendor W9 extract', 'modules/ap/api/vendors.php', "action === 'extract_w9'", 'ap.bill.create');
$assertRouteGate('Billing invoice suggest', 'modules/billing/api/invoices.php', "action === 'suggest-from-placement'", 'billing.invoice.draft');
$assertRouteGate('Billing dunning suggest', 'modules/billing/api/dunning.php', "action === 'ai_suggest'", 'billing.view');
$assertRouteGate('Placement contract extract', 'modules/placements/api/chain.php', "action === 'extract_contract'", 'placements.manage');
$assertRouteGate('Time upload extract', 'modules/time/api/upload.php', "action === 'extract'", 'time.entry.create');
$assertRouteGate('Time settlement AI suggest', 'modules/time/api/settlement.php', "action === 'ai_suggest'", 'time.settlement.view');
$assertRouteGate('Time intake poll AI extract', 'modules/time/api/intake.php', "action === 'poll'", 'time.review');
$assertRouteGate('Time intake process AI extract', 'modules/time/api/intake.php', "action === 'process'", 'time.review');

echo "\nCSV AI mapping endpoints\n";
foreach ([
    ['People CSV AI map', 'modules/people/api/csv_import.php', 'people.manage'],
    ['Placements CSV AI map', 'modules/placements/api/csv_import.php', 'placements.manage'],
    ['Time CSV AI map', 'modules/time/api/csv_import.php', 'time.bulk_upload'],
    ['Staffing CSV AI map', 'modules/staffing/api/csv_import.php', 'staffing.view'],
    ['AP CSV AI map', 'modules/ap/api/csv_import.php', 'ap.bill.create'],
    ['AP bills CSV AI map', 'modules/ap/api/bills_csv_import.php', 'ap.bill.create'],
    ['AP payments CSV AI map', 'modules/ap/api/payments_csv_import.php', 'ap.payment.create'],
    ['Billing CSV AI map', 'modules/billing/api/csv_import.php', 'billing.invoice.draft'],
    ['Billing payments CSV AI map', 'modules/billing/api/payments_csv_import.php', 'billing.payments.record'],
] as [$label, $rel, $perm]) {
    $assertRouteGate($label, $rel, "action === 'ai_suggest_map'", $perm);
}

echo "\nConditional AI enrichment\n";
$treasury = $read('modules/treasury/api/account_transactions.php');
$posCan = strpos($treasury, "\$canUseAi = rbac_legacy_can(\$user, 'ai.use')");
$posIf = strpos($treasury, 'if ($canUseAi)');
$posRequire = $posIf === false ? false : strpos($treasury, "require_once __DIR__ . '/../../../core/ai_categorization.php';", $posIf);
$posSuggest = $posIf === false ? false : strpos($treasury, 'aiSuggestCounterpartAccount(', $posIf);
$a('Treasury computes ai.use capability', $posCan !== false);
$a('Treasury AI enrichment sits behind ai.use capability',
    $posIf !== false && $posRequire !== false && $posSuggest !== false
    && $posIf < $posRequire && $posRequire < $posSuggest);
$a('Treasury read suppresses suggestions without ai.use',
    str_contains($treasury, "if (\$r['match_status'] === 'unmatched') \$rows[\$i]['ai_suggestion'] = null;"));

echo "\nDocs\n";
$doc = $read('docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md');
$a('Architecture doc states domain permission plus ai.use rule',
    str_contains($doc, 'endpoints require both their source-domain permission and `ai.use`'));
$a('Architecture doc preserves deterministic-read degradation rule',
    str_contains($doc, 'without AI enrichment') && str_contains($doc, 'unless the caller has `ai.use`'));

echo "\nAdvisory AI access controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
