<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $read('core/migrations/128_placement_commercial_contract.sql');
$economics = $read('modules/placements/lib/economics.php');
$approval = $read('modules/placements/lib/rate_approve.php');
$ratesApi = $read('modules/placements/api/rates.php');
$csvImport = $read('modules/placements/api/csv_import.php');
$csvExport = $read('modules/placements/api/csv_export.php');
$fieldMap = $read('core/integrations/field_map.php');
$fieldApply = $read('core/integrations/field_map_apply.php');
$jobdiva = $read('core/jobdiva/sync.php');
$billing = $read('modules/billing/lib/billing.php');
$ap = $read('modules/ap/lib/ap.php');
$settlement = $read('modules/time/lib/settlement_create.php');
$ui = $read('modules/placements/ui/PlacementDetail.jsx');

$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL {$label}\n";
};

$rateFields = [
    'bill_adder_pct',
    'bill_adder_flat',
    'bill_discount_pct',
    'bill_discount_flat',
    'workers_comp_pct',
    'benefits_load_pct',
    'other_cost_per_hour',
    'other_cost_flat',
];

echo "Placement commercial contract\n";
foreach ($rateFields as $field) {
    $assert("migration adds {$field}", str_contains($migration, "COLUMN {$field}"));
    $assert("field mapping exposes {$field}", str_contains($fieldMap, "'{$field}'"));
    $assert("mapping apply writes {$field}", str_contains($fieldApply, "'{$field}'"));
    $assert("JobDiva projection writes {$field}", str_contains($jobdiva, $field));
    $assert("CSV import accepts {$field}", str_contains($csvImport, "'{$field}'"));
    $assert("CSV export includes {$field}", str_contains($csvExport, "'{$field}'"));
    $assert("rates API accepts {$field}", str_contains($ratesApi, "'{$field}'"));
}

$assert('approved rate stores an immutable economics snapshot',
    str_contains($migration, 'economics_snapshot_json')
    && str_contains($approval, 'economics_snapshot_json = :snapshot')
    && str_contains($approval, "json_encode(\$margin['economics_snapshot']"));
$assert('snapshot includes bill-to terms and all commercial participants',
    str_contains($economics, "\$model['contract_parties'] = \$contractParties")
    && str_contains($economics, "\$model['receivable_contract']")
    && str_contains($economics, "'resolved_payment_terms'")
    && str_contains($economics, "'resolved_pwp'"));
$assert('snapshot preserves payment frequency and effective dates',
    str_contains($economics, "'cycle_cadence'")
    && str_contains($economics, "'operating_cycle_id'")
    && str_contains($economics, "'effective_from'")
    && str_contains($economics, "'effective_to'"));
$assert('cycle resolution reuses existing standard cadence cycles',
    str_contains($economics, 'function placementEconomicsEnsureStandardCycle')
    && str_contains($economics, 'name = :name OR cadence = :cadence'));
$assert('source cadence replaces stale operating-cycle pointers',
    str_contains($economics, '$cycleMatches = static function')
    && str_contains($economics, "purpose = :purpose")
    && str_contains($economics, "cadence = :cadence")
    && str_contains($economics, "!\$cycleMatches(\$placement['billing_operating_cycle_id']")
    && str_contains($economics, '!$cycleMatches($placement[$payField]'));
$assert('non-W2 labor payee repair is conservative and unambiguous',
    str_contains($economics, 'function placementEconomicsRepairPrimaryLaborPayee')
    && str_contains($economics, 'if (count($ids) !== 1) return;')
    && str_contains($economics, 'fee_basis = "pay_rate"'));

$assert('billing resolves bill-to and terms from each approved rate snapshot',
    substr_count($billing, 'placementEconomicsReceivableContract(') >= 2
    && str_contains($billing, "\$contractBundle['rate_snapshot_id']")
    && str_contains($billing, "\$contractRow['_rate_snapshot_id']"));
$assert('direct time billing refuses mixed bill-to contract terms',
    str_contains($settlement, 'spans approved contract snapshots with different bill-to terms'));
$assert('AP labor recipient is resolved from the locked rate snapshot',
    str_contains($ap, 'placementEconomicsPrimaryPayable(')
    && str_contains($ap, "(int) \$rate['id']"));
$assert('AP fees use the common immutable participant contract',
    str_contains($ap, 'placementEconomicsCommonContractSnapshotId(')
    && str_contains($ap, '$contractSnapshotId'));
$assert('payroll extras are calculated separately for each locked rate contract',
    str_contains($settlement, '$entriesByRate')
    && str_contains($settlement, 'placementEconomicsPayrollCharges(')
    && str_contains($settlement, '$rateSnapshotId'));

$assert('placement has one Contract workflow containing rates and participants',
    str_contains($ui, "{ slug: 'economics',   label: 'Contract' }")
    && !str_contains($ui, "{ slug: 'rates'")
    && str_contains($ui, '<RatesTab')
    && str_contains($ui, 'Commercial terms by participant'));
$assert('contract editor exposes revenue adjustments and labor cost loads',
    str_contains($ui, 'Adders, discounts, and employer costs')
    && str_contains($ui, 'Client discount %')
    && str_contains($ui, 'Workers compensation %')
    && str_contains($ui, 'Benefits load %')
    && str_contains($ui, 'Other recurring cost / hour'));

require_once $root . '/modules/placements/lib/economics.php';
$model = placementEconomicsModelForRate(1, 1, [
    'id' => 99,
    'bill_rate' => 100,
    'pay_rate' => 60,
    'bill_discount_pct' => 0.05,
    'adder_pct' => 0.10,
    'workers_comp_pct' => 0.02,
    'benefits_load_pct' => 0.03,
    'other_cost_per_hour' => 1,
    'other_cost_flat' => 25,
    'currency' => 'USD',
], [
    [
        'id' => 1, 'display_name' => 'Client', 'role' => 'end_client',
        'money_flow' => 'receivable', 'settlement_channel' => 'ar', 'fee_basis' => 'none',
    ],
    [
        'id' => 2, 'display_name' => 'Contractor LLC', 'role' => 'c2c_vendor',
        'money_flow' => 'payable', 'settlement_channel' => 'ap', 'fee_basis' => 'pay_rate',
    ],
    [
        'id' => 3, 'display_name' => 'MSP', 'role' => 'msp',
        'money_flow' => 'payable', 'settlement_channel' => 'ap',
        'fee_basis' => 'pct_bill', 'fee_pct' => 0.02,
    ],
    [
        'id' => 4, 'display_name' => 'Referrer', 'role' => 'referrer',
        'money_flow' => 'payable', 'settlement_channel' => 'ap',
        'fee_basis' => 'pct_margin', 'fee_pct' => 0.10,
    ],
]);
$assert('economic model applies client discount only to AR',
    abs((float) $model['invoice_bill_rate'] - 95.00) < 0.0001);
$assert('economic model counts labor and every hourly obligation once',
    abs((float) $model['modeled_hourly_cost'] - 75.40) < 0.0001);
$assert('economic model produces deterministic margin',
    abs((float) $model['modeled_hourly_margin'] - 19.60) < 0.0001);
$assert('economic model carries fixed obligations separately',
    abs((float) $model['fixed_obligations'] - 25.00) < 0.0001);
$assert('economic model resolves the non-W2 labor recipient',
    !empty($model['labor_payee_resolved']) && (int) $model['labor_payee_count'] === 1);

echo "Placement commercial contract smoke: {$pass} OK / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
