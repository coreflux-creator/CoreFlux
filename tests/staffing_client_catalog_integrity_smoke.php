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
    str_contains($jobs, 'staffingClientFindForCompany($tenantId, $companyId)')
    && !str_contains($jobs, 'staffingClientEnsureForCompany('));
$assert('reconciliation preview does not fall back to requisition/customer labels',
    !str_contains($proposal, 'jobdivaEndClientNameFromPayload($payload)')
    && !str_contains($proposal, "jobdivaContractProjectionScalar(\$payload, ['companyName'"));
$assert('client placement counts follow canonical company identity',
    str_contains($api, 'GROUP BY end_client_company_id')
    && str_contains($api, 'p.end_client_company_id = c.company_id'));
$assert('QuickBooks sub-customers are not promoted as active top-level clients',
    str_contains($qbo, "\$isSubcustomer = !empty(\$qbo['Job'])")
    && str_contains($clients, 'function staffingClientRetireQboSubcustomers'));
$assert('catalog repair relinks before retiring unsupported promotions',
    strpos($repair, 'staffingClientRelinkCanonicalPlacements')
        < strpos($repair, 'staffingClientRetireUnsupportedJobDivaPromotions'));
$assert('cleanup preserves source graphs and only inactivates consumer rows',
    str_contains($clients, "SET sc.status = 'inactive'")
    && !str_contains($clients, 'DELETE FROM companies')
    && !str_contains($clients, 'DELETE FROM placements'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
