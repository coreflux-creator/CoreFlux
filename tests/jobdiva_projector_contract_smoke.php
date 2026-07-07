<?php
/**
 * Smoke: JobDiva projector contract.
 *
 * Locks the source-of-truth split:
 * - JobDiva native mirrors are evidence.
 * - The projector resolves identities and writes canonical CoreFlux graphs.
 * - Field Mapping Studio enriches resolved graph owners; it is not a
 *   competing placement/job/client/rate writer.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ok - {$label}\n"; }
    else { $fail++; $failures[] = $label; echo "  FAIL - {$label}\n"; }
};
$read = static fn(string $p): string => (string) file_get_contents($p);

echo "JobDiva projector contract smoke\n";
echo "================================\n";

$projectorPath = "$root/core/jobdiva/projector.php";
$projector = $read($projectorPath);
$sync = $read("$root/core/jobdiva/sync.php");
$alignment = $read("$root/core/jobdiva/mapping_alignment.php");

echo "\n1. Projector service exists and declares the canonical adapter stages\n";
$a('projector file exists', file_exists($projectorPath));
$a('contract function exists', str_contains($projector, 'function jobdivaProjectorContract(): array'));
foreach (['mirror', 'identity_resolve', 'project_coreflux', 'workflow_readiness', 'field_map_enrichment'] as $stage) {
    $a("contract stage {$stage}", str_contains($projector, "'{$stage}'"));
}
$a('contract points at canonical graph catalog', str_contains($projector, 'jobdivaCanonicalGraphCatalog()'));

echo "\n2. Placement projection owns write sequencing\n";
$a('placement projector function exists',
    str_contains($projector, 'function jobdivaProjectorProjectPlacement(int $tenantId, array $payload'));
$a('projector joins native mirrors before projection',
    str_contains($projector, 'jobdivaPlacementPayloadWithMirrors($tenantId, $payload, $joinStats)'));
$a('projector resolves end client through JobDiva customer/company mappings',
    str_contains($projector, 'function jobdivaProjectorResolveEndClientCompany')
    && str_contains($projector, "foreach (['jobdiva_customer', 'company'] as \$mapType)")
    && str_contains($projector, "mappingFindInternal(\$tenantId, 'jobdiva', \$mapType, \$customerExtId)"));
$a('projector delegates placement/rate write to canonical sync writer',
    str_contains($projector, 'jobdivaSyncUpsertPlacement('));
$a('projector binds canonical placement mapping',
    str_contains($projector, "mappingUpsert(\$tenantId, 'jobdiva', 'placement'"));
$a('projector indexes joined canonical roots',
    str_contains($projector, 'jobdivaIndexJoinedSubPayloads($tenantId, $writePayload)'));
$a('projector applies tenant mappings after identity resolution',
    str_contains($projector, 'jobdivaApplyPlacementFieldMappings('));
$a('projector emits workflow readiness',
    str_contains($projector, 'jobdivaProjectorPlacementReadiness($tenantId, $placementId)'));

echo "\n3. Runtime paths use the projector\n";
$a('sync requires projector', str_contains($sync, "require_once __DIR__ . '/projector.php'"));
$a('live placement sync calls projector',
    str_contains($sync, 'jobdivaProjectorProjectPlacement($tid, $jd, $userId'));
$a('mirror reproject calls projector',
    str_contains($sync, 'jobdivaProjectorProjectPlacement($tenantId, $payload, $userId'));
$a('reproject passes existing placement id through projector',
    str_contains($sync, "'existing_placement_id' => \$placementId"));

echo "\n4. Alignment report surfaces readiness drift\n";
$a('alignment exposes projector contract stages',
    str_contains($alignment, 'jobdivaProjectorContract()')
    && str_contains($alignment, "'contract_stages'"));
$a('alignment exposes projector readiness counts',
    str_contains($alignment, 'jobdivaProjectorReadinessCounts($tenantId)')
    && str_contains($alignment, "'workflow_readiness'"));
foreach (['placement_missing_staffing_job', 'placement_missing_rate_row', 'placement_active_missing_approved_rate'] as $code) {
    $a("alignment issue {$code}", str_contains($alignment, $code));
}

echo "\n5. Syntax\n";
foreach ([$projectorPath, "$root/core/jobdiva/sync.php", "$root/core/jobdiva/mapping_alignment.php"] as $path) {
    $lint = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    $a('php -l ' . basename($path), str_contains((string) $lint, 'No syntax errors detected'));
}

echo "\n================================\n";
echo "JobDiva projector contract smoke: {$pass} ok / {$fail} fail\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! {$msg}\n";
    exit(1);
}
exit(0);
