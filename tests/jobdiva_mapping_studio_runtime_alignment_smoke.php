<?php
/**
 * JobDiva Mapping Studio -> runtime alignment smoke.
 *
 * Locks in the contract behind the operator-facing Mapping Studio:
 * selected canonical source paths must resolve against the enriched
 * runtime payload, and selected target tables must write through the
 * correct graph owner instead of silently no-oping under linked_entity=self.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/core/integrations/field_map_apply.php';
require_once $root . '/core/jobdiva/canonical_graph.php';

$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  OK $label\n"; }
    else       { $fail++; $failures[] = $label; echo "  FAIL $label\n"; }
};

echo "JobDiva Mapping Studio/runtime alignment smoke\n";
echo "==============================================\n";

$payload = [
    '_jd_job' => [
        'title' => 'Service Desk Analyst',
        'jobID' => '27857851',
        'companyName' => 'TCS',
    ],
    '_jd_start' => [
        'payRate' => '52.50',
        'finalBillRate' => '120.00',
        'BILLRATEMAX' => '125.00',
        'agreedPayRate' => '64.00',
    ],
    '_jd_candidate' => [
        'firstName' => 'Andrew',
    ],
    '_jd_customer' => [
        'name' => 'SOIE',
    ],
    'assignment' => [
        0 => [
            'W2' => 1,
            'corp_to_corp' => 0,
            'TCS_W2' => 1,
        ],
    ],
];

echo "\n1. Canonical source paths resolve against _jd_* payloads\n";
$a('job.title resolves from _jd_job.title',
    integrationPayloadResolvePath($payload, 'job.title') === 'Service Desk Analyst');
$a('job.jobID resolves from _jd_job.jobID',
    integrationPayloadResolvePath($payload, 'job.jobID') === '27857851');
$a('assignment.payRate resolves from _jd_start.payRate',
    integrationPayloadResolvePath($payload, 'assignment.payRate') === '52.50');
$a('assignment.BILLRATEMAX resolves from _jd_start.BILLRATEMAX',
    integrationPayloadResolvePath($payload, 'assignment.BILLRATEMAX') === '125.00');
$a('assignment.final_bill_rate resolves from camelCase _jd_start.finalBillRate',
    integrationPayloadResolvePath($payload, 'assignment.final_bill_rate') === '120.00');
$a('assignment.agreed_pay_rate resolves from camelCase _jd_start.agreedPayRate',
    integrationPayloadResolvePath($payload, 'assignment.agreed_pay_rate') === '64.00');
$a('assignment[].W2 resolves numeric JobDiva row wrapper',
    integrationPayloadResolvePath($payload, 'assignment[].W2') === 1);
$a('assignment.0.W2 resolves legacy numeric JobDiva row wrapper',
    integrationPayloadResolvePath($payload, 'assignment.0.W2') === 1);
$a('assignment.W2 resolves first wrapped JobDiva row for operator-friendly paths',
    integrationPayloadResolvePath($payload, 'assignment.W2') === 1);
$a('person.firstName resolves from _jd_candidate.firstName',
    integrationPayloadResolvePath($payload, 'person.firstName') === 'Andrew');
$a('company.name resolves from _jd_customer.name',
    integrationPayloadResolvePath($payload, 'company.name') === 'SOIE');
$a('job.COMPANYNAME resolves from camelCase _jd_job.companyName',
    integrationPayloadResolvePath($payload, 'job.COMPANYNAME') === 'TCS');
$a('legacy pluck resolves job.COMPANYNAME through the same alias contract',
    tenantIntegrationFieldMapPluckPath($payload, 'job.COMPANYNAME') === 'TCS');
$a('legacy pluck resolves assignment.BILLRATEMAX through the same alias contract',
    tenantIntegrationFieldMapPluckPath($payload, 'assignment.BILLRATEMAX') === '125.00');
$a('legacy pluck resolves assignment[].W2 through numeric row wrapper',
    tenantIntegrationFieldMapPluckPath($payload, 'assignment[].W2') === '1');
$a('aliases list keeps original first and includes _jd_job fallback',
    integrationPayloadSourcePathAliases('job.title')[0] === 'job.title'
    && in_array('_jd_job.title', integrationPayloadSourcePathAliases('job.title'), true));
$a('truthy worker transforms turn JobDiva flags into CoreFlux enum values',
    tenantIntegrationFieldMapApplyTransform('1', 'truthy_to_w2') === 'w2'
    && tenantIntegrationFieldMapApplyTransform(1, 'truthy_to_c2c') === 'c2c'
    && tenantIntegrationFieldMapApplyTransform('C2C', 'truthy_to_w2') === 'c2c'
    && tenantIntegrationFieldMapApplyTransform('0', 'truthy_to_w2') === null
    && tenantIntegrationFieldMapApplyTransform('Public Storage', 'truthy_to_w2') === null);
$a('truthy worker transforms pass CoreFlux engagement coercion',
    integrationFieldMapCoerceTargetValue(
        tenantIntegrationFieldMapApplyTransform('1', 'truthy_to_c2c'),
        ['target_table' => 'placements', 'target_column' => 'engagement_type']
    ) === 'c2c');
$a('placement status coercion maps JobDiva status text into CoreFlux enum values',
    integrationFieldMapCoerceTargetValue('started', ['target_table' => 'placements', 'target_column' => 'status']) === 'active'
    && integrationFieldMapCoerceTargetValue('cancelled', ['target_table' => 'placements', 'target_column' => 'status']) === 'cancelled'
    && integrationFieldMapCoerceTargetValue('complete', ['target_table' => 'placements', 'target_column' => 'status']) === 'ended');

echo "\n2. Target table resolves to the actual graph owner\n";
$ctx = [
    'self' => 10,
    'placement' => 10,
    'person' => 20,
    'end_client_company' => 30,
    'staffing_job' => 40,
    'placement_rates' => 10,
    'placement_corp_details' => 10,
    'placement_commission_recruiter' => 60,
    'placement_commission_account_manager' => 61,
];
$a('stale self + staffing_jobs target writes staffing_job row',
    integrationFieldMapContextRowId($ctx, [
        'linked_entity' => 'self',
        'target_module' => 'staffing',
        'target_table' => 'staffing_jobs',
        'target_column' => 'title',
    ]) === 40);
$a('stale self + companies target writes end-client company row',
    integrationFieldMapContextRowId($ctx, [
        'linked_entity' => 'self',
        'target_module' => 'companies',
        'target_table' => 'companies',
        'target_column' => 'industry',
    ]) === 30);
$a('people target writes linked person row',
    integrationFieldMapContextRowId($ctx, [
        'linked_entity' => 'self',
        'target_module' => 'people',
        'target_table' => 'people',
        'target_column' => 'first_name',
    ]) === 20);
$a('placement_rates target writes sibling placement_rates row',
    integrationFieldMapContextRowId($ctx, [
        'linked_entity' => 'self',
        'target_module' => 'placements',
        'target_table' => 'placement_rates',
        'target_column' => 'bill_rate',
    ]) === 10);
$fieldMapApplySrc = file_get_contents($root . '/core/integrations/field_map_apply.php');
$a('placement_rates mappings can create a source-backed draft when no sibling row exists',
    str_contains($fieldMapApplySrc, 'function integrationFieldMapInsertPlacementRateRow')
    && str_contains($fieldMapApplySrc, "placement_rates@placement#")
    && str_contains($fieldMapApplySrc, 'INSERT INTO placement_rates')
    && str_contains($fieldMapApplySrc, 'placement_rates_missing_required'));
$a('placement_rates draft creation requires real bill and pay source values',
    str_contains($fieldMapApplySrc, 'bill_rate and pay_rate must both resolve to positive source values')
    && !str_contains($fieldMapApplySrc, '$payRate = $billRate'));
$a('placement_corp_details target writes placement-keyed corp sibling row',
    integrationFieldMapContextRowId($ctx, [
        'linked_entity' => 'self',
        'target_module' => 'placements',
        'target_table' => 'placement_corp_details',
        'target_column' => 'corp_legal_name',
    ]) === 10);
$a('placement_commissions recruiter target writes recruiter commission row',
    integrationFieldMapContextRowId($ctx, [
        'linked_entity' => 'placement_commission_recruiter',
        'target_module' => 'placements',
        'target_table' => 'placement_commissions',
        'target_column' => 'split_pct',
    ]) === 60);
$a('placement_commissions account manager target writes account-manager commission row',
    integrationFieldMapContextRowId($ctx, [
        'linked_entity' => 'placement_commission_account_manager',
        'target_module' => 'placements',
        'target_table' => 'placement_commissions',
        'target_column' => 'split_pct',
    ]) === 61);
$a('commission linked_entity defaults resolve to the same role keys used by runtime creation',
    integrationFieldMapCommissionContextKey('placement_commission_recruiter') === 'placement_commission_recruiter'
    && integrationFieldMapCommissionContextKey('placement_commission_account_manager') === 'placement_commission_account_manager'
    && integrationFieldMapCommissionContextKey('self') === 'placement_commission_recruiter');
$a('chain linked_entity defaults resolve to canonical row keys used by runtime creation',
    integrationFieldMapChainContextKey('placement_chain_msp') === 'placement_chain_msp'
    && integrationFieldMapChainContextKey('placement_chain_prime_vendor') === 'placement_chain_prime_vendor'
    && integrationFieldMapChainContextKey('placement_chain_sub_vendor') === 'placement_chain_sub_vendor');
$a('explicit vendor company link remains respected',
    integrationFieldMapContextRowId($ctx + ['vendor_company' => 50], [
        'linked_entity' => 'vendor_company',
        'target_module' => 'companies',
        'target_table' => 'companies',
        'target_column' => 'name',
    ]) === 50);
$a('placement context without company id skips companies target instead of using placement id',
    integrationFieldMapContextRowId(['self' => 10, 'placement' => 10], [
        'linked_entity' => 'self',
        'target_module' => 'companies',
        'target_table' => 'companies',
        'target_column' => 'name',
    ]) === 0);
$a('standalone company context can still use self for companies target',
    integrationFieldMapContextRowId(['self' => 30], [
        'linked_entity' => 'self',
        'target_module' => 'companies',
        'target_table' => 'companies',
        'target_column' => 'name',
    ]) === 30);

echo "\n3. Save-time defaults and legacy aliases are canonical\n";
$a('placement -> staffing_jobs default is staffing_job',
    tenantIntegrationFieldMapDefaultLinkedEntityForTarget('placement', 'staffing', 'staffing_jobs') === 'staffing_job');
$a('placement -> people default is person',
    tenantIntegrationFieldMapDefaultLinkedEntityForTarget('placement', 'people', 'people') === 'person');
$a('placement -> companies default is end_client_company',
    tenantIntegrationFieldMapDefaultLinkedEntityForTarget('placement', 'companies', 'companies') === 'end_client_company');
$a('placement -> placement_commissions default is recruiter commission row',
    tenantIntegrationFieldMapDefaultLinkedEntityForTarget('placement', 'placements', 'placement_commissions') === 'placement_commission_recruiter');
$a('placement -> placement_corp_details default is placement corp details row',
    tenantIntegrationFieldMapDefaultLinkedEntityForTarget('placement', 'placements', 'placement_corp_details') === 'placement_corp_details');
$a('company -> companies remains self',
    tenantIntegrationFieldMapDefaultLinkedEntityForTarget('company', 'companies', 'companies') === 'self');
$a('JobDiva native entity type canonicalizes before save',
    tenantIntegrationFieldMapCanonicalEntityType('jobdiva', 'jobdiva_job') === 'staffing_job');
$a('JobDiva apply list includes legacy jobdiva_job mappings',
    in_array('jobdiva_job', jobdivaCanonicalApplyEntityTypes('job'), true));

echo "\n4. Studio UI has matching grouping/defaulting hooks\n";
$fms = (string) file_get_contents($root . '/dashboard/src/pages/FieldMappingStudio.jsx');
$a('Studio groups job.* with _jd_job',
    str_contains($fms, "_jd_job: ['job', 'staffing_job', 'jobdiva_job']"));
$a('Studio label says Placement job context',
    str_contains($fms, "label: 'Placement job context'"));
$a('Studio infers linked entity from selected target table',
    str_contains($fms, 'function inferLinkedEntityForTarget')
    && str_contains($fms, "if (table === 'staffing_jobs') return et === 'staffing_job' ? 'self' : 'staffing_job';")
    && str_contains($fms, "if (table === 'companies') return et === 'company' ? 'self' : 'end_client_company';")
    && str_contains($fms, "if (table === 'placement_commissions') return target?.default_linked_entity || 'placement_commission_recruiter';")
    && str_contains($fms, "if (table === 'placement_corp_details') return 'placement_corp_details';"));

echo "\n==============================================\n";
echo "JobDiva mapping alignment smoke: $pass OK / $fail FAIL\n";
echo "==============================================\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! $msg\n";
    exit(1);
}
exit(0);
