<?php
/**
 * Smoke: an exact JobDiva contract is not successful while canonical fields drift.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/jobdiva/projector.php';

$payload = [
    'id' => '57612620',
    'candidate id' => '6632210784858',
    'job id' => '29001707',
    'companyName' => 'TCS',
    '_jd_contract' => [
        'source' => 'EmployeeAssignmentRecordsDetail',
        'start_id' => '57612620',
        'placement_status' => 'active',
        'engagement_type' => 'w2',
        'start_date' => '2026-08-11T00:00:00',
        'end_date' => '2027-08-17T23:59:59',
        'bill_rate' => 74.26,
        'net_bill_rate' => 74.26,
        'bill_rate_in_vms' => 79.00,
        'pay_rate' => 60.00,
        'client_bill_cycle' => 'weekly',
        'vendor_pay_cycle' => 'biweekly',
        'paid_when_paid' => false,
    ],
];

$drifted = [
    'placement' => [
        'status' => 'draft',
        'engagement_type' => 'c2c',
        'start_date' => '2026-08-24',
        'end_date' => '2027-02-24',
        'client_bill_cycle' => 'monthly',
        'vendor_pay_cycle' => 'monthly',
        'vendor_pwp_enabled' => 0,
    ],
    'rates' => [[
        'id' => 1,
        'effective_to' => null,
        'approved_at' => null,
        'bill_rate' => 38.00,
        'pay_rate' => 38.00,
    ]],
];
$drift = jobdivaProjectorSourceContractDrift($payload, $drifted, '57612620');
$fields = array_column($drift, 'field');
foreach (['status', 'engagement_type', 'start_date', 'end_date', 'end_client_name', 'bill_rate', 'pay_rate', 'client_bill_cycle', 'vendor_pay_cycle'] as $field) {
    if (!in_array($field, $fields, true)) {
        fwrite(STDERR, "Missing expected drift field: {$field}\n");
        exit(1);
    }
}

$aligned = $drifted;
$aligned['placement'] = array_merge($aligned['placement'], [
    'status' => 'active',
    'engagement_type' => 'w2',
    'start_date' => '2026-08-11',
    'end_date' => '2027-08-17',
    'end_client_name' => 'TCS',
    'client_bill_cycle' => 'weekly',
    'vendor_pay_cycle' => 'biweekly',
]);
$aligned['rates'][0]['bill_rate'] = '79.0000';
$aligned['rates'][0]['pay_rate'] = '60.0000';
if (jobdivaProjectorSourceContractDrift($payload, $aligned, '57612620') !== []) {
    fwrite(STDERR, "Aligned graph still reported source-contract drift\n");
    exit(1);
}

echo "JobDiva projector postcondition smoke: ok\n";
