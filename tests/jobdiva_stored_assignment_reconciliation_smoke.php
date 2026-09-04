<?php
/**
 * Smoke: stored JobDiva assignments are the authoritative placement source.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0; $failures = [];
$assert = static function (string $label, bool $condition) use (&$pass, &$fail, &$failures): void {
    if ($condition) {
        $pass++;
        echo "  ok - {$label}\n";
        return;
    }
    $fail++;
    $failures[] = $label;
    echo "  FAIL - {$label}\n";
};
$read = static fn(string $path): string => (string) file_get_contents($path);

echo "JobDiva stored assignment reconciliation smoke\n";
echo "==============================================\n";

$sync = $read("$root/core/jobdiva/sync.php");
$api = $read("$root/modules/placements/api/jobdiva_reconciliation.php");
$ui = $read("$root/modules/placements/ui/JobDivaReconciliation.jsx");

echo "\n1. Assignment mirrors own placement identity\n";
$assert(
    'stored plan starts from jobdiva_assignment mirrors',
    str_contains($sync, 'function jobdivaStoredAssignmentProjectionPlan(')
    && str_contains($sync, "internal_entity_type = 'jobdiva_assignment'")
);
$assert(
    'live discovery persists verified assignment evidence before projection',
    str_contains($sync, '// Persist verified Start evidence before projecting it.')
    && str_contains($sync, "jobdivaMirrorStoreAndIndex(\n                \$tid,\n                'jobdiva_assignment'")
);
$assert(
    'final replay uses stored assignments rather than placement snapshots',
    str_contains($sync, 'function jobdivaReprojectStoredAssignmentGraphs(')
    && str_contains($sync, "static fn() => jobdivaReprojectStoredAssignmentGraphs(")
);

echo "\n2. Related source facets join by foreign key\n";
foreach (['jobs_joined', 'candidates_joined', 'contacts_joined', 'companies_joined', 'assignments_joined'] as $facet) {
    $assert("join statistic {$facet}", str_contains($sync, "'{$facet}'"));
}
$assert(
    'company join refuses shallow customer-id identity',
    str_contains($sync, "shallow `customer id` is frequently a contact id")
    && str_contains($sync, "jobdivaMirrorPayloadByExternalId(\$tenantId, 'company', \$companyId)")
);

echo "\n3. Apply delegates the whole graph to the canonical projector\n";
$assert(
    'stored apply exists',
    str_contains($sync, 'function jobdivaApplyStoredAssignmentProjection(')
);
$assert(
    'stored apply calls canonical projector',
    str_contains($sync, "jobdivaProjectorProjectPlacement(\n                \$tenantId,\n                (array) \$row['__payload']")
);
$assert(
    'stored apply and automatic replay preserve the exact placement person identity',
    substr_count($sync, "'person_id' => (int) (\$row['current']['person_id'] ?? 0)") >= 2
);
$assert(
    'explicit stored reconciliation replaces stale overrides on JobDiva-owned contract fields',
    substr_count($sync, "'force_source_contract' => true") >= 2
    && str_contains($sync, "\$contractOwnedFields = [")
    && str_contains($sync, "'vendor_payment_terms_override'")
    && str_contains($sync, "'vendor_pwp_enabled'")
);
$projector = $read("$root/core/jobdiva/projector.php");
$assert(
    'canonical projector always loads the shared candidate resolver when needed',
    str_contains($projector, "!function_exists('jobdivaPlacementsAutoCreatePerson')")
    && str_contains($projector, "require_once __DIR__ . '/sync_placements.php';")
);
$assert(
    'projector forwards explicit source-contract authority to the canonical writer',
    str_contains($projector, "\$writePayload['__cf_force_source_contract'] = true;")
);
$economics = $read("$root/modules/placements/lib/economics.php");
$assert(
    'exact reconciliation clears stale field overrides only on source-managed participants',
    str_contains($economics, "!empty(\$party['force_source_fields'])")
    && str_contains($economics, 'AND source_managed = 1')
    && str_contains($economics, "\$party['force_source_fields'] = true;")
);
$assert(
    'stored apply is transactional',
    str_contains($sync, '$pdo->beginTransaction();')
    && str_contains($sync, 'if ($pdo->inTransaction()) $pdo->rollBack();')
);
$assert(
    'stored apply exposes exact-source safety contract',
    str_contains($sync, "'identity' => 'Exact verified JobDiva Start ID'")
    && str_contains($sync, "'deletes' => false")
    && str_contains($sync, "'archives' => false")
);
$assert(
    'exact archived matches require an explicit restore selection',
    str_contains($sync, "\$outcome = \$isArchived ? 'restore'")
    && str_contains($sync, "'restores' => 'Explicit selection only")
    && str_contains($sync, "\$row['outcome'] === 'restore'")
);
$assert(
    'automatic replay never restores archived rows',
    str_contains($sync, "empty(\$row['selectable']) || \$row['outcome'] === 'restore'")
);

echo "\n4. API and UI make stored reconciliation primary\n";
$assert(
    'API exposes stored preview and apply',
    str_contains($api, "\$action === 'stored_preview'")
    && str_contains($api, "\$action === 'stored_apply'")
    && str_contains($api, 'APPLY_STORED_JOBDIVA_ASSIGNMENTS')
);
$assert(
    'UI automatically loads stored source preview',
    str_contains($ui, 'refreshStored();')
    && str_contains($ui, '?action=stored_preview')
);
$assert(
    'UI projects stored assignments through explicit selection',
    str_contains($ui, 'Project selected assignments')
    && str_contains($ui, '?action=stored_apply')
);
$assert(
    'CSV is labeled fallback',
    str_contains($ui, '>CSV fallback<')
);

echo "\n==============================================\n";
echo "Stored assignment reconciliation: {$pass} ok / {$fail} fail\n";
if ($fail > 0) {
    foreach ($failures as $failure) echo " ! {$failure}\n";
    exit(1);
}
exit(0);
