<?php
/**
 * Slice 5 smoke — JobDiva field-mapping coverage extended to:
 *   - companies upsert (legal_name, duns, ein_last4, msa_signed_at, etc.)
 *   - company_contacts upsert (name override, role, is_primary, notes)
 *   - companiesUpsertByName re-encounter UPDATE behavior
 *   - allow-list audit (ghost fields removed; real columns added)
 *
 * Pure static analysis (string presence + structure). Production execution
 * is exercised by sprint8a / contacts_backfill smokes which now also pass.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$sync   = (string) file_get_contents('/app/core/jobdiva/sync.php');
$fmap   = (string) file_get_contents('/app/core/integrations/field_map.php');
$cohelp = (string) file_get_contents('/app/modules/people/lib/companies.php');

echo "\n1. Allow-list audit\n";
// Slice 5b (2026-02): the original ghost-field roster has been pared
// down — `employment_type`, `hire_date`, `termination_date`,
// `pay_frequency` (people: migration 006_unify_and_extend) and
// `industry` (companies: migration 005_companies_v2) ARE real schema
// columns and the broader-mapping expansion (Slice 5b) intentionally
// surfaces them. Only the truly synthetic columns remain banned.
foreach ([] as $ghost) {
    $a("ghost person field '{$ghost}' removed", !preg_match("/'{$ghost}'/", $fmap));
}
foreach (['billing_email','billing_terms','tax_id_last4'] as $ghost) {
    $a("ghost company field '{$ghost}' removed", !preg_match("/'{$ghost}'/", $fmap));
}
// 'description' was removed from the company allow-list (it's not a real
// `companies.*` column), but it IS a legitimate field on the
// gl_account / journal_entry / bill / invoice / payment entity types
// added in the QBO/Zoho/Xero rollout (2026-02). Assert removal scoped
// to the `'company' => [` block only, not the whole file.
if (preg_match("/'company'\\s*=>\\s*\\[(.*?)\\],\\s*\\n\\s*\\/\\//s", $fmap, $m)) {
    $a("ghost company field 'description' removed from company block",
        !preg_match("/'description'/", $m[1]));
} else {
    // Fallback if regex shape changes — still better than the old
    // global check which broke on legitimate uses elsewhere.
    $a('company block locatable for ghost-field assertion', false);
}
// Real schema columns must be present:
foreach (['legal_name','duns','ein_last4','primary_contact_name','primary_contact_email','primary_contact_phone','msa_signed_at','notes'] as $real) {
    $a("company allow-list has '{$real}'", str_contains($fmap, "'{$real}'"));
}
foreach (['middle_name','preferred_name','email_secondary','phone_secondary','classification','status','work_auth_status','linkedin_url','source','recruiter_notes'] as $real) {
    $a("person allow-list has '{$real}'", str_contains($fmap, "'{$real}'"));
}
$a("contact allow-list has 'is_primary'", str_contains($fmap, "'is_primary'"));

echo "\n2. jobdivaSyncCompanies registry wiring\n";
$a('requires field_map.php',
    (bool) preg_match("#jobdivaSyncCompanies.*?require_once.*?integrations/field_map\.php#s", $sync));
foreach ([
    ['name',                  'company name through registry'],
    ['website',               'website through registry'],
    ['phone',                 'phone through registry'],
    ['legal_name',            'legal_name through registry'],
    ['duns',                  'duns through registry'],
    ['ein_last4',             'ein_last4 through registry'],
    ['primary_contact_name',  'primary contact name through registry'],
    ['primary_contact_email', 'primary contact email through registry'],
    ['primary_contact_phone', 'primary contact phone through registry'],
    ['address_line1',         'address line 1 through registry'],
    ['city',                  'city through registry'],
    ['state',                 'state through registry'],
    ['postal_code',           'postal_code through registry'],
    ['country',               'country through registry'],
    ['notes',                 'notes through registry'],
    ['msa_signed_at',         'MSA date through registry'],
] as [$field, $label]) {
    $a($label, (bool) preg_match("/\\\$pluck\\('{$field}',/", $sync));
}

echo "\n3. jobdivaSyncUpsertContact registry wiring\n";
foreach ([
    "tenantIntegrationFieldMapPluckInternal" => 'invokes registry resolver',
    "'jobdiva', 'contact', 'email'"          => 'email via registry',
    "'jobdiva', 'contact', 'phone'"          => 'phone via registry',
    "'jobdiva', 'contact', 'title'"          => 'title via registry',
    "'jobdiva', 'contact', 'contact_role'"   => 'contact_role via registry',
    "'jobdiva', 'contact', 'is_primary'"     => 'is_primary via registry',
    "'jobdiva', 'contact', 'notes'"          => 'notes via registry',
    "'jobdiva', 'contact', 'name'"           => 'name override via registry',
    "'jobdiva', 'contact', 'first_name'"     => 'first_name override via registry',
    "'jobdiva', 'contact', 'last_name'"      => 'last_name override via registry',
] as $needle => $label) {
    $a($label, str_contains($sync, $needle));
}
// Persistence side — make sure new fields are written, not just read:
$a('UPDATE writes contact_role',  str_contains($sync, 'contact_role = :cr'));
$a('UPDATE writes is_primary',    str_contains($sync, 'is_primary = :ip'));
$a('UPDATE writes notes',         str_contains($sync, 'notes = :no'));
$a('INSERT binds all new cols',
    (bool) preg_match('#INSERT INTO company_contacts.+?contact_role,\s*is_primary,\s*notes#s', $sync));
$a('contact_role coerced to ENUM (other fallback)',
    str_contains($sync, "\$contactRoleMap[strtolower(trim(\$contactRoleRaw))] ?? 'other'"));

echo "\n4. companiesUpsertByName UPDATE branch\n";
$a('writable list includes duns',         str_contains($cohelp, "'duns'"));
$a('writable list includes ein_last4',    str_contains($cohelp, "'ein_last4'"));
$a('writable list includes msa_signed_at',str_contains($cohelp, "'msa_signed_at'"));
$a('non-empty patch triggers UPDATE',
    (bool) preg_match('#if \(!empty\(\$updatable\)\) \{.+?UPDATE companies SET#s', $cohelp));
$a('UPDATE drops created_by_user_id',     str_contains($cohelp, "unset(\$patch['created_by_user_id'])"));
$a('UPDATE filters null/empty patch values',
    (bool) preg_match('#array_filter\(\s*\$patch,\s*static fn\(\$v\) => \$v !== null && \$v !== ""\s*\)#', $cohelp)
    || (bool) preg_match("#array_filter\(\s*\\\$patch,\s*static fn\(\\\$v\) => \\\$v !== null && \\\$v !== ''#", $cohelp));
$a('UPDATE re-asserts tenant_id in WHERE',str_contains($cohelp, 'WHERE id = :id AND tenant_id = :tenant_id'));

echo "\n5. PHP syntax (no parse errors)\n";
foreach (['/app/core/jobdiva/sync.php', '/app/core/integrations/field_map.php', '/app/modules/people/lib/companies.php'] as $f) {
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode(' | ', array_slice($out, -2)));
    $out = [];
}

echo "\n=========================================\n";
echo "JobDiva field-mapping slice 5 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
