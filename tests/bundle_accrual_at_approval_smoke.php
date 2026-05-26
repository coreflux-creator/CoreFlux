<?php
/**
 * Smoke — bundle accrual at timesheet-approval (2026-02 architectural
 * correction).
 *
 * Source-level coverage for the new accrual model:
 *   • accountingBreakdownBundleByDate distributes amounts by hours
 *     and rejects bundle_types that aren't ar/ap.
 *   • accountingPostBundleAccrual rejects payroll/revrec bundles
 *     up-front (no GL write).
 *   • The helper's posted JE shape uses AR Unbilled / Revenue (4000)
 *     for ar bundles and Expense (5000) / AP Accrued for ap bundles.
 *   • Idempotency key embeds bundle_id + period_id.
 *   • source_module routes through 'time' (recognition event owner),
 *     source_ref_type='time_bundle' so the audit trail is unambiguous.
 *
 * No live DB. The shape-level + early-return paths are deterministic
 * and exercise the most regression-prone code paths.
 */
declare(strict_types=1);

require_once '/app/modules/accounting/lib/multi_period.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. accountingBreakdownBundleByDate input validation\n";
try {
    accountingBreakdownBundleByDate(1, 1, 'payroll');
    $a('rejects payroll bundle type', false, 'no exception thrown');
} catch (\RuntimeException $e) {
    $a('rejects payroll bundle type',
        str_contains($e->getMessage(), "Bundle accrual only supports ar/ap"));
}
try {
    accountingBreakdownBundleByDate(1, 1, 'revrec');
    $a('rejects revrec bundle type', false, 'no exception thrown');
} catch (\RuntimeException $e) {
    $a('rejects revrec bundle type',
        str_contains($e->getMessage(), "Bundle accrual only supports ar/ap"));
}

echo "\n2. accountingPostBundleAccrual gating\n";
$a('payroll bundle short-circuits to empty array (no GL post)',
   accountingPostBundleAccrual(1, 9999, 'payroll') === []);
$a('revrec bundle short-circuits to empty array',
   accountingPostBundleAccrual(1, 9999, 'revrec') === []);
$a('unknown bundle type short-circuits to empty array',
   accountingPostBundleAccrual(1, 9999, 'mystery') === []);

echo "\n3. Source-level shape (the JE the helper would build)\n";
$lib = (string) file_get_contents('/app/modules/accounting/lib/multi_period.php');

// AR shape:
$a('AR accrual: Dr AR Unbilled debit, Cr Revenue credit (per period)',
   str_contains($lib, "'account_code' => \$arUnbilled,  'debit' => round(\$amt, 2), 'credit' => 0,")
   && str_contains($lib, "'account_code' => \$revenueCode, 'debit' => 0, 'credit' => round(\$amt, 2),"));
$a('AR accrual revenue code defaults to 4000',
   str_contains($lib, "\$revenueCode = '4000';"));
$a('AR accrual carries counterparty_company_id (client) when known',
   str_contains($lib, "\$partyCompanyId = \$bundleType === 'ar' && !empty(\$partyRow['end_client_company_id'])"));

// AP shape:
$a('AP accrual: Dr Expense debit, Cr AP Accrued credit (per period)',
   str_contains($lib, "'account_code' => \$expenseCode, 'debit' => round(\$amt, 2), 'credit' => 0,")
   && str_contains($lib, "'account_code' => \$apAccrued,   'debit' => 0, 'credit' => round(\$amt, 2),"));
$a('AP accrual expense code defaults to 5000',
   str_contains($lib, "\$expenseCode = '5000';"));

// Period anchoring + idempotency:
$a('accrual posts on period end_date (not work_date) for unambiguous stamp',
   str_contains($lib, "'posting_date'    => (string) \$period['end_date'],"));
$a('idempotency key embeds bundle_id + period_id',
   str_contains($lib, "sprintf('time:bundle:%d:accrual:%d', \$bundleId, (int) \$period['id'])"));
$a('source_module set to time (recognition event owner)',
   str_contains($lib, "'source_module'   => \$bundleType === 'ar' ? 'time' : 'time'"));
$a('source_ref_type set to time_bundle',
   str_contains($lib, "'source_ref_type' => 'time_bundle'"));

echo "\n4. Distribution math: last-day-absorbs-rounding invariant\n";
// Verify the comment-documented invariant about no rounding drift. We
// can't drive accountingBreakdownBundleByDate without a live DB but
// we can assert the algorithm's source structure.
$a('amount allocated proportional to hours per day',
   str_contains($lib, '$share = (float) $d[\'hrs\'] / $totalHrs;'));
$a('last day absorbs rounding (no drift)',
   str_contains($lib, "(\$i === \$n - 1) ? round(\$total - \$allocated, 2) : round(\$total * \$share, 2)"));
$a('chronologically sorted output',
   str_contains($lib, 'ksort($byDate);'));

echo "\n5. Zero-bill / no-billable-hours handling\n";
$a('returns empty when total <= 0 (zero-bill bundle)',
   str_contains($lib, 'if (round($total, 2) <= 0.005) {'));
$a('returns empty when no billable entries found',
   str_contains($lib, 'if (!$days) return [];'));
$a('returns empty when totalHrs is 0',
   str_contains($lib, 'if ($totalHrs <= 0) return [];'));

echo "\n6. Time category filter — only billable hours drive accrual\n";
$a('filter excludes PTO/unpaid/non-billable',
   str_contains($lib, 'IN ("regular_billable","OT_billable")'));

echo "\n7. PHP syntax\n";
$out = []; $rc = 0;
exec('php -l /app/modules/accounting/lib/multi_period.php 2>&1', $out, $rc);
$a('multi_period.php syntax clean', $rc === 0, implode("\n", $out));

echo "\n=========================================\n";
echo "Bundle accrual at approval smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
