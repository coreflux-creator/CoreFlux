<?php
/**
 * Legacy merge refusal and remaining People route/editor wiring smoke.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function ($n, $c) use (&$pass, &$fail) {
    if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; }
};

putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../modules/people/lib/companies.php';
$a('normalization groups suggestions without changing stored identities', companiesNormalizeName('Acme, LLC')===companiesNormalizeName('ACME Inc.'));
$blocked=false;try{companiesMerge(1,2,3,4);}catch(RuntimeException $e){$blocked=$e->getCode()===409;}
$a('legacy unreviewed merge is disabled', $blocked);
// Merge behavior is exercised by company_merge_review_db.php and native HTTP;
// rendered choice/confirmation behavior is covered by companyMergeReview.test.mjs.

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
$a('shared editor receives the person identity', str_contains($pd, '<RecordCustomFields entityType="people" recordId={personId}'));
$editor = (string) file_get_contents(__DIR__ . '/../dashboard/src/components/RecordCustomFields.jsx');
$a('values use the central entity and record route', str_contains($editor, '/api/custom_field_values.php?${query}&record_id='));
$a('save checks the original values and directory', str_contains($editor, 'expected_values: expected') && str_contains($editor, 'expected_owner_tenant_id: values.data.owner_tenant_id'));
$a('tab testid present', str_contains($editor, 'data-testid="tab-custom"'));
$a('save button', str_contains($editor, 'data-testid="custom-values-save"'));
$a('dynamic input by field_type date', str_contains($editor, "def.field_type === 'date'"));
$a('dynamic input by field_type boolean', str_contains($editor, "def.field_type === 'boolean'"));
$a('dynamic input by field_type select', str_contains($editor, "def.field_type === 'select'"));
$a('PII indicator', str_contains($editor, 'Number(def.pii) === 1'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
