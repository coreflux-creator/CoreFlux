<?php
/**
 * Smoke test for the JobDiva field-first mapper on the placement detail page.
 *
 * This covers the operator workflow:
 *   1. Open a linked JobDiva placement.
 *   2. Pick the CoreFlux destination field first.
 *   3. See available JobDiva paths and sample values from the current record.
 *   4. Save the mapping with the full destination address.
 *   5. Immediately project the mapping into the open CoreFlux record.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        echo "  OK  {$msg}\n";
        $pass++;
    } else {
        echo "  FAIL {$msg}\n";
        $fail++;
    }
};

$root = realpath(__DIR__ . '/..');
$panel = (string) file_get_contents($root . '/dashboard/src/components/LinkedExternalSystemsPanel.jsx');

echo "\nJobDiva field-first mapping UI\n";
$assert('renders a CoreFlux-field-first mapper',
    str_contains($panel, 'data-testid="field-map-coreflux-first"')
    && str_contains($panel, 'data-testid="field-map-target-picker"')
    && str_contains($panel, 'data-testid="field-map-source-picker"'));

$assert('loads writable CoreFlux destinations from the catalog',
    str_contains($panel, "/api/admin/integrations/writable_targets.php")
    && str_contains($panel, 'expandWritableTargetForEntity(entityType, t)'));

$assert('loads all JobDiva placement source buckets for placement-level mappings',
    str_contains($panel, 'entity_type=*&limit=2000')
    && str_contains($panel, 'fieldIndexPathEntries(allJobDivaPathData, \'\', entityType)'));

$assert('uses live payload entries with sample values',
    str_contains($panel, 'function flattenPayloadScalarEntries(value')
    && str_contains($panel, 'sample_value: value')
    && str_contains($panel, 'sampleValueLabel(s.sample_value)'));

$assert('normalizes JobDiva numeric row wrappers before showing/saving paths',
    str_contains($panel, 'function isNumericKeyObject(value)')
    && str_contains($panel, 'function normalizeSourcePathForPicker(path)')
    && str_contains($panel, "replace(/\\.([0-9]+)(?=\\.|$)/g, '[]')")
    && str_contains($panel, 'firstNumericKeyObjectValue(value)')
    && str_contains($panel, 'normalizeSourcePathForPicker(prefixedSourcePath'));

$assert('shows searchable target/source lists',
    str_contains($panel, 'data-testid="field-map-target-search"')
    && str_contains($panel, 'data-testid="field-map-source-search"')
    && str_contains($panel, 'filteredTargetOptions.map')
    && str_contains($panel, 'filteredSourceEntries.map'));

$assert('saves source_path plus target table/column/linked role',
    str_contains($panel, 'source_path: sourcePath')
    && str_contains($panel, 'target_module: selectedTarget.target_module')
    && str_contains($panel, 'target_table: selectedTarget.target_table')
    && str_contains($panel, 'target_column: selectedTarget.target_column')
    && str_contains($panel, "linked_entity: selectedTarget.linked_entity || 'self'"));

$assert('immediately applies the saved mapping to the current record',
    str_contains($panel, 'const saveFieldFirst = async () =>')
    && str_contains($panel, 'const applied = await applyCurrentMappings({ quiet: true });')
    && str_contains($panel, "/api/admin/integrations/field_map_apply_now.php"));

$assert('expands placement chain and commission targets by role',
    str_contains($panel, 'const PLACEMENT_CHAIN_TARGETS')
    && str_contains($panel, 'placement_chain_prime_vendor')
    && str_contains($panel, 'placement_chain_sub_vendor')
    && str_contains($panel, 'const PLACEMENT_COMMISSION_TARGETS')
    && str_contains($panel, 'placement_commission_account_manager'));

$assert('default routing covers rates, chain, commissions, and corp details',
    str_contains($panel, 'const placementRateFields = new Set')
    && str_contains($panel, 'adder_pct')
    && str_contains($panel, 'const placementChainFields = new Set')
    && str_contains($panel, 'portal_fee_pct')
    && str_contains($panel, 'const placementCommissionFields = new Set')
    && str_contains($panel, 'split_pct')
    && str_contains($panel, 'const placementCorpFields = new Set')
    && str_contains($panel, 'corp_legal_name'));

$assert('surfaces apply skipped/error counts instead of hiding them',
    str_contains($panel, 'const skipped = Number(fieldMap.skipped ?? 0);')
    && str_contains($panel, 'const errors = Array.isArray(fieldMap.errors)')
    && str_contains($panel, 'const skippedReasons = Array.isArray(fieldMap.skipped_reasons)'));

$assert('auto-selects flag-to-enum transforms for worker fields',
    str_contains($panel, 'function inferFieldFirstTransform')
    && str_contains($panel, 'truthy_to_w2')
    && str_contains($panel, 'truthy_to_c2c')
    && str_contains($panel, 'transform: inferFieldFirstTransform(selectedTarget, s, prev.transform)'));

$assert('treats zero-write apply as a visible no-change warning',
    str_contains($panel, 'const applyHadNoWrites = (r) =>')
    && str_contains($panel, "Saved mapping, but nothing changed")
    && str_contains($panel, "Nothing changed"));

$assert('placement detail passes full root payload to the mapper',
    str_contains($panel, 'rootPayload={payload}'));

echo "\n=========================================\n";
echo "JobDiva field-first mapping UI smoke: {$pass} OK / {$fail} FAIL\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
