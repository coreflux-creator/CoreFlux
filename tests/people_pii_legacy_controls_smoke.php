<?php
/**
 * Legacy People/PII controls smoke.
 *
 * Locks the Phase 2 rule that older People endpoints cannot expose or mutate
 * PII, bank, tax, I-9, address, onboarding-readiness, or comp records under
 * plain authentication or broad people.view/manage alone.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$a = function (string $msg, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { echo "  OK  {$msg}\n"; $pass++; }
    else     { echo "  BAD {$msg}\n"; $fail++; }
};
$read = static fn (string $rel): string => (string) file_get_contents("{$ROOT}/{$rel}");
$lint = static function (string $rel) use ($ROOT): bool {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    return $rc === 0;
};

$people = $read('modules/people/api/people.php');
$employees = $read('modules/people/api/employees.php');
$employeesLib = $read('modules/people/lib/employees.php');
$bank = $read('modules/people/api/bank_accounts.php');
$tax = $read('modules/people/api/tax.php');
$taxFederal = $read('modules/people/api/tax_federal.php');
$taxState = $read('modules/people/api/tax_state.php');
$addresses = $read('modules/people/api/addresses.php');
$i9 = $read('modules/people/api/i9.php');
$contacts = $read('modules/people/api/contacts.php');
$emergency = $read('modules/people/api/emergency_contacts.php');
$customValues = $read('modules/people/api/custom_field_values.php');
$aiMissing = $read('modules/people/api/ai_missing_fields.php');
$aiSetup = $read('modules/people/api/ai_setup_email.php');
$sendSetup = $read('modules/people/api/send_setup_email.php');
$comp = $read('modules/people/api/compensation.php');
$orgChart = $read('modules/people/api/org_chart.php');
$manifest = $read('modules/people/manifest.php');
$legacyMap = $read('core/rbac/legacy_map.php');
$docs = $read('docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md');

echo "PHP syntax\n";
foreach ([
    'modules/people/api/people.php',
    'modules/people/api/employees.php',
    'modules/people/api/bank_accounts.php',
    'modules/people/api/tax.php',
    'modules/people/api/tax_federal.php',
    'modules/people/api/tax_state.php',
    'modules/people/api/addresses.php',
    'modules/people/api/i9.php',
    'modules/people/api/contacts.php',
    'modules/people/api/emergency_contacts.php',
    'modules/people/api/custom_field_values.php',
    'modules/people/api/ai_missing_fields.php',
    'modules/people/api/ai_setup_email.php',
    'modules/people/api/send_setup_email.php',
    'modules/people/api/compensation.php',
    'modules/people/api/org_chart.php',
    'modules/people/lib/employees.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nUnified and legacy employee PII\n";
$a('gender and marital_status are gated as PII on unified person writes',
    str_contains($people, "'dob', 'ssn_last4', 'gender', 'marital_status'"));
$a('legacy employee detail only shows PII with people.pii.view',
    str_contains($employees, "rbac_legacy_can(\$user, 'people.pii.view')")
    && str_contains($employees, "unset(\$out['ssn_last4'], \$out['date_of_birth'], \$out['gender'], \$out['marital_status'], \$out['citizenship_status'])"));
$a('legacy employee PII writes require people.pii.manage',
    str_contains($employees, 'function _employeePiiInputFields')
    && str_contains($employees, "rbac_legacy_require(\$user, 'people.pii.manage')"));
$a('legacy employee PII read/write is audit logged',
    str_contains($employees, 'employee.pii.viewed')
    && str_contains($employees, 'employee.pii.updated'));
$a('W-2 bridge does not copy DOB into legacy employee rows',
    !str_contains($employeesLib, 'p.dob')
    && !str_contains($employeesLib, "'date_of_birth'    =>"));

echo "\nBanking, tax, addresses, and I-9\n";
$a('legacy bank reads require people.banking.view',
    str_contains($bank, "rbac_legacy_require(\$user, 'people.banking.view')"));
$a('legacy bank writes require people.banking.manage',
    substr_count($bank, "rbac_legacy_require(\$user, 'people.banking.manage')") >= 3);
$a('legacy bank access emits platform audit',
    str_contains($bank, "peopleAudit('people.banking.viewed'")
    && str_contains($bank, "peopleAudit('people.banking.updated'"));
$a('unified tax reads emit tax viewed audit',
    str_contains($tax, "peopleAudit('people.tax.viewed'"));
$a('legacy federal tax requires explicit tax permissions',
    str_contains($taxFederal, "rbac_legacy_require(\$user, 'people.tax.view')")
    && str_contains($taxFederal, "rbac_legacy_require(\$user, 'people.tax.manage')"));
$a('legacy state tax requires explicit tax permissions',
    str_contains($taxState, "rbac_legacy_require(\$user, 'people.tax.view')")
    && str_contains($taxState, "rbac_legacy_require(\$user, 'people.tax.manage')"));
$a('legacy address endpoint requires PII permissions',
    str_contains($addresses, "rbac_legacy_require(\$user, 'people.pii.view')")
    && str_contains($addresses, "rbac_legacy_require(\$user, 'people.pii.manage')"));
$a('legacy I-9 endpoint requires PII permissions',
    str_contains($i9, "rbac_legacy_require(\$user, 'people.pii.view')")
    && str_contains($i9, "rbac_legacy_require(\$user, 'people.pii.manage')"));

echo "\nContacts, custom fields, AI helpers, and comp\n";
$a('specific emergency-contact endpoint requires PII permissions',
    str_contains($emergency, "rbac_legacy_require(\$user, 'people.pii.view')")
    && str_contains($emergency, "rbac_legacy_require(\$user, 'people.pii.manage')"));
$a('generic emergency-contact resource switches to PII permissions',
    str_contains($contacts, "\$readPermission = \$resource === 'emergency_contacts' ? 'people.pii.view' : 'people.view'")
    && str_contains($contacts, "\$writePermission = \$resource === 'emergency_contacts' ? 'people.pii.manage' : 'people.manage'"));
$a('PII custom field reads redact without people.pii.view',
    str_contains($customValues, "'people.pii.view'")
    && str_contains($customValues, "customFieldValues(\$tenantId, \$entityType, \$personId, \$canPii)")
    && str_contains($customValues, "peopleCustomFieldHasPiiDefinitions(\$tenantId) && !\$canPii"));
$a('PII custom field writes require people.pii.manage',
    str_contains($customValues, "'people.pii.manage'")
    && str_contains($customValues, "\$canPiiManage")
    && str_contains($customValues, "'required' => \$piiManagePerm"));
$a('legacy custom fields use platform service',
    str_contains($customValues, 'customFieldValueUpsert(')
    && str_contains($customValues, 'customFieldAudit('));
$a('PII custom field reads/writes are logged',
    str_contains($customValues, 'custom_field_pii.viewed')
    && str_contains($customValues, 'custom_field_pii.set'));
$a('AI missing-fields readiness requires PII view',
    str_contains($aiMissing, "rbac_legacy_require(\$user, 'people.pii.view')"));
$a('AI setup email requires manage and PII view',
    str_contains($aiSetup, "rbac_legacy_require(\$user, 'people.manage')")
    && str_contains($aiSetup, "rbac_legacy_require(\$user, 'people.pii.view')"));
$a('send setup email requires manage and PII view',
    str_contains($sendSetup, "rbac_legacy_require(\$user, 'people.manage')")
    && str_contains($sendSetup, "rbac_legacy_require(\$user, 'people.pii.view')"));
$a('legacy compensation endpoint requires comp permissions',
    str_contains($comp, "rbac_legacy_require(\$user, 'people.comp.view')")
    && str_contains($comp, "rbac_legacy_require(\$user, 'people.comp.manage')"));
$a('org chart read endpoint requires people.view',
    str_contains($orgChart, "rbac_legacy_require(\$user, 'people.view')"));

echo "\nManifest, RBAC map, and docs\n";
$a('manifest declares tax viewed event',
    str_contains($manifest, "'people.tax.viewed'"));
$a('legacy RBAC map declares people.comp permissions',
    str_contains($legacyMap, "'people.comp.view'")
    && str_contains($legacyMap, "'people.comp.manage'"));
$a('alignment doc records legacy People/PII hardening',
    str_contains($docs, 'Legacy People/PII Controls'));

echo "\nLegacy People/PII controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
