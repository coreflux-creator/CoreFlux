<?php
/**
 * Smoke — multi-period JE split, B-part-2:
 *   1. AP mirror (`accountingBuildBillJEBatch`) shape + balance
 *   2. accountingEnsureAccrualAccounts() auto-seed behaviour
 *   3. Invoice post handler wire-in (feature-flag gated)
 *   4. Bill post handler wire-in (feature-flag gated)
 *
 * Pure source-level + pure-PHP logic (no live DB). The B-part-1 smoke
 * `multi_period_je_split_smoke.php` already covers the invoice batch
 * builder + scenario matrix; this one extends to the AP mirror plus
 * confirms the gating + ensure-accounts plumbing.
 */
declare(strict_types=1);

require_once '/app/modules/accounting/lib/multi_period.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

function mkPeriod(int $id, string $start, string $end, ?int $num = null): array {
    return ['id' => $id, 'period_number' => $num ?? $id, 'start_date' => $start, 'end_date' => $end, 'status' => 'open'];
}

echo "\n1. AP mirror — single-period bill (degenerate)\n";
$periods = [['period' => mkPeriod(601, '2026-01-01', '2026-01-31'),
             'amounts' => ['5000' => 1000.00]]];
$bill = ['id' => 1, 'bill_date' => '2026-01-20', 'vendor_company_id' => 11,
         'internal_ref' => 'BILL-001', 'total' => 1000.00, 'subtotal' => 1000.00, 'tax_total' => 0];
$batch = accountingBuildBillJEBatch($bill, $periods, '21500');
$a('1 JE for single-period bill', count($batch) === 1);
$a('JE marked is_recognition_period=true', $batch[0]['is_recognition_period'] === true);
$a('contains AP 2000 credit for full amount',
   (bool) array_filter($batch[0]['lines'], fn($l) => $l['account_code'] === '2000' && (float) $l['credit'] === 1000.0));
$a('contains expense debit 5000 for full amount',
   (bool) array_filter($batch[0]['lines'], fn($l) => $l['account_code'] === '5000' && (float) $l['debit'] === 1000.0));
$dr = array_sum(array_column($batch[0]['lines'], 'debit'));
$cr = array_sum(array_column($batch[0]['lines'], 'credit'));
$a('JE balances', round($dr, 2) === round($cr, 2));

echo "\n2. AP mirror — bill spanning month boundary (TWO JEs)\n";
// Contractor week Mon Jan 29 → Sun Feb 4. Bill received Feb 5.
// $1400 cost total; Jan portion $600, Feb portion $800.
$periods = [
    ['period' => mkPeriod(701, '2026-01-01', '2026-01-31', 1), 'amounts' => ['5000' => 600.00]],
    ['period' => mkPeriod(702, '2026-02-01', '2026-02-28', 2), 'amounts' => ['5000' => 800.00]],
];
$bill = ['id' => 2, 'bill_date' => '2026-02-05', 'vendor_company_id' => 12,
         'internal_ref' => 'BILL-002', 'total' => 1400.00, 'subtotal' => 1400.00, 'tax_total' => 0];
$batch = accountingBuildBillJEBatch($bill, $periods, '21500');
$a('TWO JEs produced', count($batch) === 2);
$a('JE0 (Jan) is accrual, posts on period end_date',
   $batch[0]['date'] === '2026-01-31' && $batch[0]['is_recognition_period'] === false);
$a('JE1 (Feb) is recognition, posts on bill_date',
   $batch[1]['date'] === '2026-02-05' && $batch[1]['is_recognition_period'] === true);
// Jan accrual: Dr 5000 $600 / Cr 21500 $600
$a('Jan JE Dr 5000 $600 expense',
   (bool) array_filter($batch[0]['lines'], fn($l) => $l['account_code'] === '5000' && (float) $l['debit'] === 600.0));
$a('Jan JE Cr 21500 AP Accrued $600',
   (bool) array_filter($batch[0]['lines'], fn($l) => $l['account_code'] === '21500' && (float) $l['credit'] === 600.0));
$dr0 = array_sum(array_column($batch[0]['lines'], 'debit'));
$cr0 = array_sum(array_column($batch[0]['lines'], 'credit'));
$a('Jan JE balances at 600.0', round($dr0, 2) === 600.0 && round($cr0, 2) === 600.0);
// Feb recognition: Dr 5000 $800 + Dr 21500 $600 (clear accrual) / Cr 2000 $1400
$a('Feb JE Dr 5000 $800 (Feb portion expense)',
   (bool) array_filter($batch[1]['lines'], fn($l) => $l['account_code'] === '5000' && (float) $l['debit'] === 800.0));
$a('Feb JE Dr 21500 $600 (clears Jan accrual)',
   (bool) array_filter($batch[1]['lines'], fn($l) => $l['account_code'] === '21500' && (float) $l['debit'] === 600.0));
$a('Feb JE Cr 2000 AP $1400 (full payable)',
   (bool) array_filter($batch[1]['lines'], fn($l) => $l['account_code'] === '2000'  && (float) $l['credit'] === 1400.0));
$dr1 = array_sum(array_column($batch[1]['lines'], 'debit'));
$cr1 = array_sum(array_column($batch[1]['lines'], 'credit'));
$a('Feb JE balances at 1400.0', round($dr1, 2) === 1400.0 && round($cr1, 2) === 1400.0);

// Aggregate expense across both JEs equals bill subtotal (no leakage,
// no double-counting from the accrual clear).
$totalExp = 0.0;
foreach ($batch as $je) {
    foreach ($je['lines'] as $l) {
        if ($l['account_code'] === '5000') {
            $totalExp += ((float) $l['debit']) - ((float) $l['credit']);
        }
    }
}
$a('aggregate net expense across JEs equals bill subtotal (1400)',
   round($totalExp, 2) === 1400.0);

echo "\n3. AP mirror — three-period span\n";
$periods = [
    ['period' => mkPeriod(801, '2025-11-02', '2025-11-29', 11), 'amounts' => ['5000' => 200.00]],
    ['period' => mkPeriod(802, '2025-11-30', '2025-12-27', 12), 'amounts' => ['5000' => 400.00]],
    ['period' => mkPeriod(803, '2025-12-28', '2026-01-24', 13), 'amounts' => ['5000' => 800.00]],
];
$bill = ['id' => 3, 'bill_date' => '2026-01-20', 'vendor_company_id' => 13,
         'internal_ref' => 'BILL-Q4', 'total' => 1400.00, 'subtotal' => 1400.00, 'tax_total' => 0];
$batch = accountingBuildBillJEBatch($bill, $periods, '21500');
$a('three JEs produced', count($batch) === 3);
$a('P13 JE clears combined prior accruals (200 + 400 = 600)',
   (bool) array_filter($batch[2]['lines'], fn($l) => $l['account_code'] === '21500' && (float) $l['debit'] === 600.0));
foreach ($batch as $i => $je) {
    $dr = array_sum(array_column($je['lines'], 'debit'));
    $cr = array_sum(array_column($je['lines'], 'credit'));
    $a("AP JE{$i} balances", round($dr, 2) === round($cr, 2));
}

echo "\n4. accountingEnsureAccrualAccounts — defined + idempotent shape\n";
$a('function declared', function_exists('accountingEnsureAccrualAccounts'));
$lib = (string) file_get_contents('/app/modules/accounting/lib/multi_period.php');
$a('checks accounting_accounts for existing code (no double-insert)',
   str_contains($lib, "SELECT id FROM accounting_accounts WHERE tenant_id = :t AND account_code = :c LIMIT 1"));
$a('AR Unbilled inserted as account_type=asset',
   str_contains($lib, "'AR Unbilled (Accrued Revenue)', 'asset'"));
$a('AP Accrued inserted as account_type=liability',
   str_contains($lib, "'AP Accrued (Unbilled Costs)',   'liability'"));
$a('insert wrapped in try/catch (race-safe)',
   str_contains($lib, 'try {')
   && str_contains($lib, '$ins->execute(')
   && str_contains($lib, 'Race or schema drift'));

echo "\n5. Invoice post handler wire-in (billing/api/invoices.php)\n";
$inv = (string) file_get_contents('/app/modules/billing/api/invoices.php');
$a('require_once multi_period.php at top of post handler',
   str_contains($inv, "require_once __DIR__ . '/../../accounting/lib/multi_period.php';"));
$a('reads accountingSettingsGet($tid) inside post action',
   str_contains($inv, '$settings = accountingSettingsGet($tid);'));
$a('multi-period branch gated on multi_period_split_enabled',
   str_contains($inv, "if (!empty(\$settings['multi_period_split_enabled']))"));
$a('ensures accrual accounts before building batch',
   str_contains($inv, 'accountingEnsureAccrualAccounts($tid, $settings);'));
$a('builds breakdown via accountingBreakdownInvoiceByDate',
   str_contains($inv, 'accountingBreakdownInvoiceByDate($tid, (int) $id)'));
$a('groups via accountingGroupBreakdownByPeriod',
   str_contains($inv, 'accountingGroupBreakdownByPeriod($tid, (int) ($row[\'entity_id\'] ?? 0), $byDate)'));
$a('only takes multi-JE path when perPeriod count > 1',
   str_contains($inv, 'if (count($perPeriod) > 1)'));
$a('single-period falls through to legacy path (comment present)',
   str_contains($inv, 'Single-period fall-through'));
$a('each multi JE gets its own idempotency_key',
   str_contains($inv, "sprintf('billing:invoice:%d:post:mp:%d', \$id, \$i)"));
$a('multi-period audit tagged via=multi_period_split',
   str_contains($inv, "'via' => 'multi_period_split'"));
$a('response includes periods_spanned count',
   str_contains($inv, "'periods_spanned'   => count(\$batch)"));
$a('multi-period failure rolls back transaction (loud-fail)',
   str_contains($inv, 'if ($pdo_mp->inTransaction()) $pdo_mp->rollBack();')
   && str_contains($inv, "api_error('Multi-period post failed: "));

echo "\n6. AP bill post handler wire-in (ap/api/bills.php)\n";
$ap = (string) file_get_contents('/app/modules/ap/api/bills.php');
$a('require_once multi_period.php at top of post handler',
   str_contains($ap, "require_once __DIR__ . '/../../accounting/lib/multi_period.php';"));
$a('reads accountingSettingsGet inside post action',
   str_contains($ap, '$settings = accountingSettingsGet($tid);'));
$a('gated on multi_period_split_enabled flag',
   str_contains($ap, "if (!empty(\$settings['multi_period_split_enabled']))"));
$a('uses AP-side breakdown helper',
   str_contains($ap, 'accountingBreakdownBillByDate($tid, (int) $id)'));
$a('builds AP-side batch with ap_accrued account code',
   str_contains($ap, "accountingBuildBillJEBatch(\$row, \$perPeriod, (string) \$settings['ap_accrued_account_code'])"));
$a('AP multi-period only fires when count > 1',
   str_contains($ap, 'if (count($perPeriod) > 1)'));
$a('AP idempotency_key per JE',
   str_contains($ap, "sprintf('ap:bill:%d:post:mp:%d', \$id, \$i)"));
$a('AP multi audit tagged via=multi_period_split',
   str_contains($ap, "'via'               => 'multi_period_split'"));
$a('AP loud-fails on prep error with 422',
   str_contains($ap, "api_error('Multi-period split prep failed: "));

echo "\n7. Legacy single-period path preserved (regression guard)\n";
$a('invoice legacy accountingPostJe call still present',
   substr_count($inv, 'accountingPostJe(') >= 2); // multi-period + legacy
$a('AP legacy accountingPostJe call still present',
   substr_count($ap, 'accountingPostJe(') >= 2);

echo "\n8. PHP syntax\n";
foreach ([
    '/app/modules/accounting/lib/multi_period.php',
    '/app/modules/billing/api/invoices.php',
    '/app/modules/ap/api/bills.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Multi-period JE split B-part-2 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
