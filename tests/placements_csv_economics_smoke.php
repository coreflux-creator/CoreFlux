<?php
/**
 * Smoke - placements CSV import/export exposes the canonical placement graph.
 *
 * Guards against the importer regressing to "placement + bill/pay only".
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$import = (string) file_get_contents("{$ROOT}/modules/placements/api/csv_import.php");
$export = (string) file_get_contents("{$ROOT}/modules/placements/api/csv_export.php");

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. Import schema exposes placement metadata\n";
foreach ([
    'actual_end_date',
    'end_client_company_id',
    'client_approver_name',
    'jobdiva_job_id',
    'recruiter_name',
    'account_manager_name',
    'client_bill_cycle',
    'vendor_pay_cycle',
] as $field) {
    $a("schema includes {$field}", str_contains($import, "'{$field}'"));
}

echo "\n2. Import schema exposes rate economics\n";
foreach ([
    'rate_effective_from',
    'bill_rate_unit',
    'pay_rate_unit',
    'currency',
    'ot_multiplier',
    'dt_multiplier',
    'adder_pct',
    'background_fee_total',
] as $field) {
    $a("schema includes {$field}", str_contains($import, "'{$field}'"));
}

echo "\n3. Import schema exposes vendor chain\n";
foreach ([
    'msp_name',
    'msp_fee_pct',
    'prime_vendor_name',
    'prime_vendor_fee_pct',
    'sub_vendor_name',
    'sub_vendor_fee_pct',
] as $field) {
    $a("schema includes {$field}", str_contains($import, "'{$field}'"));
}

echo "\n4. Commit writer targets the right graphs\n";
$a('requires companies + staffing clients libs',
    str_contains($import, 'people/lib/companies.php')
    && str_contains($import, 'staffing/lib/clients.php'));
$a('end-client import resolves companies and staffing client consumer row',
    str_contains($import, 'companiesUpsertByName(currentTenantId()')
    && str_contains($import, 'staffingClientEnsureForCompany('));
$a('rate importer updates/creates placement_rates drafts',
    str_contains($import, 'function placementsCsvUpsertDraftRate')
    && str_contains($import, 'FROM placement_rates')
    && str_contains($import, 'scopedInsert(\'placement_rates\''));
$a('chain importer writes placement_client_chain tiers',
    str_contains($import, 'function placementsCsvUpsertChain')
    && str_contains($import, "'msp'")
    && str_contains($import, "'prime_vendor'")
    && str_contains($import, "'sub_vendor'"));
$a('percentage helpers convert 22 or 22% to decimals',
    str_contains($import, 'function placementsCsvPercentToDecimal')
    && str_contains($import, 'abs($n) > 1'));

echo "\n5. Export mirrors the widened import surface\n";
foreach ([
    'placement_id',
    'person_id',
    'actual_end_date',
    'jobdiva_job_id',
    'bill_rate_unit',
    'adder_pct',
    'background_fee_total',
    'msp_name',
    'prime_vendor_name',
    'sub_vendor_name',
] as $field) {
    $a("export includes {$field}", str_contains($export, $field));
}
$a('export joins current placement_rates row',
    str_contains($export, 'LEFT JOIN placement_rates r')
    && str_contains($export, 'rr.placement_id = p.id'));
$a('export reads placement_client_chain roles',
    str_contains($export, 'placement_client_chain')
    && str_contains($export, 'party_role = \\\'msp\\\'')
    && str_contains($export, 'party_role = \\\'prime_vendor\\\'')
    && str_contains($export, 'party_role = \\\'sub_vendor\\\''));

echo "\n6. PHP syntax\n";
foreach ([
    "{$ROOT}/modules/placements/api/csv_import.php",
    "{$ROOT}/modules/placements/api/csv_export.php",
] as $file) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
    $a('php -l ' . basename($file), $rc === 0, implode("\n", $out));
}

echo "\nPlacements CSV economics smoke: {$pass} ✓ / {$fail} ✗\n";
exit($fail === 0 ? 0 : 1);
