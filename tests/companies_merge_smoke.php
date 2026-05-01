<?php
/**
 * Companies Merge duplicates + New-Hire Wizard backlog — contract smoke.
 * Static contract checks on the lib + API + React wiring.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function ($n, $c) use (&$pass, &$fail) {
    if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; }
};

echo "companies.lib merge helpers\n";
$lib = (string) file_get_contents(__DIR__ . '/../modules/people/lib/companies.php');
$a('companiesNormalizeName() exists',          strpos($lib, 'function companiesNormalizeName') !== false);
$a('strips inc/llc/corp/company',              strpos($lib, 'inc|incorporated|llc|l l c|ltd|limited|co|corp|corporation|company') !== false);
$a('companiesDuplicateCandidates() exists',    strpos($lib, 'function companiesDuplicateCandidates') !== false);
$a('candidates returns groups ≥2',             strpos($lib, 'if (count($rows) < 2) continue') !== false);
$a('companiesMerge() exists',                  strpos($lib, 'function companiesMerge(int $tenantId, int $survivorId, int $victimId') !== false);
$a('merge blocks self-merge',                  strpos($lib, 'Cannot merge a company into itself') !== false);
$a('merge blocks cross-tenant',                strpos($lib, 'Cross-tenant merge blocked') !== false);
$a('merge refuses already-deleted',            strpos($lib, 'is already soft-deleted') !== false);
$a('merge wrapped in transaction',             strpos($lib, '$pdo->beginTransaction()') !== false && strpos($lib, 'rollBack()') !== false);
foreach ([
    'ap_vendors_index'        => 'company_id',
    'ap_bills'                => 'vendor_company_id',
    'ap_payments'             => 'vendor_company_id',
    'ap_1099_ledger'          => 'vendor_company_id',
    'billing_invoices'        => 'client_company_id',
    'placements'              => 'end_client_company_id',
    'placement_client_chain'  => 'company_id',
    'placement_referrals'     => 'referrer_company_id',
] as $tbl => $col) {
    $a("merge redirects {$tbl}.{$col}", strpos($lib, "_cfMergeRedirect") !== false
                                         && strpos($lib, "'{$tbl}'") !== false
                                         && strpos($lib, "'{$col}'") !== false);
}
$a('roles union via INSERT IGNORE',            strpos($lib, 'INSERT IGNORE INTO company_roles') !== false);
$a('contacts reparented',                      strpos($lib, "_cfMergeReparent(\$pdo, 'company_contacts'") !== false);
$a('addresses reparented',                     strpos($lib, "_cfMergeReparent(\$pdo, 'company_addresses'") !== false);
$a('victim soft-deleted',                      strpos($lib, 'UPDATE companies SET deleted_at = NOW()') !== false);
$a('survivor use_count bumped',                strpos($lib, 's.use_count = s.use_count + v.use_count') !== false);
$a('audit row written',                        strpos($lib, "companiesAudit('company.merged'") !== false);

echo "\ncompanies API endpoints\n";
$api = (string) file_get_contents(__DIR__ . '/../modules/people/api/companies.php');
$a('GET ?action=duplicates route',             strpos($api, "GET' && \$action === 'duplicates'") !== false);
$a('POST ?action=merge route',                 strpos($api, "POST' && \$action === 'merge'") !== false);
$a('merge requires people.manage',             strpos($api, "RBAC::requirePermission(\$user, 'people.manage')") !== false);
$a('merge validates both ids',                 strpos($api, 'survivor id (query) and victim_id (body) required') !== false);
$a('merge returns 409 on error',               strpos($api, "api_error(\$e->getMessage(), 409)") !== false);

echo "\nCompaniesMerge React page\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/people/ui/CompaniesMerge.jsx');
$a('page testid',                              strpos($ui, 'companies-merge-page') !== false);
$a('fetches duplicates endpoint',              strpos($ui, 'action=duplicates') !== false);
$a('posts merge endpoint',                     strpos($ui, 'action=merge&id=') !== false);
$a('confirm before merge',                     strpos($ui, 'confirm(`Merge company') !== false);
$a('group contains radio survivor picker',     strpos($ui, 'companies-merge-pick-') !== false);
$a('merge button per row',                     strpos($ui, 'companies-merge-into-survivor-') !== false);
$a('empty state testid',                       strpos($ui, 'companies-merge-empty') !== false);
$a('notice rendering',                         strpos($ui, 'companies-merge-notice') !== false);

echo "\nPeopleModule routes merge\n";
$pm = (string) file_get_contents(__DIR__ . '/../modules/people/ui/PeopleModule.jsx');
$a('imports CompaniesMerge',                   strpos($pm, "import CompaniesMerge from './CompaniesMerge'") !== false);
$a('registers /merge route',                   strpos($pm, 'path="merge"') !== false);

echo "\nDirectoryModule links to merge\n";
$dm = (string) file_get_contents(__DIR__ . '/../modules/people/ui/DirectoryModule.jsx');
$a('Merge duplicates link present',            strpos($dm, 'Merge duplicates') !== false);
$a('link testid',                              strpos($dm, '-merge-link') !== false);

echo "\nPersonDetail custom-fields tab\n";
$pd = (string) file_get_contents(__DIR__ . '/../modules/people/ui/PersonDetail.jsx');
$a('CustomFieldsTab rendered route',           strpos($pd, 'path="custom"    element={<CustomFieldsTab') !== false);
$a('tabs contains custom entry',               strpos($pd, "slug: 'custom'") !== false);
$a('values GET uses person_id',                strpos($pd, 'custom_field_values.php?person_id=') !== false);
$a('values POST endpoint',                     strpos($pd, "api.post(`/modules/people/api/custom_field_values.php?person_id=\${personId}`") !== false);
$a('tab testid present',                       strpos($pd, 'data-testid="tab-custom"') !== false);
$a('save button',                              strpos($pd, 'data-testid="custom-values-save"') !== false);
$a('dynamic input by field_type date',         strpos($pd, "def.field_type === 'date'") !== false);
$a('dynamic input by field_type boolean',      strpos($pd, "def.field_type === 'boolean'") !== false);
$a('dynamic input by field_type select',       strpos($pd, "def.field_type === 'select'") !== false);
$a('PII indicator 🔒',                         strpos($pd, '🔒') !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
