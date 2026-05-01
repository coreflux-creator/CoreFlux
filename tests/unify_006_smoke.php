<?php
/**
 * Migration 006 (unify_and_extend) + New-Hire Wizard — contract smoke tests.
 * Static checks only. No live DB.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function ($n, $c) use (&$pass, &$fail) {
    if ($c) { echo "  \u{2713} {$n}\n"; $pass++; }
    else    { echo "  \u{2717} {$n}\n"; $fail++; }
};

echo "Migration 006 SQL\n";
$sql = (string) file_get_contents(__DIR__ . '/../modules/people/migrations/006_unify_and_extend.sql');
$assert('migration exists',                 strlen($sql) > 0);
$assert('utf8mb4_unicode_ci safe',          strpos($sql, 'utf8mb4_0900_ai_ci') === false);

// Part A — AP unification
$assert('ap_vendors_index.company_id',      strpos($sql, "TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='company_id'") !== false);
$assert('ap_bills.vendor_company_id',       strpos($sql, "TABLE_NAME='ap_bills' AND COLUMN_NAME='vendor_company_id'") !== false);
$assert('ap_payments.vendor_company_id',    strpos($sql, "TABLE_NAME='ap_payments' AND COLUMN_NAME='vendor_company_id'") !== false);
$assert('ap_1099_ledger.vendor_company_id', strpos($sql, "TABLE_NAME='ap_1099_ledger' AND COLUMN_NAME='vendor_company_id'") !== false);
$assert('AP backfills vendors into companies', strpos($sql, "FROM ap_vendors_index") !== false);
$assert('AP skips 1099_individual for company row', strpos($sql, "vendor_type IN ('c2c_corp','w9_business','utility','other')") !== false);
$assert('AP tags role=vendor',              strpos($sql, "'vendor'") !== false);
$assert('AP backfills bills.vendor_company_id', strpos($sql, 'UPDATE ap_bills b') !== false);
$assert('AP backfills payments.vendor_company_id', strpos($sql, 'UPDATE ap_payments p') !== false);
$assert('AP adds idx_apb_vendor_company',   strpos($sql, 'idx_apb_vendor_company') !== false);

// Part B — Billing unification
$assert('billing_invoices.client_company_id', strpos($sql, "TABLE_NAME='billing_invoices' AND COLUMN_NAME='client_company_id'") !== false);
$assert('Billing backfills into companies',   strpos($sql, 'FROM billing_invoices') !== false);
$assert('Billing tags role=client',           strpos($sql, "'client'") !== false);
$assert('Billing backfills invoices.client_company_id', strpos($sql, 'UPDATE billing_invoices bi') !== false);
$assert('Billing adds idx_bi_client_company', strpos($sql, 'idx_bi_client_company') !== false);

// Part C — Placement chain extension
foreach (['submittal_id','vms_job_id','portal_credentials_ct','kms_key_version'] as $col) {
    $assert("chain.{$col} added",           strpos($sql, "TABLE_NAME='placement_client_chain' AND COLUMN_NAME='{$col}'") !== false);
}
$assert('chain.portal_credentials_ct is VARBINARY(2048)', strpos($sql, 'portal_credentials_ct VARBINARY(2048)') !== false);

// Part D — People extension
foreach ([
    'employment_type','hire_date','termination_date','pay_frequency',
    'gender','marital_status',
    'mailing_address_line1','mailing_address_line2','mailing_city',
    'mailing_state','mailing_postal_code','mailing_country',
] as $col) {
    $assert("people.{$col} added",          strpos($sql, "TABLE_NAME='people' AND COLUMN_NAME='{$col}'") !== false);
}
$assert('people.employment_type enum 5',    strpos($sql, 'ENUM("full_time","part_time","contractor","intern","temp")') !== false);
$assert('people.pay_frequency enum 4',      strpos($sql, 'ENUM("weekly","biweekly","semimonthly","monthly")') !== false);
$assert('people hire_date indexed',         strpos($sql, 'idx_people_tenant_hire') !== false);

echo "\nAP API unification wiring\n";
$vapi = (string) file_get_contents(__DIR__ . '/../modules/ap/api/vendors.php');
$assert('vendors GET joins companies',      strpos($vapi, 'LEFT JOIN companies c') !== false);
$assert('vendors GET filter by company_id', strpos($vapi, "\$_GET['company_id']") !== false);
$assert('vendors POST resolves company_id', strpos($vapi, 'companiesUpsertByName') !== false);
$assert('vendors POST tags role=vendor',    strpos($vapi, "'vendor'") !== false);
$assert('vendors POST skips 1099 individual', strpos($vapi, "'c2c_corp','w9_business','utility','other'") !== false);
$assert('vendors UPSERT persists company_id', strpos($vapi, 'company_id     = COALESCE(VALUES(company_id), company_id)') !== false);

$bapi = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bills.php');
$assert('bills manual POST sets vendor_company_id', strpos($bapi, "'vendor_company_id' => \$vendorCompanyId") !== false);
$assert('bills time-bundle sets vendor_company_id', strpos($bapi, "UPDATE ap_bills SET vendor_company_id") !== false);
$assert('bills PATCH allows vendor_company_id',     strpos($bapi, "'vendor_name','vendor_company_id'") !== false);

echo "\nBilling API unification wiring\n";
$iapi = (string) file_get_contents(__DIR__ . '/../modules/billing/api/invoices.php');
$assert('invoice manual POST resolves client_company_id', strpos($iapi, '$clientCompanyId = !empty') !== false);
$assert('invoice manual POST upserts company',            strpos($iapi, 'companiesUpsertByName') !== false);
$assert('invoice time-bundle resolves client_company_id', strpos($iapi, 'companiesBumpUsage($clientCid)') !== false);
$assert('invoice PATCH allows client_company_id',         strpos($iapi, "'client_name','client_company_id'") !== false);

echo "\nPeople API + lib (extended fields)\n";
$papi = (string) file_get_contents(__DIR__ . '/../modules/people/api/people.php');
$assert('people POST accepts employment_type',  strpos($papi, "'employment_type','hire_date','termination_date','pay_frequency','gender','marital_status'") !== false);
$assert('people PII list includes mailing_*',   strpos($papi, "'mailing_address_line1'") !== false);

$plib = (string) file_get_contents(__DIR__ . '/../modules/people/lib/people.php');
$assert('lib safeFields includes employment_type', strpos($plib, 'employment_type') !== false);
$assert('lib safeFields includes hire_date',       strpos($plib, 'hire_date') !== false);
$assert('lib PIIFields includes gender',           strpos($plib, 'gender') !== false);
$assert('lib PIIFields includes mailing_address_line1', strpos($plib, 'mailing_address_line1') !== false);

echo "\nNew-Hire Wizard UI (PersonCreate.jsx)\n";
$pc = (string) file_get_contents(__DIR__ . '/../modules/people/ui/PersonCreate.jsx');
$assert('wizard has stepper',                   strpos($pc, 'person-create-stepper') !== false);
$assert('wizard has 3 steps',                   strpos($pc, "const STEPS = [") !== false);
$assert('wizard step 1 panel',                  strpos($pc, 'person-create-step-1-panel') !== false);
$assert('wizard step 2 panel',                  strpos($pc, 'person-create-step-2-panel') !== false);
$assert('wizard step 3 panel',                  strpos($pc, 'person-create-step-3-panel') !== false);
$assert('wizard next button',                   strpos($pc, 'person-create-next') !== false);
$assert('wizard prev button',                   strpos($pc, 'person-create-prev') !== false);
$assert('wizard submit button retained',        strpos($pc, 'person-create-submit') !== false);
$assert('wizard mailing_same_as_home default',  strpos($pc, 'mailing_same_as_home: true') !== false);
$assert('wizard employment_type input',         strpos($pc, 'person-create-employment-type') !== false);
$assert('wizard hire_date input',               strpos($pc, 'person-create-hire-date') !== false);
$assert('wizard pay_frequency input',           strpos($pc, 'person-create-pay-frequency') !== false);
$assert('wizard DOB input',                     strpos($pc, 'person-create-dob') !== false);
$assert('wizard SSN last4 input',               strpos($pc, 'person-create-ssn-last4') !== false);
$assert('wizard gender input',                  strpos($pc, 'person-create-gender') !== false);
$assert('wizard marital input',                 strpos($pc, 'person-create-marital') !== false);
$assert('wizard home address input',            strpos($pc, 'person-create-home-line1') !== false);
$assert('wizard mailing toggle',                strpos($pc, 'person-create-mailing-same') !== false);
$assert('wizard placement title input',         strpos($pc, 'person-create-placement-title') !== false);
$assert('wizard placement end-client typeahead', strpos($pc, 'person-create-placement-end-client') !== false);
$assert('wizard CompanyTypeahead imported',     strpos($pc, "import CompanyTypeahead") !== false);
$assert('wizard placement creation posts to /placements.php', strpos($pc, '/modules/placements/api/placements.php') !== false);
$assert('wizard rates call on placement create', strpos($pc, '/modules/placements/api/rates.php') !== false);
$assert('wizard strips empty date/enum fields', strpos($pc, "if (!out[k]) delete out[k]") !== false);
$assert('wizard mirror mailing when same',       strpos($pc, 'out.mailing_address_line1 = out.home_address_line1') !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
