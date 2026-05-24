<?php
/**
 * Smoke for the new jobdivaSyncUpsertPlacementRates cross-table writer.
 *
 * Context: the field-map registry surfaces bill_rate / pay_rate / etc.
 * under entity_type='placement' so the operator can map JobDiva's
 * "final bill rate" / "agreed pay rate" alongside title / start_date.
 * But these columns live on the placement_rates table, not placements.
 * This smoke asserts the syncer:
 *
 *   1. Resolves each rate field through tenantIntegrationFieldMapPluckInternal
 *      (so tenant overrides win over the built-in candidate list)
 *   2. Provides JobDiva V2 default candidates ("final bill rate",
 *      "agreed pay rate", "bill rate currency/unit", etc.) so the
 *      common case works without any tenant configuration
 *   3. Skips writing when bill_rate <= 0 (don't pollute the rate
 *      table with placeholder rows for direct-hire / unrated placements)
 *   4. UPSERTs the CURRENT row (effective_to IS NULL) — UPDATE if one
 *      already exists, INSERT otherwise
 *   5. Coerces rate_unit / currency to valid ENUM / CHAR(3) values
 *   6. Is called from BOTH the INSERT and UPDATE branches of
 *      jobdivaSyncUpsertPlacement (so newly-discovered placements AND
 *      re-synced placements both get rates)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');
$sync = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");
$lib  = (string) file_get_contents("{$ROOT}/core/integrations/field_map.php");

echo "Allow-list — rate fields surface under entity_type='placement'\n";
foreach (['bill_rate', 'bill_rate_unit', 'pay_rate', 'pay_rate_unit',
          'currency', 'ot_multiplier', 'dt_multiplier'] as $f) {
    $assert("allow-list contains placement.{$f}",
        preg_match("/'placement'\s*=>\s*\[[\s\S]*?'{$f}'/", $lib) === 1);
}

echo "\njobdivaSyncUpsertPlacementRates — declaration\n";
$assert('declared with the canonical 4-arg signature',
    strpos($sync, 'function jobdivaSyncUpsertPlacementRates(int $tid, int $placementId, string $startDate, array $jd): bool') !== false);
$assert('called from the INSERT branch (newly-created placement gets rates)',
    preg_match('/\$placementId = \(int\) \$pdo->lastInsertId\(\);\s*jobdivaSyncUpsertPlacementRates\(\$tid, \$placementId, \$startDate, \$jd\);/', $sync) === 1);
$assert('called from the UPDATE branch (re-sync overwrites rates)',
    preg_match('/jobdivaSyncUpsertPlacementRates\(\$tid, \$existingId, \$startDate, \$jd\);/', $sync) === 1);

echo "\njobdivaSyncUpsertPlacementRates — registry resolution\n";
foreach (['bill_rate', 'pay_rate', 'bill_rate_unit', 'pay_rate_unit',
          'currency', 'ot_multiplier', 'dt_multiplier'] as $f) {
    $assert("resolves {$f} via tenantIntegrationFieldMapPluckInternal",
        strpos($sync, "'jobdiva', 'placement', '{$f}', \$jd,") !== false);
}

echo "\njobdivaSyncUpsertPlacementRates — JobDiva V2 default candidates\n";
$assert('bill_rate defaults include "final bill rate" (V2 BI key)',
    strpos($sync, "'final bill rate', 'finalBillRate', 'final_bill_rate'") !== false);
$assert('pay_rate defaults include "agreed pay rate" (V2 BI key)',
    strpos($sync, "'agreed pay rate', 'agreedPayRate', 'agreed_pay_rate'") !== false);
$assert('bill_rate_unit defaults include "bill rate currency/unit"',
    strpos($sync, "'bill rate currency/unit'") !== false);

echo "\njobdivaSyncUpsertPlacementRates — coercion / validation\n";
$assert('skips when bill_rate <= 0 (no placeholder rows)',
    strpos($sync, 'if ($billRate <= 0) {') !== false
    && strpos($sync, 'return false;') !== false);
$assert('pay_rate falls back to bill_rate when JobDiva did not supply one (NOT NULL column)',
    strpos($sync, '$payRate = is_numeric($payRateRaw) ? (float) $payRateRaw : $billRate;') !== false);
$assert('unit coercion accepts "h" / "USD/Hour" / "Hourly" → hour',
    strpos($sync, "if (\$s === '' || \$s === 'h' || str_contains(\$s, 'hour')) return 'hour';") !== false);
$assert('currency is forced to CHAR(3) by extracting ISO-3 substring',
    strpos($sync, "preg_match('/\\b([A-Z]{3})\\b/'") !== false);

echo "\njobdivaSyncUpsertPlacementRates — UPSERT semantics\n";
$assert('locates the CURRENT rate row via effective_to IS NULL',
    strpos($sync, 'effective_to IS NULL') !== false);
$assert('UPDATE branch writes all rate columns + multipliers',
    strpos($sync, "UPDATE placement_rates\n                SET bill_rate = :br, bill_rate_unit = :bru,\n                    pay_rate  = :pr, pay_rate_unit  = :pru,\n                    currency  = :cur,\n                    ot_multiplier = :ot, dt_multiplier = :dt") !== false);
$assert('INSERT branch sets effective_from to placement start_date',
    strpos($sync, '$startDate !== \'\' ? $startDate : date(\'Y-m-d\')') !== false);
$assert('tenant_id is bound on INSERT (RLS defence-in-depth)',
    strpos($sync, "'t'   => \$tid, 'p'   => \$placementId,") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
