<?php
/**
 * JobDiva EmployeeAssignmentRecordsDetail -> CoreFlux contract smoke.
 *
 * Fixtures mirror the labels observed in the live JobDiva Assignment screen
 * and Assignment Dashboard. Values and names are synthetic.
 */
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

echo "JobDiva assignment contract smoke\n";
echo "=================================\n";

$w2Rows = [[
    'Start ID' => '56830791',
    'Employment Category' => 'Hourly Employee',
    'Bill Rate' => '$62.04 / Hour',
    'Pay Rate' => '$50.00 / Hour',
    'Bill Start' => '08/03/2026',
    'Bill End' => '01/03/2027',
    'Pay Start' => '08/03/2026',
    'Pay End' => '01/03/2027',
    'Payment Frequency' => 'Bi-Weekly',
    'Payment Terms' => '0 days',
    'W2' => 1,
    'C2C' => 0,
    'Overheads' => [
        'Workers Comp %' => '2.50%',
        'Benefits Load %' => '4%',
        'Payroll Load %' => '11.75%',
        'Per Diem' => '$35.00',
        'Other Expenses' => '$12.50',
        'Outside Commission' => '$1.25',
        'Fixed Costs' => '$305.15',
        'Payroll Profile ID' => 'PP-44',
        'Pass Through' => true,
        'Pass Discount' => false,
    ],
]];

$w2 = jobdivaAssignmentContractBuild($w2Rows, [], '56830791');
$assert('Hourly Employee is authoritative W-2', ($w2['engagement_type'] ?? '') === 'w2');
$assert('W-2 bill and pay stay distinct',
    abs((float) ($w2['bill_rate'] ?? 0) - 62.04) < 0.0001
    && abs((float) ($w2['pay_rate'] ?? 0) - 50.00) < 0.0001);
$assert('Bi-Weekly becomes the canonical biweekly pay cadence',
    ($w2['vendor_pay_cycle'] ?? '') === 'biweekly');
$assert('overhead percentages normalize as decimals',
    abs((float) ($w2['payroll_load_pct'] ?? 0) - 0.1175) < 0.000001
    && abs((float) ($w2['workers_comp_pct'] ?? 0) - 0.025) < 0.000001
    && abs((float) ($w2['benefits_load_pct'] ?? 0) - 0.04) < 0.000001);
$assert('named overhead components are retained without flattening their meaning',
    abs((float) ($w2['overheads']['per_diem'] ?? 0) - 35.0) < 0.0001
    && abs((float) ($w2['overheads']['outside_commission'] ?? 0) - 1.25) < 0.0001
    && ($w2['overheads']['payroll_profile_id'] ?? '') === 'PP-44'
    && count($w2['overheads']['source_fields'] ?? []) >= 8);
$assert('compatible fixed overhead projects to the fixed-cost slot',
    abs((float) ($w2['other_cost_flat'] ?? 0) - 305.15) < 0.0001);

$c2cRows = [
    // The endpoint can return related records. This row must be ignored when
    // the requested Start ID is 56848682.
    [
        'Start ID' => '99999999',
        'Employment Category' => 'Hourly Employee',
        'Bill Rate' => 10,
        'Pay Rate' => 9,
    ],
    [
        'Start ID' => '56848682',
        'Employment Category' => 'Subcontract',
        'Corporation' => 'Invent Example LLC',
        'Bill Rate' => '$71.40 / Hour',
        'Pay Rate' => '$65.00 / Hour',
        'Pay Rate to Vendor' => '$68.00 / Hour',
        'Bill Rate in Beeline' => '$75.00',
        'Net Bill Rate' => '$71.40',
        'Spread' => '$3.40',
        'Payment Frequency' => 'Bi-Weekly',
        'Payment Due' => 'Upon Approval',
        'Subcontractor Payment Terms' => 'Pay When Paid',
        'Payment Discount %' => '2%',
        'W2' => 0,
        'C2C' => 1,
        'Referral Vendor' => 'Referral Example LLC',
        'Referral Fee Amount' => '$500.00',
        'Referral Vendor Payment terms' => 'Net 30',
        'Primary Sales' => 'Sales Owner',
        'Primary Sales %' => '40%',
        'Primary Recruiter' => 'Recruiter Owner',
        'Primary Recruiter %' => '60%',
        'Overheads' => [
            'C2C Overheads' => 1,
            'Fixed Costs' => '$125.00',
            'Other Cost Per Hour' => '$1.50',
        ],
    ],
];

$c2c = jobdivaAssignmentContractBuild($c2cRows, [], '56848682');
$assert('Subcontract is authoritative C2C', ($c2c['engagement_type'] ?? '') === 'c2c');
$assert('exact Start filtering blocks cross-assignment contamination',
    ($c2c['start_id'] ?? '') === '56848682'
    && abs((float) ($c2c['bill_rate'] ?? 0) - 71.40) < 0.0001);
$mismatchOnly = jobdivaAssignmentContractBuild([
    ['Start ID' => '99999999', 'Bill Rate' => 999, 'Pay Rate to Vendor' => 998],
], ['Start ID' => '56848682', 'Employment Category' => 'Subcontract'], '56848682');
$assert('an explicitly mismatched response cannot supply financial values',
    !array_key_exists('bill_rate', $mismatchOnly)
    && !array_key_exists('pay_rate', $mismatchOnly)
    && ($mismatchOnly['start_id'] ?? '') === '56848682');
$assert('C2C labor cost is the explicit Pay Rate to Vendor',
    abs((float) ($c2c['pay_rate'] ?? 0) - 68.00) < 0.0001
    && abs((float) ($c2c['pay_rate_to_vendor'] ?? 0) - 68.00) < 0.0001);
$assert('C2C corporation becomes the payee company source',
    ($c2c['corporation_name'] ?? '') === 'Invent Example LLC');
$assert('PWP and cadence normalize for AP',
    ($c2c['vendor_payment_terms'] ?? '') === 'PWP'
    && ($c2c['paid_when_paid'] ?? false) === true
    && ($c2c['vendor_pay_cycle'] ?? '') === 'biweekly');
$assert('payment discount and VMS economics remain explicit source facts',
    abs((float) ($c2c['payment_discount_pct'] ?? 0) - 0.02) < 0.000001
    && abs((float) ($c2c['bill_rate_in_vms'] ?? 0) - 75.00) < 0.0001
    && abs((float) ($c2c['net_bill_rate'] ?? 0) - 71.40) < 0.0001);
$assert('referral vendor economics normalize into the contract',
    ($c2c['referral_vendor'] ?? '') === 'Referral Example LLC'
    && abs((float) ($c2c['referral_fee_amount'] ?? 0) - 500.0) < 0.0001
    && ($c2c['referral_payment_terms'] ?? '') === 'NET30');
$assert('sales and recruiter allocation facts are retained',
    ($c2c['account_manager'] ?? '') === 'Sales Owner'
    && abs((float) ($c2c['account_manager_commission_pct'] ?? 0) - 0.40) < 0.000001
    && ($c2c['recruiter_name'] ?? '') === 'Recruiter Owner'
    && abs((float) ($c2c['recruiter_commission_pct'] ?? 0) - 0.60) < 0.000001);

$payload = ['_jd_contract' => $c2c, '_jd_start' => ['crop to crop' => null]];
$assert('runtime engagement inference prefers the financial contract',
    jobdivaInferPlacementEngagementTypeFromPayload($payload, 'w2') === 'c2c');
$assert('runtime deep pluck resolves canonical contract rates',
    jobdivaPluckFieldDeep($payload, ['bill_rate']) === '71.4'
    && jobdivaPluckFieldDeep($payload, ['pay_rate']) === '68');
$subPayloads = jobdivaExtractJoinedSubPayloads($payload);
$assert('canonical assignment facet contains the normalized contract',
    ($subPayloads['assignment']['engagement_type'] ?? '') === 'c2c'
    && abs((float) ($subPayloads['assignment']['pay_rate'] ?? 0) - 68.0) < 0.0001);

$assert('hybrid PWP/net language retains both trigger and outside date',
    jobdivaAssignmentContractTerms('Net 90 or P-W-P whichever is earlier') === 'PWP_NET90');
$assert('Upon Approval remains due-on-receipt when no stronger PWP term exists',
    jobdivaAssignmentContractTerms('', 'Upon Approval') === 'DUE_ON_RECEIPT');

$syncSource = (string) file_get_contents($root . '/core/jobdiva/sync.php');
$economicsSource = (string) file_get_contents($root . '/modules/placements/lib/economics.php');
$placementUi = (string) file_get_contents($root . '/modules/placements/ui/PlacementDetail.jsx');
$assert('sync fetches the authoritative financial record by Start ID',
    str_contains($syncSource, '/apiv2/bi/EmployeeAssignmentRecordsDetail')
    && str_contains($syncSource, "'_jd_contract'"));
$assert('draft rate source snapshot retains the assignment contract and overheads',
    str_contains($syncSource, "'assignment_contract' => \$sourceContract")
    && str_contains($syncSource, "'source_overheads' => \$sourceContract['overheads']"));
$assert('repair replay refreshes the authoritative assignment contract',
    str_contains($syncSource, "|| empty(\$jd['_jd_contract'])"));
$assert('economics API exposes source evidence before rate approval',
    str_contains($economicsSource, 'placementEconomicsLatestSourceEvidence')
    && str_contains($economicsSource, "'source_overheads' => \$sourceEvidence['source_overheads']")
    && str_contains($economicsSource, "\$payload['_jd_contract']"));
$assert('placement economics has a dedicated JobDiva overheads section',
    str_contains($placementUi, 'data-testid="jobdiva-overheads"')
    && str_contains($placementUi, 'Raw JobDiva overhead fields'));

echo "\nJobDiva assignment contract smoke: {$pass} ok / {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
