<?php
/**
 * Smoke — JobDiva broader integration mapping (Slice 5b, 2026-02).
 *
 * Verifies:
 *   1. The tenant_integration_field_map allow-list surfaces the broader
 *      column set for placement / person / company / contact.
 *   2. The JobDiva syncer (`/app/core/jobdiva/sync.php`) is wired to
 *      resolve those new fields via the registry with safe fallbacks.
 *   3. ENUM coercion + date normalisation guards are present so a
 *      free-text upstream payload can't break the prepared statements.
 *   4. The placement INSERT + UPDATE branches both write the new
 *      placement cycle columns; the contact upsert writes the new
 *      company_contacts columns.
 *   5. PHP syntax stays green.
 *
 * Pure source-level (no live DB). The `tenant_integration_field_map`
 * read/write helpers already have DB-backed coverage in their own
 * smoke (`field_map_*_smoke.php`).
 */
declare(strict_types=1);

require_once '/app/core/integrations/field_map.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. Allow-list expansion (placement)\n";
$placement = tenantIntegrationFieldMapAllowedInternalFields('placement');
foreach ([
    'client_bill_cycle', 'client_bill_cycle_anchor',
    'vendor_pay_cycle',  'vendor_pay_cycle_anchor',
] as $f) {
    $a("placement allow-list surfaces {$f}", in_array($f, $placement, true));
}
$a('placement allow-list excludes source-owned external_id', !in_array('external_id', $placement, true));

echo "\n2. Allow-list expansion (person)\n";
$person = tenantIntegrationFieldMapAllowedInternalFields('person');
foreach ([
    'employment_type', 'hire_date', 'termination_date',
    'pay_frequency', 'worker_class',
    'mailing_address_line1', 'mailing_address_line2',
    'mailing_city', 'mailing_state', 'mailing_postal_code', 'mailing_country',
] as $f) {
    $a("person allow-list surfaces {$f}", in_array($f, $person, true));
}
foreach (['dob', 'ssn_last4', 'tenant_id', 'id'] as $banned) {
    $a("person allow-list still excludes {$banned}", !in_array($banned, $person, true));
}
$a('person allow-list excludes source-owned external_id', !in_array('external_id', $person, true));

echo "\n3. Allow-list expansion (company)\n";
$company = tenantIntegrationFieldMapAllowedInternalFields('company');
foreach ([
    'payment_terms_days', 'default_terms', 'currency',
    'status', 'tax_classification',
    'industry', 'employee_size_range',
    'w9_on_file', 'w9_expires_on',
    'coi_on_file', 'coi_expires_on',
    'tags_json',
] as $f) {
    $a("company allow-list surfaces {$f}", in_array($f, $company, true));
}
foreach (['ein_full_ct', 'msa_storage_object_id', 'account_manager_user_id', 'tenant_id'] as $banned) {
    $a("company allow-list still excludes {$banned}", !in_array($banned, $company, true));
}
$a('company allow-list excludes source-owned external_id', !in_array('external_id', $company, true));

echo "\n4. Allow-list expansion (contact)\n";
$contact = tenantIntegrationFieldMapAllowedInternalFields('contact');
foreach ([
    'mobile_phone', 'linkedin_url', 'department',
    'decision_role', 'is_active',
] as $f) {
    $a("contact allow-list surfaces {$f}", in_array($f, $contact, true));
}
$a('contact allow-list excludes source-owned external_id', !in_array('external_id', $contact, true));

echo "\n5. Placement syncer wire-in (jobdivaSyncUpsertPlacement)\n";
$sync = (string) file_get_contents('/app/core/jobdiva/sync.php');
$a('resolves client_bill_cycle via registry',
   str_contains($sync, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'placement', 'client_bill_cycle', \$jd,"));
$a('resolves vendor_pay_cycle via registry',
   str_contains($sync, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'placement', 'vendor_pay_cycle', \$jd,"));
$a('resolves client_bill_cycle_anchor via registry',
   str_contains($sync, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'placement', 'client_bill_cycle_anchor', \$jd,"));
$a('resolves vendor_pay_cycle_anchor via registry',
   str_contains($sync, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'placement', 'vendor_pay_cycle_anchor', \$jd,"));
$a('cycle enum coercion handles biweekly variants',
   str_contains($sync, "'bi-weekly' => 'biweekly'")
   && str_contains($sync, "'semi-monthly' => 'semimonthly'")
   && str_contains($sync, "'ad-hoc' => 'adhoc'"));
$a('cycle anchors normalised through jobdivaNormaliseDate',
   str_contains($sync, 'jobdivaNormaliseDate($clientBillCycleAnchorRaw)')
   && str_contains($sync, 'jobdivaNormaliseDate($vendorPayCycleAnchorRaw)'));
$a('placement UPDATE allFields map includes client_bill_cycle',
   str_contains($sync, "'client_bill_cycle'         => ['cbc',  \$clientBillCycle]"));
$a('placement UPDATE allFields map includes vendor_pay_cycle',
   str_contains($sync, "'vendor_pay_cycle'          => ['vpc',  \$vendorPayCycle]"));
$a('placement UPDATE skips null ENUM cycle to keep DB default',
   str_contains($sync, "in_array(\$col, ['client_bill_cycle', 'vendor_pay_cycle'], true)"));
$a('placement INSERT writes all four cycle columns',
   str_contains($sync, 'client_bill_cycle, client_bill_cycle_anchor,')
   && str_contains($sync, 'vendor_pay_cycle, vendor_pay_cycle_anchor'));
$a('placement INSERT falls back to schema defaults when payload silent',
   str_contains($sync, "\$clientBillCycle ?? 'monthly'")
   && str_contains($sync, "\$vendorPayCycle ?? 'biweekly'"));

echo "\n6. Contact syncer wire-in (jobdivaSyncUpsertContact)\n";
foreach ([
    'mobile_phone', 'linkedin_url', 'department', 'decision_role', 'is_active',
] as $f) {
    $a("resolves {$f} via registry",
       str_contains($sync, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'contact', '{$f}', \$jd,"));
}
$a('decision_role coerces unknown values to "unknown"',
   str_contains($sync, "\$decisionRoleMap[strtolower(trim(\$decisionRoleRaw))] ?? 'unknown'"));
$a('is_active treats inactive synonyms as 0',
   str_contains($sync, "['0', 'false', 'no', 'n', 'inactive', 'disabled']"));
$a('contact UPDATE writes mobile_phone + linkedin_url + department + decision_role + is_active',
   str_contains($sync, 'mobile_phone = :mp')
   && str_contains($sync, 'linkedin_url = :lu')
   && str_contains($sync, 'department = :dp')
   && str_contains($sync, 'decision_role = :dr')
   && str_contains($sync, 'is_active = :ia'));
$a('contact INSERT writes the new columns',
   str_contains($sync, 'mobile_phone, linkedin_url, department, decision_role, is_active'));

echo "\n7. PHP syntax\n";
foreach ([
    '/app/core/integrations/field_map.php',
    '/app/core/jobdiva/sync.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "JobDiva broader field-map smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
