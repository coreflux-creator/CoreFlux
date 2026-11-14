<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$assert = static function (string $message, bool $condition) use (&$pass, &$fail): void {
    echo '  ' . ($condition ? "\u{2713}" : "\u{2717}") . " {$message}\n";
    $condition ? $pass++ : $fail++;
};
$read = static fn(string $path): string => (string) file_get_contents($path);

require_once $root . '/core/jobdiva/projector.php';

echo "Staffing client catalog integrity\n";

$weak = jobdivaProjectorAssignmentClientEvidence([
    'companyName' => 'Candidate or requisition label',
    'customerName' => 'Contact name',
]);
$assert('weak JobDiva company/customer labels are not client evidence', empty($weak['authoritative']));

$exact = jobdivaProjectorAssignmentClientEvidence([
    '_jd_contract' => [
        'source' => 'EmployeeAssignmentRecordsDetail',
        'client_company_name' => 'TCS',
        'client_company_id' => '1065',
    ],
]);
$assert('exact assignment billing company is authoritative',
    !empty($exact['authoritative'])
    && $exact['name'] === 'TCS'
    && $exact['external_id'] === '1065');

$sync = $read($root . '/core/jobdiva/sync.php');
$jobs = $read($root . '/modules/staffing/lib/jobs.php');
$clients = $read($root . '/modules/staffing/lib/clients.php');
$api = $read($root . '/modules/staffing/api/clients.php');
$repair = $read($root . '/scripts/repair_staffing_client_catalog.php');
$proposal = $read($root . '/core/jobdiva/contract_projection.php');
$qbo = $read($root . '/core/qbo/sync_in.php');

$assert('generic JobDiva companies remain generic organizations',
    str_contains($sync, 'jobdivaUpsertCompanyMapped($tid, $extId, $name, $patch, $jd, $userId, [])'));
$assert('JobDiva jobs cannot create staffing clients',
    str_contains($jobs, '$clientScope[\'owner\'],$companyId')
    && !str_contains($jobs, 'staffingClientEnsureForCompany('));
$assert('reconciliation preview does not fall back to requisition/customer labels',
    !str_contains($proposal, 'jobdivaEndClientNameFromPayload($payload)')
    && !str_contains($proposal, "jobdivaContractProjectionScalar(\$payload, ['companyName'"));
$assert('client placement counts follow the explicit bill-to profile',
    str_contains($api, 'GROUP BY client_id')
    && str_contains($api, 'p.client_id = c.id'));
$source = $read($root . '/modules/staffing/lib/client_source.php');
$review = $read($root . '/modules/staffing/lib/client_catalog_review.php');
$links = $read($root . '/core/jobdiva/client_link_repair.php');
$assert('QuickBooks subcustomer status obeys source ownership and active placement holds',
    str_contains($qbo, "$"."payload['status']='inactive'")
    && str_contains($source, "in_array($"."field,$"."state['owned'],true)")
    && str_contains($source, "in_array($"."apply['status'],['closed','inactive'],true)"));
$assert('catalog command defaults to read-only and requires an exact write flag',
    str_contains($repair, "'--apply-source-links'") && str_contains($repair, "'read_only'")
    && str_contains($repair, '$repair = $apply ? jobdivaMappingRepairStaffingClientLinks'));
$assert('report resolves both independent directories without lifecycle mutation',
    str_contains($review, 'companyRelationshipScope(') && str_contains($review, "'company_owner_id'")
    && !str_contains($review, 'UPDATE staffing_clients') && !str_contains($review, 'DELETE FROM'));
$assert('repair never canonicalizes retained client numbers or removes company roles',
    !str_contains($clients, 'function staffingClientRelinkCanonicalPlacements')
    && !str_contains($clients, 'DELETE FROM company_roles')
    && !str_contains($clients, 'function staffingClientReactivateProvenActivePlacementClients'));
$assert('exact repair locks scope and ownership before resolving its company',
    strpos($links, 'placementOwnershipFields(') < strpos($links, 'jobdivaProjectorResolveBillingClientCompany(')
    && str_contains($links, 'companyRelationshipScope(') && str_contains($links, 'FOR UPDATE'));
$assert('repair refuses name-only identity and does not force active client status',
    str_contains($links, 'exact_billing_identity_required') && !str_contains($links, 'staffingClientFindForCompany(')
    && !str_contains($links, "'status'=>'active'"));
$assert('catalog concerns are review evidence rather than deletion criteria',
    str_contains($review, 'companies_with_multiple_clients') && str_contains($review, 'active_placeholder_names')
    && str_contains($review, 'active_placements_needing_client_review'));
$assert('explicit CSV client imports retain audit provenance',
    str_contains($read($root . '/modules/staffing/api/csv_import.php'), 'staffingClientImportNative(')
    && str_contains($read($root . '/modules/staffing/lib/client_write.php'), "'staffing.client.imported'"));
$assert('client-link repair requires an atomic audit receipt',
    str_contains($links, 'companyRelationshipAtomic(') && str_contains($links, 'companySourceAudit(')
    && str_contains($links, 'placement.client_link_repaired'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
