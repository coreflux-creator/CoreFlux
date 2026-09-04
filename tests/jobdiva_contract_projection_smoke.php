<?php
/** Canonical JobDiva assignment contract proposal smoke. */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/jobdiva/assignment_contract.php';
require_once $root . '/core/jobdiva/sync.php';

$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $condition) use (&$pass, &$fail): void {
    if ($condition) {
        $pass++;
        echo "  OK {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};

echo "JobDiva canonical contract projection smoke\n";
echo "==========================================\n";

$rows = [[
    'Start ID' => '57137454',
    'BILLING' => [[
        'START_DATE' => '2026-07-06T00:00:00',
        'END_DATE' => '2026-12-01T23:59:59',
        'APPROVED' => 1,
        'CLOSED' => 0,
        'ACTUALSTART' => 1,
        'FREQUENCY_LABEL' => 'Weekly',
        'BILL_RATE' => 62.98,
        'BILL_RATE_PER' => 'H',
        'WORKING_STATE' => 'AZ',
        'WORKING_COUNTRY' => 'US',
        'NET_BILL' => '$62.98/H',
        'Bill_Rate_in_Beeline' => 67,
        'PRIMARY_SALESPERSON' => '1601464',
        'PRIMARY_RECRUITER' => '1601464',
        'PRISALE_COMM_PERCENT' => 100,
        'PRIREC_COMM_PERCENT' => 100,
    ]],
    'SALARY' => [[
        'SALARY' => 60,
        'SUBCONTRACT_COMPANYID' => '12319524',
        'PAYMENT_FREQUENCY' => 'Weekly',
        'EMPLOYMENT_CATEGORY' => 'Subcontract',
        'Subcontractor_Payment_terms' => 'PWP',
        'Pay_Rate_to_Vendor' => 60,
    ]],
    'OVERHEADS' => [[
        'Payroll Load %' => '2.5%',
        'Workers Comp %' => '1%',
        'Fixed Costs' => 120,
    ]],
]];
$contract = jobdivaAssignmentContractBuild($rows, [], '57137454');
$payload = [
    'startId' => '57137454',
    'candidateId' => '1001',
    'candidateName' => 'Example Consultant',
    'jobId' => '2002',
    'companyId' => '3003',
    'companyName' => 'Example Client',
    '_jd_contract' => $contract,
];
$currentGraph = [
    'placement' => [
        'engagement_type' => 'w2',
        'status' => 'active',
        'start_date' => '2026-07-06',
        'end_date' => '2026-12-01',
        'client_bill_cycle' => 'monthly',
        'vendor_pay_cycle' => 'biweekly',
    ],
    'rates' => [[
        'id' => 10,
        'effective_from' => '2026-07-06',
        'effective_to' => null,
        'approved_at' => null,
        'bill_rate' => 62.98,
        'pay_rate' => 60,
    ]],
    'economic_parties' => [],
];
$proposal = jobdivaContractProjectionBuild($payload, $currentGraph, '57137454');

$assert('one exact assignment produces a complete contract', !empty($proposal['complete']));
$assert('gross, VMS adjustment, invoice, labor, and margin reconcile',
    abs((float) $proposal['economics']['gross_client_rate'] - 67.0) < 0.0001
    && abs((float) $proposal['economics']['client_adjustment_amount'] - 4.02) < 0.0001
    && abs((float) $proposal['economics']['invoice_rate'] - 62.98) < 0.0001
    && abs((float) $proposal['economics']['labor_rate'] - 60.0) < 0.0001
    && abs((float) $proposal['economics']['gross_margin'] - 0.88) < 0.0001);
$assert('C2C labor becomes one AP vendor recipient with weekly PWP terms',
    count(array_filter($proposal['participants'], static fn(array $party): bool =>
        $party['role'] === 'c2c_vendor'
        && $party['settlement_channel'] === 'ap'
        && $party['calculation'] === 'pay_rate'
        && $party['cadence'] === 'weekly'
        && $party['payment_terms'] === 'PWP'
        && $party['paid_when_paid'] === true
    )) === 1);
$assert('sales and recruiter allocation stay attribution-only',
    count($proposal['attributions']) === 2
    && count(array_filter($proposal['attributions'], static fn(array $owner): bool => $owner['creates_payment'])) === 0);
$assert('overhead source fields become modeled rate fields',
    abs((float) ($contract['payroll_load_pct'] ?? 0) - 0.025) < 0.000001
    && abs((float) ($contract['workers_comp_pct'] ?? 0) - 0.01) < 0.000001
    && abs((float) ($contract['other_cost_flat'] ?? 0) - 120.0) < 0.0001);
$assert('preview exposes current-to-proposed field changes with JobDiva authority',
    count(array_filter($proposal['changes'], static fn(array $field): bool =>
        $field['field'] === 'engagement_type'
        && $field['current'] === 'w2'
        && $field['proposed'] === 'c2c'
        && $field['authority'] === 'exact_assignment'
    )) === 1);

$missingContract = jobdivaContractProjectionBuild([
    'startId' => '57137454',
    'candidateId' => '1001',
    'jobId' => '2002',
    'companyId' => '3003',
], [], '57137454');
$assert('a shallow Start cannot claim contract completeness',
    empty($missingContract['complete'])
    && count(array_filter($missingContract['blocking_issues'], static fn(array $check): bool =>
        $check['code'] === 'exact_assignment_contract'
    )) === 1);

$conflictingPayees = $currentGraph;
$conflictingPayees['economic_parties'] = [
    ['active' => 1, 'money_flow' => 'payable', 'fee_basis' => 'pay_rate'],
    ['active' => 1, 'money_flow' => 'payable', 'fee_basis' => 'pay_rate'],
];
$conflictProposal = jobdivaContractProjectionBuild($payload, $conflictingPayees, '57137454');
$assert('multiple current labor recipients block automatic apply',
    empty($conflictProposal['complete'])
    && count(array_filter($conflictProposal['blocking_issues'], static fn(array $check): bool =>
        $check['code'] === 'single_labor_payee'
    )) === 1);

$staleSourcePayees = $currentGraph;
$staleSourcePayees['economic_parties'] = [
    ['active' => 1, 'source_type' => 'worker', 'source_managed' => 1, 'money_flow' => 'payable', 'fee_basis' => 'pay_rate'],
    ['active' => 1, 'source_type' => 'corp', 'source_managed' => 1, 'money_flow' => 'payable', 'fee_basis' => 'pay_rate'],
];
$staleSourceProposal = jobdivaContractProjectionBuild($payload, $staleSourcePayees, '57137454');
$assert('stale source-owned labor recipients are repairable rather than blocking',
    !empty($staleSourceProposal['complete'])
    && count(array_filter($staleSourceProposal['warnings'], static fn(array $check): bool =>
        $check['code'] === 'single_labor_payee'
    )) === 1);

$syncSource = (string) file_get_contents($root . '/core/jobdiva/sync.php');
$economicsSource = (string) file_get_contents($root . '/modules/placements/lib/economics.php');
$uiSource = (string) file_get_contents($root . '/modules/placements/ui/PlacementDetail.jsx');
$reconciliationUi = (string) file_get_contents($root . '/modules/placements/ui/JobDivaReconciliation.jsx');
$assert('stored reconciliation rejoins the exact financial placement mirror',
    str_contains($syncSource, '$financialPayloads')
    && str_contains($syncSource, 'jobdivaContractProjectionBuild'));
$assert('exact contract overheads outrank broad tenant mappings',
    str_contains($syncSource, "array_key_exists('payroll_load_pct', \$sourceContract)")
    && str_contains($syncSource, "array_key_exists('workers_comp_pct', \$sourceContract)")
    && str_contains($syncSource, "array_key_exists('other_cost_flat', \$sourceContract)"));
$assert('placement API exposes a current draft model without changing downstream approval rules',
    str_contains($economicsSource, 'placementEconomicsCurrentContractModel')
    && str_contains($economicsSource, "'contract_model' => \$contractModel"));
$assert('placement UI separates settlement participants from attribution',
    str_contains($uiSource, 'Current draft economics')
    && str_contains($uiSource, 'JobDiva assignment attribution')
    && str_contains($uiSource, 'Update draft contract'));
$assert('reconciliation UI shows contract fields, participants, and checks',
    str_contains($reconciliationUi, 'Canonical contract proposal')
    && str_contains($reconciliationUi, 'Contract checks')
    && str_contains($reconciliationUi, 'Settlement participants'));

echo "\nJobDiva canonical contract projection smoke: {$pass} ok / {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
