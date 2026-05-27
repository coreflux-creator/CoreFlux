<?php
/**
 * Smoke — Consolidation methods (P1.10): proportionate, equity, CTA.
 *
 * Spec re-audit decision (2026-02): "Consolidation supports both
 * equity method and proportionate consolidation, plus CTA postings."
 *
 * Source-level wire-up checks only — full DB integration is exercised
 * by the existing consolidation_runs functional smokes.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$lib = (string) file_get_contents('/app/modules/accounting/lib/consolidation.php');

echo "\n1. proportionate accepted as a valid consolidation_method\n";
$a('relationship-upsert validation allows proportionate',
    str_contains($lib, "if (!in_array(\$method, ['full','proportionate','equity','cost','none']"));

echo "\n2. consolidateTrialBalanceWithMethods — top-level API\n";
$a('function declared',                       str_contains($lib, 'function consolidateTrialBalanceWithMethods('));
$a('signature accepts opts array w/ root_entity_id + reporting_currency',
    str_contains($lib, "array \$opts = []"));
$a('returns rows + method_treatments + cta_adjustments + reporting_currency',
    str_contains($lib, "'method_treatments' => \$treatments")
    && str_contains($lib, "'cta_adjustments'   => \$ctaAdjustments")
    && str_contains($lib, "'reporting_currency'=> \$reportingCcy"));

echo "\n3. Per-entity treatment bucketing\n";
$a('cost/none entities excluded from line-by-line',
    str_contains($lib, "if (\$w['excluded']) continue;"));
$a('equity entities routed to equity bucket (no line pickup)',
    str_contains($lib, "if (\$w['method'] === 'equity') \$equity[\$eid] = \$w;"));
$a('full + proportionate routed to weighted bucket',
    str_contains($lib, "else                           \$fullProp[\$eid] = \$w;"));

echo "\n4. Weighted TB aggregation\n";
$a('_consolidationPerEntityWeightedTB queries GROUP BY je.entity_id, a.id',
    str_contains($lib, 'GROUP BY je.entity_id, a.id'));
$a('debit + credit scaled by per-entity weight',
    str_contains($lib, "\$byCode[\$code]['debit']  += (float) \$r['debit']  * \$weight;")
    && str_contains($lib, "\$byCode[\$code]['credit'] += (float) \$r['credit'] * \$weight;"));

echo "\n5. Equity pickup\n";
$a('_consolidationEquityPickup helper exists',
    str_contains($lib, 'function _consolidationEquityPickup('));
$a('pickup = ownership_pct × net_income from revenue/expense/cogs lines',
    str_contains($lib, 'a.account_type IN ("revenue","expense","cogs")')
    && str_contains($lib, '$pickup = round($netIncome * $weight, 2);'));
$a('synthetic row tagged "equity_pickup" with source_entity_id',
    str_contains($lib, "'synthetic'      => 'equity_pickup'")
    && str_contains($lib, "'source_entity_id' => \$entityId,"));
$a('null returned when net income ≈ 0 (no pickup row)',
    str_contains($lib, "if (abs(\$pickup) < 0.005) return null;"));

echo "\n6. CTA (Cumulative Translation Adjustment)\n";
$a('_consolidationApplyCTA helper exists + signature accepts \$rows by ref',
    str_contains($lib, 'function _consolidationApplyCTA(int $tenantId, array $entityIds, string $asOf, string $reportingCcy, array &$rows)'));
$a('skips entities whose functional_currency equals reporting currency',
    str_contains($lib, "if (\$ccy === \$reportingCcy || \$ccy === '') continue;"));
$a('looks up closing + average FX rates from accounting_fx_rates',
    str_contains($lib, "_consolidationLookupFxRate(\$tenantId, \$ccy, \$reportingCcy, \$asOf)"));
$a('synthetic CTA row carries currency_from, currency_to, rate_close, rate_average',
    str_contains($lib, "'currency_from'  => \$ccy")
    && str_contains($lib, "'currency_to'    => \$reportingCcy")
    && str_contains($lib, "'rate_close'     => \$rateClose")
    && str_contains($lib, "'rate_average'   => \$rateAvg"));
$a('soft-degrades when accounting_fx_rates table missing',
    str_contains($lib, '// accounting_fx_rates table missing or schema drift — soft degrade.'));

echo "\n7. consolidationComputeEntityWeights\n";
$a('function declared + reads from relationship descendants graph',
    str_contains($lib, 'function consolidationComputeEntityWeights(int $tenantId, int $rootEntityId, array $entityIds)')
    && str_contains($lib, '$descendants = entityRelationshipResolveDescendants($tenantId, $rootEntityId, date(\'Y-m-d\'));'));
$a('root entity always carries method=full, weight=1.0',
    str_contains($lib, "\$out[\$eid] = ['method' => 'full', 'weight' => 1.0, 'excluded' => false];"));
$a('cost / none flagged as excluded',
    str_contains($lib, "\$excluded = in_array(\$m, ['cost','none'], true);"));

echo "\n8. PHP syntax\n";
$out = []; $rc = 0;
exec('php -l /app/modules/accounting/lib/consolidation.php 2>&1', $out, $rc);
$a('php -l consolidation.php', $rc === 0, implode("\n", $out));

echo "\n=========================================\n";
echo "Consolidation methods + CTA (P1.10) smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
