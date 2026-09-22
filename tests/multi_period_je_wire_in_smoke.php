<?php
/**
 * Smoke — accrual-at-approval model wire-in (2026-02 architectural
 * correction).
 *
 * Per operator's accounting clarification:
 *   • Timesheet approval IS the recognition event.
 *     - bundle_type='ar' → Dr AR Unbilled / Cr Revenue (per accounting_period)
 *     - bundle_type='ap' → Dr Expense    / Cr AP Accrued (per accounting_period)
 *   • Invoice post is pure RECLASSIFICATION (single JE on issue_date):
 *       Dr AR / Cr AR Unbilled (/ Cr Sales Tax)
 *   • Bill post is pure RECLASSIFICATION (single JE on bill_date):
 *       Dr AP Accrued (/ Dr Input Tax) / Cr AP
 *
 * The previous fork's "multi-period split on invoice post" model has been
 * superseded — this smoke asserts the new shape is in place.
 *
 * Pure source-level (no live DB). DB-level execution is covered by the
 * separate bundle_accrual_at_approval_smoke + the multi_period_je_split
 * batch builder smokes.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/modules/accounting/lib/multi_period.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. accountingEnsureAccrualAccounts — still defined + idempotent shape\n";
$a('function declared', function_exists('accountingEnsureAccrualAccounts'));
$lib = (string) file_get_contents($root . '/modules/accounting/lib/multi_period.php');
$a('checks accounting_accounts for existing code (no double-insert)',
   str_contains($lib, "SELECT id FROM accounting_accounts WHERE tenant_id = :t AND account_code = :c LIMIT 1"));
$a('AR Unbilled inserted as account_type=asset',
   str_contains($lib, "'AR Unbilled (Accrued Revenue)', 'asset'"));
$a('AP Accrued inserted as account_type=liability',
   str_contains($lib, "'AP Accrued (Unbilled Costs)',   'liability'"));

echo "\n2. New bundle-accrual helpers exist\n";
$a('accountingBreakdownBundleByDate declared',
   function_exists('accountingBreakdownBundleByDate'));
$a('accountingPostBundleAccrual declared',
   function_exists('accountingPostBundleAccrual'));
$a('bundle breakdown filters to billable categories only',
   str_contains($lib, 'IN ("regular_billable","OT_billable")'));
$a('bundle accrual gated to ar/ap bundle types only',
   str_contains($lib, "if (!in_array(\$bundleType, ['ar', 'ap'], true)) {\n        return [];"));
$a('bundle accrual posts AR shape (Dr AR Unbilled / Cr Revenue)',
   str_contains($lib, "'account_code' => \$arUnbilled,  'debit' => round(\$amt, 2), 'credit' => 0,")
   && str_contains($lib, "'account_code' => \$revenueCode, 'debit' => 0, 'credit' => round(\$amt, 2),"));
$a('bundle accrual posts AP shape (Dr Expense / Cr AP Accrued)',
   str_contains($lib, "'account_code' => \$expenseCode, 'debit' => round(\$amt, 2), 'credit' => 0,")
   && str_contains($lib, "'account_code' => \$apAccrued,   'debit' => 0, 'credit' => round(\$amt, 2),"));
$a('bundle accrual idempotency key is time:bundle:<id>:accrual:<period>',
   str_contains($lib, "sprintf('time:bundle:%d:accrual:%d', \$bundleId, (int) \$period['id'])"));
$a('bundle accrual source_ref_type=time_bundle',
   str_contains($lib, "'source_ref_type' => 'time_bundle'"));
$a('bundle accrual cannot duplicate posted assignment events',
   str_contains($lib, 'accountingBundleAssignmentRecognitionState')
   && str_contains($lib, 'is only partly recognized by assignment events'));
$a('AP bundle accrual is contractor/referral only',
   str_contains($lib, "['1099', 'c2c', 'referral']")
   && str_contains($lib, "\$expenseCode = '5010';"));

echo "\n3. Invoice post handler — reclassification wiring (billing/api/invoices.php)\n";
$inv = (string) file_get_contents($root . '/modules/billing/api/invoices.php');
$a('require_once multi_period.php at top of post handler',
   str_contains($inv, "require_once __DIR__ . '/../../accounting/lib/multi_period.php';"));
$a('reads accountingSettingsGet($tid) inside post action',
   str_contains($inv, '$settings = accountingSettingsGet($tid);'));
$a('reclassifyOnly gate requires multi-period mode and fully accrued source lines',
   str_contains($inv, "\$reclassifyOnly = !empty(\$settings['multi_period_split_enabled']) && \$allLinesWereAccrued;"));
$a('direct manual invoices are excluded from approved-time reclassification',
   str_contains($inv, 'source_type IN ("time", "time_entry", "economic_item")')
   && str_contains($inv, "\$allLinesWereAccrued ="));
$a('reclassification block engages on the flag',
   str_contains($inv, "if (\$reclassifyOnly) {"));
$a('reclassification debits AR (account 1100) for full total',
   str_contains($inv, "'account_code' => '1100'")
   && str_contains($inv, "'debit' => round(\$total, 2)"));
$a('reclassification credits AR Unbilled by placement subtotal',
   str_contains($inv, "'account_code' => \$arUnbilled")
   && str_contains($inv, "'credit' => \$amount > 0 ? \$amount : 0")
   && str_contains($inv, "\$placementSubtotals as \$placementId => \$amount"));
$a('reclassification credits Sales Tax Payable (2100) when tax > 0',
   str_contains($inv, "'account_code' => '2100'")
   && str_contains($inv, "'credit' => round(\$taxTotal, 2)"));
$a('reclassification idempotency key includes :post:reclass',
   str_contains($inv, "sprintf('billing:invoice:%d:post:reclass', \$id)"));
$a('reclassification audits with via=ar_reclassification',
   str_contains($inv, "'via' => 'ar_reclassification'"));
$a('event layer receives the reclassification shape',
   str_contains($inv, '$eventPostingLines = $reclassifyOnly ? $reclassLines : $lines')
   && str_contains($inv, "'lines'          => \$payloadLines"));
$a('reclassification block placed BEFORE legacy accountingPostJe',
   strpos($inv, "sprintf('billing:invoice:%d:post:reclass',") < strpos($inv, "sprintf('billing:invoice:%d:post',"));
$a('legacy single-period revenue-recognition path still present',
   str_contains($inv, "sprintf('billing:invoice:%d:post', \$id)"));
$a('OLD multi-period-split phrases removed from invoice handler',
   !str_contains($inv, "accountingBuildInvoiceJEBatch")
   && !str_contains($inv, "'via' => 'multi_period_split'")
   && !str_contains($inv, 'is_issue_period'));

echo "\n4. AP bill post handler — reclassification wiring (ap/api/bills.php)\n";
$ap = (string) file_get_contents($root . '/modules/ap/api/bills.php');
$a('require_once multi_period.php at top of post handler',
   str_contains($ap, "require_once __DIR__ . '/../../accounting/lib/multi_period.php';"));
$a('reads accountingSettingsGet inside post action',
   str_contains($ap, '$settings = accountingSettingsGet($tid);'));
$a('reclassifyOnly gate requires multi-period mode and fully accrued source lines',
   str_contains($ap, "\$reclassifyOnly = !empty(\$settings['multi_period_split_enabled']) && \$allLinesWereAccrued;"));
$a('manual AP bills are excluded from approved-time reclassification',
   str_contains($ap, "['time', 'time_entry', 'economic_item']")
   && str_contains($ap, '$allLinesWereAccrued ='));
$a('AP reclassification debits AP Accrued by placement subtotal',
   str_contains($ap, "'account_code' => \$apAccrued")
   && str_contains($ap, "'debit' => \$amount > 0 ? \$amount : 0")
   && str_contains($ap, "\$placementSubtotals as \$placementId => \$amount"));
$a('AP reclassification credits Accounts Payable (2000) for total',
   str_contains($ap, "'account_code' => '2000'")
   && str_contains($ap, "'credit' => round((float) \$row['total'], 2)"));
$a('AP reclassification debits Input Tax (1310) when tax > 0',
   str_contains($ap, "'account_code' => '1310'")
   && str_contains($ap, "'debit' => \$billTaxTotal"));
$a('AP reclassification idempotency key includes :post:reclass',
   str_contains($ap, "sprintf('ap:bill:%d:post:reclass', \$id)"));
$a('AP reclassification audits with via=ap_reclassification',
   str_contains($ap, "'via'               => 'ap_reclassification'"));
$a('AP event layer receives the reclassification shape',
   str_contains($ap, '$eventPostingLines = $reclassifyOnly ? $reclassLines : $payloadLines')
   && str_contains($ap, "'lines'        => \$eventPostingLines"));
$a('AP reclassification placed BEFORE legacy accountingPostJe',
   strpos($ap, "sprintf('ap:bill:%d:post:reclass',") < strpos($ap, "sprintf('ap:bill:%d:post',"));
$a('AP legacy expense-recognition path still present',
   str_contains($ap, "sprintf('ap:bill:%d:post', \$id)"));
$a('OLD multi-period-split phrases removed from AP bill handler',
   !str_contains($ap, "accountingBuildBillJEBatch")
   && !str_contains($ap, "'via'               => 'multi_period_split'")
   && !str_contains($ap, 'is_recognition_period'));

echo "\n5. Bundle accrual hook in timeBuildBundlesForPeriod (modules/time/lib/time.php)\n";
$time = (string) file_get_contents($root . '/modules/time/lib/time.php');
$a('time.php requires multi_period helper after bundle build',
   str_contains($time, "require_once __DIR__ . '/../../accounting/lib/multi_period.php';"));
$a('hook gated by multi_period_split_enabled',
   str_contains($time, "if (!empty(\$settings['multi_period_split_enabled']))"));
$a('hook iterates over $built bundles',
   str_contains($time, 'foreach ($built as $b) {'));
$a('hook only fires for ar/ap bundle types',
   str_contains($time, "if (!in_array(\$b['bundle_type'], ['ar', 'ap'], true)) continue;"));
$a('hook calls accountingPostBundleAccrual',
   str_contains($time, 'accountingPostBundleAccrual('));
$a('hook log-and-swallows failures (does not block bundle build)',
   str_contains($time, '[time.bundle.accrual]')
   && str_contains($time, '} catch (\Throwable $e) {'));

echo "\n6. Sprint 7e contract still green (event-layer first in both handlers)\n";
// Strip PHP comments before ordering checks so a stray reference in a
// comment block doesn't falsely break the strpos invariant — mirrors
// module_emission_discipline_smoke's approach.
$stripPhpComments = static function (string $s): string {
    return preg_replace([
        '#/\*.*?\*/#s',
        '#//[^\n]*#',
        '/\#[^\n]*/',
    ], '', $s);
};
$invCode = $stripPhpComments($inv);
$apCode  = $stripPhpComments($ap);
$a('Invoice: accountingProcessEvent before accountingPostJe',
   strpos($invCode, 'accountingProcessEvent(') < strpos($invCode, 'accountingPostJe('));
$a('AP bill: accountingProcessEvent before accountingPostJe',
   strpos($apCode, 'accountingProcessEvent(') < strpos($apCode, 'accountingPostJe('));
$a('AP bill: moduleEmissionDisciplineLog before legacy direct post',
   strpos($apCode, "moduleEmissionDisciplineLog('ap'") < strpos($apCode, "accountingPostJe(\$tid, ["));

echo "\n7. PHP syntax\n";
foreach ([
    $root . '/modules/accounting/lib/multi_period.php',
    $root . '/modules/billing/api/invoices.php',
    $root . '/modules/ap/api/bills.php',
    $root . '/modules/time/lib/time.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Accrual-at-approval wire-in smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
