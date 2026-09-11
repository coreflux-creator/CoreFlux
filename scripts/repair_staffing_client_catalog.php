<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/jobdiva/mapping_alignment.php';

$tenantId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php scripts/repair_staffing_client_catalog.php TENANT_ID\n");
    exit(2);
}

$before = staffingClientCatalogIntegritySummary($tenantId);
$clientLinks = jobdivaMappingRepairStaffingClientLinks($tenantId, null, 5000);
$relinked = staffingClientRelinkCanonicalPlacements($tenantId);
$qboSubcustomersRetired = staffingClientRetireQboSubcustomers($tenantId);
$retired = staffingClientRetireUnsupportedJobDivaPromotions($tenantId);
$legacyRowsRetired = staffingClientRetireUnsupportedLegacyRows($tenantId);
$placeholdersRetired = staffingClientRetireJobDivaPlaceholders($tenantId);
$after = staffingClientCatalogIntegritySummary($tenantId);

$result = [
    'tenant_id' => $tenantId,
    'before' => $before,
    'assignment_client_links' => $clientLinks,
    'canonical_links_repaired' => $relinked,
    'qbo_subcustomers_retired' => $qboSubcustomersRetired,
    'unsupported_jobdiva_clients_retired' => $retired,
    'unsupported_legacy_clients_retired' => $legacyRowsRetired,
    'jobdiva_placeholders_retired' => $placeholdersRetired,
    'after' => $after,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

$failed = (int) ($clientLinks['failed'] ?? 0);
if ($failed > 0
    || (int) ($after['client_company_mismatches'] ?? 0) > 0
    || (int) ($after['active_jobdiva_placeholders'] ?? 0) > 0
    || (int) ($after['active_unproven_clients'] ?? 0) > 0
    || (int) ($after['active_placements_with_inactive_client'] ?? 0) > 0) {
    fwrite(STDERR, "Staffing client catalog integrity check failed.\n");
    exit(1);
}
