<?php
/**
 * Smoke: JobDiva integration-data alignment.
 *
 * Locks the fix for the confusing JobDiva mapping split:
 * - canonical mappings vs mirror-only payloads are explicitly modeled;
 * - JobDiva placements write placements.client_id through the staffing
 *   consumer bridge, so billing/payroll readiness can group by client;
 * - operators have one alignment cockpit and a repair action.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ok - {$label}\n"; }
    else { $fail++; $failures[] = $label; echo "  FAIL - {$label}\n"; }
};
$read = static fn(string $p): string => (string) file_get_contents($p);

echo "JobDiva mapping alignment smoke\n";
echo "===============================\n";

$sync = $read("$root/core/jobdiva/sync.php");
$clients = $read("$root/modules/staffing/lib/clients.php");
$servicePath = "$root/core/jobdiva/mapping_alignment.php";
$service = $read($servicePath);
$apiPath = "$root/api/admin/integrations/jobdiva_mapping_alignment.php";
$api = $read($apiPath);
$ui = $read("$root/dashboard/src/pages/JobDivaSettings.jsx");

echo "\n1. JobDiva placement sync writes the staffing client bridge\n";
$a('sync requires staffing client bridge helper',
    str_contains($sync, "/../../modules/staffing/lib/clients.php"));
$a('placement upsert accepts actor user for bridge creation',
    str_contains($sync, 'function jobdivaSyncUpsertPlacement(int $tid, int $personId, ?int $endClientCompanyId, array $jd, string $extId, ?int $userId = null)'));
$a('placement sync passes user id into upsert',
    str_contains($sync, 'jobdivaSyncUpsertPlacement($tid, $personId, $endClientCompanyId, $jd, $extId, $userId)'));
$a('sync calls staffingClientEnsureForCompany for JobDiva end-client',
    str_contains($sync, 'staffingClientEnsureForCompany($tid, $endClientCompanyId, $clientBridgeName'));
$a('update field set includes client_id',
    str_contains($sync, "'client_id'            => ['cli',   \$clientId]"));
$a('insert column list includes client_id next to end_client_company_id',
    str_contains($sync, 'end_client_name, end_client_company_id, client_id'));
$a('insert values bind :cli and preserve staffing job link slot',
    str_contains($sync, ':rp, :notes, :ecn, :ecc, :cli, :sji, :can'));

echo "\n2. Staffing client bridge is tenant-explicit\n";
$a('helper no longer scopedUpdate()s staffing_clients',
    !str_contains($clients, "scopedUpdate('staffing_clients'"));
$a('helper no longer scopedInsert()s staffing_clients',
    !str_contains($clients, "scopedInsert('staffing_clients'"));
$a('helper update is guarded by tenant_id and id',
    str_contains($clients, 'WHERE tenant_id = :tenant_id AND id = :id'));
$a('helper insert explicitly sets tenant_id from argument',
    str_contains($clients, "\$payload['tenant_id'] = \$tenantId"));

echo "\n3. Alignment service models canonical roots plus native mirrors\n";
$a('alignment service file exists', file_exists($servicePath));
$a('canonical object map function exists',
    str_contains($service, 'function jobdivaMappingCanonicalObjectMap(): array'));
$a('object map is sourced from canonical graph catalog',
    str_contains($service, 'jobdivaCanonicalGraphCatalog()')
    && str_contains($service, "\$row['mapping_kind'] = 'canonical'"));
$a('report keeps native mirrors as secondary diagnostics',
    str_contains($service, 'native_payload_mirrors')
    && str_contains($service, 'native_facets_vs_canonical_roots'));
$a('customer id semantic tension documented',
    str_contains($service, "'code' => 'customer_id_semantics'"));
$a('canonical mapping and field counts are exposed',
    str_contains($service, 'canonical_mapping_counts')
    && str_contains($service, 'canonical_field_coverage'));
$a('report flags placements missing staffing client',
    str_contains($service, 'placement_missing_staffing_client'));
$a('repair function exists and uses staffing bridge',
    str_contains($service, 'function jobdivaMappingRepairStaffingClientLinks')
    && str_contains($service, 'staffingClientEnsureForCompany($tenantId, $companyId, $name'));
$a('duplicate placement detector and repair function exist',
    str_contains($service, 'duplicate_jobdiva_placement_rows')
    && str_contains($service, 'function jobdivaMappingRepairDuplicatePlacements')
    && str_contains($service, '_jobdivaMappingDuplicatePlacementBlockingChildren'));

echo "\n4. Alignment API is wired and gated\n";
$a('alignment API file exists', file_exists($apiPath));
$a('API requires alignment service',
    str_contains($api, 'core/jobdiva/mapping_alignment.php'));
$a('GET returns report',
    str_contains($api, "if (\$method === 'GET')")
    && str_contains($api, 'jobdivaMappingAlignmentReport($tid'));
$a('POST repair_client_links action is wired',
    str_contains($api, "repair_client_links")
    && str_contains($api, 'jobdivaMappingRepairStaffingClientLinks($tid'));
$a('POST repair_duplicate_placements action is wired',
    str_contains($api, "repair_duplicate_placements")
    && str_contains($api, 'jobdivaMappingRepairDuplicatePlacements('));
$a('API uses integration RBAC gates',
    str_contains($api, 'rbac_legacy_require_any')
    && str_contains($api, 'integrations.jobdiva.view')
    && str_contains($api, 'integrations.jobdiva.manage'));

echo "\n5. JobDiva settings exposes the cockpit\n";
$a('settings loads alignment endpoint',
    str_contains($ui, '/api/admin/integrations/jobdiva_mapping_alignment.php'));
$a('settings mounts mapping alignment card',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-card"'));
$a('settings has repair client links button',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-repair-client-links"'));
$a('settings has duplicate placement preview + repair buttons',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-preview-duplicate-placements"')
    && str_contains($ui, 'data-testid="jobdiva-mapping-alignment-repair-duplicate-placements"'));
$a('settings renders canonical object map',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-object-map"'));
$a('settings renders mirror-only section',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-mirror-only"'));
$a('settings renders issue rows by code',
    str_contains($ui, 'jobdiva-mapping-alignment-issue-${issue.code}'));

echo "\n6. Syntax\n";
foreach ([$servicePath, $apiPath, "$root/core/jobdiva/sync.php", "$root/modules/staffing/lib/clients.php"] as $path) {
    $lint = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    $a('php -l ' . basename($path), str_contains((string) $lint, 'No syntax errors detected'));
}

echo "\n===============================\n";
echo "JobDiva mapping alignment smoke: {$pass} ok / {$fail} fail\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! {$msg}\n";
    exit(1);
}
exit(0);
