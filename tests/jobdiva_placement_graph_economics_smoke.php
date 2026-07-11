<?php
/**
 * Smoke - JobDiva placement projection covers classification, chain, and economics.
 *
 * Source-level coverage for the operator complaint:
 *   - every placement defaulting to w2
 *   - vendors / MSP / discounts not landing in placement_client_chain
 *   - rate economics stopping at bill/pay only
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$sync = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");
$fieldMap = (string) file_get_contents("{$ROOT}/core/integrations/field_map.php");
$apply = (string) file_get_contents("{$ROOT}/core/integrations/field_map_apply.php");
$projector = (string) file_get_contents("{$ROOT}/core/jobdiva/projector.php");
$migration = (string) file_get_contents("{$ROOT}/core/migrations/123_placement_chain_and_rate_writable_targets.sql");
$commissionMigration = (string) file_get_contents("{$ROOT}/core/migrations/124_placement_commission_writable_targets.sql");
$studio = (string) file_get_contents("{$ROOT}/dashboard/src/pages/FieldMappingStudio.jsx");

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. Engagement type is resolved, not blindly defaulted\n";
$a('normalizer exists', str_contains($sync, 'function jobdivaNormalisePlacementEngagementType'));
$a('classification candidates include JobDiva position/tax/payroll language',
    str_contains($sync, "'position type'")
    && str_contains($sync, "'tax type'")
    && str_contains($sync, "'payroll type'"));
$a('classification candidates include JobDiva C2C flag fields',
    str_contains($sync, "'crop to crop'")
    && str_contains($sync, "'corp to corp'")
    && str_contains($sync, 'function jobdivaInferPlacementEngagementTypeFromPayload')
    && str_contains($sync, 'jobdivaBoolishTrue($valueRaw)'));
$a('normalizer recognizes c2c/corp/vendor and observed typo crop-to-crop',
    str_contains($sync, "str_contains(\$s, 'c2c')")
    && str_contains($sync, "str_contains(\$s, 'crop to crop')")
    && str_contains($sync, "str_contains(\$s, 'vendor')"));
$a('silent source does not preserve stale imported engagement type',
    str_contains($sync, '$mappedEngagement = jobdivaNormalisePlacementEngagementType($engagementRaw, \'\')')
    && str_contains($sync, '($mappedEngagement !== \'\' ? $mappedEngagement : \'w2\')'));
$a('old direct fallback to w2 is gone',
    !str_contains($sync, '$engagementMap[strtolower(trim($engagementRaw))] ?? \'w2\''));

echo "\n2. Placement chain is projected\n";
$a('chain writer exists', str_contains($sync, 'function jobdivaSyncUpsertPlacementChain('));
$a('chain writer targets placement_client_chain', str_contains($sync, 'placement_client_chain'));
$a('sync calls chain writer from update and insert paths',
    substr_count($sync, 'jobdivaSyncUpsertPlacementChain($tid,') >= 2);
$a('chain writer creates MSP / prime vendor / sub-vendor tiers',
    str_contains($sync, "'role' => 'msp'")
    && str_contains($sync, "'role' => 'prime_vendor'")
    && str_contains($sync, "'role' => 'sub_vendor'"));
$a('chain context ids are available to generalized field mapping',
    str_contains($sync, 'function jobdivaPlacementChainContextIds')
    && str_contains($apply, "'placement_client_chain' => match"));
$a('chain mappings can create/reuse vendor-chain sibling rows',
    str_contains($apply, 'function integrationFieldMapEnsurePlacementChainRow')
    && str_contains($apply, 'integrationFieldMapFindPlacementChainRow($tenantId, $contextRowIds, $linked)')
    && str_contains($apply, "placement_client_chain' && \$colLower === 'party_name'"));
$a('projector declares placement_client_chain output',
    str_contains($projector, 'placement_client_chain')
    && str_contains($projector, 'vendor_chain'));
$a('Field Mapping Studio exposes placement chain linked-entity roles',
    str_contains($studio, 'placement_chain_prime_vendor')
    && str_contains($studio, 'placement_chain_msp')
    && str_contains($studio, "table === 'placement_client_chain'"));

echo "\n3. Rate economics include adders/background fees\n";
foreach (['adder_pct', 'background_fee_total'] as $f) {
    $a("field-map allow-list includes {$f}", str_contains($fieldMap, "'{$f}'"));
    $a("JobDiva rate writer resolves {$f}", str_contains($sync, "'jobdiva', 'placement', '{$f}', \$jd,"));
    $a("writable-target migration includes {$f}", str_contains($migration, "'{$f}'"));
}
$a('rate SQL writes adder/background on update and insert',
    str_contains($sync, 'adder_pct = :adder, background_fee_total = :bg')
    && str_contains($sync, 'adder_pct, background_fee_total)'));
$a('placement_rates mapping no longer falls through to placement id',
    str_contains($apply, "'placement_rates' => array_merge(['placement_rates'], \$rootSelfFallback)"));

echo "\n4. Placement commissions are projected and mappable\n";
$a('commission writer exists', str_contains($sync, 'function jobdivaSyncUpsertPlacementCommissions('));
$a('sync calls commission writer from update and insert paths',
    substr_count($sync, 'jobdivaSyncUpsertPlacementCommissions($tid,') >= 2);
$a('commission writer targets placement_commissions',
    str_contains($sync, 'INSERT INTO placement_commissions')
    && str_contains($sync, 'UPDATE placement_commissions'));
$a('commission context ids are available to generalized field mapping',
    str_contains($sync, 'function jobdivaPlacementCommissionContextIds')
    && str_contains($apply, "'placement_commissions' => match"));
$a('commission mappings can create/reuse commission sibling rows',
    str_contains($apply, 'function integrationFieldMapEnsurePlacementCommissionRow')
    && str_contains($apply, 'integrationFieldMapEnsurePlacementCommissionRow($tenantId, $contextRowIds, $linked)')
    && str_contains($apply, 'integrationFieldMapCommissionContextKey($linked)'));
$a('field-map allow-list includes commission economics',
    str_contains($fieldMap, "'split_pct'")
    && str_contains($fieldMap, "'flat_amount'")
    && str_contains($fieldMap, "'effective_from'"));
$a('writable-target migration exposes placement_commissions',
    str_contains($commissionMigration, "'placements', 'placement_commissions', 'split_pct'")
    && str_contains($commissionMigration, "'placement_commission_recruiter'")
    && str_contains($commissionMigration, "placement_commission_account_manager"));
$a('Field Mapping Studio exposes commission linked-entity roles',
    str_contains($studio, 'placement_commission_recruiter')
    && str_contains($studio, 'placement_commission_account_manager')
    && str_contains($studio, "table === 'placement_commissions'"));

echo "\n5. PHP syntax\n";
foreach ([
    "{$ROOT}/core/jobdiva/sync.php",
    "{$ROOT}/core/integrations/field_map.php",
    "{$ROOT}/core/integrations/field_map_apply.php",
    "{$ROOT}/core/jobdiva/projector.php",
] as $file) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
    $a('php -l ' . basename($file), $rc === 0, implode("\n", $out));
}

echo "\nJobDiva placement graph/economics smoke: {$pass} ✓ / {$fail} ✗\n";
exit($fail === 0 ? 0 : 1);
