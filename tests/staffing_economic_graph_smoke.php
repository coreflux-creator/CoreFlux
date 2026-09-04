<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$checks = [];
$assert = static function (string $name, bool $ok) use (&$checks): void {
    $checks[] = [$name, $ok];
    echo ($ok ? "PASS" : "FAIL") . " - {$name}\n";
};

$migration = $read('core/migrations/126_staffing_economic_graph.sql');
$contractMigration = $read('core/migrations/127_staffing_contract_terms.sql');
$economics = $read('modules/placements/lib/economics.php');
$economicsApi = $read('modules/placements/api/economics.php');
$cyclesApi = $read('modules/placements/api/cycles.php');
$placementsApi = $read('modules/placements/api/placements.php');
$ap = $read('modules/ap/lib/ap.php');
$apApi = $read('modules/ap/api/bills.php');
$pwp = $read('modules/ap/lib/pwp.php');
$settlement = $read('modules/time/lib/settlement.php');
$settlementCreate = $read('modules/time/lib/settlement_create.php');
$payrollRuns = $read('modules/payroll/api/runs.php');
$payrollLib = $read('modules/payroll/lib/payroll.php');
$payrollCompute = $read('modules/payroll/lib/compute.php');
$billing = $read('modules/billing/lib/billing.php');
$billingApi = $read('modules/billing/api/invoices.php');
$jobdiva = $read('core/jobdiva/sync.php');
$fieldApply = $read('core/integrations/field_map_apply.php');
$fieldMap = $read('core/integrations/field_map.php');
$ui = $read('modules/placements/ui/PlacementDetail.jsx');

$assert('migration creates purpose-specific operating cycles',
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS staffing_operating_cycles'));
$assert('migration creates canonical economic parties',
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS placement_economic_parties'));
$assert('migration creates auditable settlement obligations',
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS placement_economic_obligations'));
$assert('migration stores placement vendor terms and PWP',
    str_contains($migration, 'vendor_payment_terms_override') && str_contains($migration, 'vendor_pwp_enabled'));
$assert('contract migration stores client terms and invoice audit terms',
    str_contains($contractMigration, 'client_payment_terms_override')
    && str_contains($contractMigration, "TABLE_NAME='billing_invoices'")
    && str_contains($contractMigration, 'ADD COLUMN payment_terms'));
$assert('party overrides inherit terms, PWP, and cycles independently',
    str_contains($migration, 'payment_terms_overridden')
    && str_contains($migration, 'pwp_overridden')
    && str_contains($migration, 'cycle_overridden')
    && str_contains($economics, 'IF(payment_terms_overridden = 1')
    && !str_contains($economics, '$sets[] = \'source_managed = 0\''));
$assert('migration guards module-table alters and legacy cycle backfill',
    str_contains($migration, '@tbl>0 AND @col=0')
    && str_contains($migration, '@payroll_tables=2')
    && str_contains($migration, '@billing_cols=2')
    && str_contains($migration, '@ap_cols=2')
    && str_contains($migration, '@payroll_cols=2')
    && !str_contains($migration, 'ADD COLUMN billing_operating_cycle_id BIGINT UNSIGNED NULL AFTER'));

foreach (['placement:end_client', 'source_type\' => \'worker', 'source_type\' => \'corp',
          'source_type\' => \'chain', 'source_type\' => \'referral', 'source_type\' => \'commission'] as $needle) {
    $assert("reconciler projects {$needle}", str_contains($economics, $needle));
}
$assert('AP vendor identity is rooted in company and vendor graphs',
    str_contains($economics, "companiesAddRole(\$companyId, 'vendor')")
    && str_contains($economics, 'ap_vendors_index'));
$assert('all chain vendors normalize even before they become payable',
    str_contains($economics, '$isPayable || $isVendorRole'));
$assert('source overrides deactivate when their source row disappears',
    str_contains($economics, '$managedSourceTypes = [\'placement\',\'worker\',\'chain\',\'corp\',\'referral\',\'commission\']')
    && !str_contains($economics, 'placement_id = :p AND source_managed = 1'));
$assert('relationship terms remain placement overrides',
    !str_contains($economics, 'UPDATE ap_vendors_index SET default_terms = :terms, default_pwp = :pwp'));
$assert('economic charges cover labor, portal, referral, and margin bases',
    str_contains($economics, 'function placementEconomicsApCharges')
    && str_contains($economics, "'portal_fee_pct', 'pct_bill', 'bill_rate'")
    && str_contains($economics, "'pct_margin', 'net_margin', 'gross_margin'"));
$assert('obligations are idempotent by source and party',
    str_contains($migration, 'uq_peo_source_party')
    && str_contains($economics, 'function placementEconomicsRecordObligation'));

$assert('economics API supports participant terms and PWP edits',
    str_contains($economicsApi, "'payment_terms','pwp_enabled','operating_cycle_id'")
    || (str_contains($economicsApi, 'placementEconomicsUpdateParty') && str_contains($economicsApi, 'operating_cycle_id')));
$assert('manual C2C vendor reuses the canonical corporate party identity',
    str_contains($economicsApi, "? 'corp:' . \$placementId")
    && str_contains($economicsApi, "'source_type' => \$role === 'c2c_vendor' ? 'corp' : 'manual'"));
$assert('manual C2C terms mirror to the corporate source and removal clears it',
    str_contains($economicsApi, 'payment_terms_override = :terms')
    && str_contains($economicsApi, 'DELETE FROM placement_corp_details'));
$assert('cycles can be created and assigned by purpose',
    str_contains($cyclesApi, "['billing','ap','payroll']")
    && str_contains($cyclesApi, "action === 'assign'"));
$assert('ordinary frequencies resolve to reusable internal schedules',
    str_contains($economics, 'function placementEconomicsEnsureStandardCycle')
    && str_contains($economics, "'standard_cadence'")
    && str_contains($economicsApi, 'placementEconomicsApiCadence'));
$assert('W2 and employee-like engagements resolve payroll rather than AP schedules',
    str_contains($economics, "['w2','temp_to_perm','internal']"));
$assert('unclassified non-employee labor defaults to the vendor/AP graph',
    str_contains($economics, ": 'ap';")
    && !str_contains($economics, "(\$engagement === '1099' ? 'ap' : 'none')"));
$assert('AP cycle defaults feed relationship terms and exclusions clear legacy fallbacks',
    str_contains($economics, '$apCycleTerms')
    && str_contains($cyclesApi, '$legacyMap')
    && str_contains($cyclesApi, '$updates[$legacyMap[$purpose]] = null'));

$assert('AP builds bills from all graph charges',
    str_contains($ap, 'placementEconomicsApCharges')
    && str_contains($ap, "'source' => 'staffing_economics'"));
$assert('W-2 placements can create secondary AP obligations without a contractor labor bill',
    str_contains($ap, "['_primary_ap_party']")
    && str_contains($ap, "array_filter(\$payable, static fn(array \$row): bool => !empty(\$row['_primary_ap_party']))"));
$assert('one-time placement obligations cannot rebill on a later extraction',
    str_contains($economics, "if (\$basis === 'one_time')")
    && str_contains($economics, 'status IN ("projected","billed","payroll","paid")'));
$assert('AP persists obligation-to-bill audit links',
    str_contains($apApi, 'placementEconomicsRecordObligation')
    && str_contains($apApi, "'ap_bill_id' => \$billId"));
$assert('AP voids the corresponding economic obligations',
    str_contains($apApi, 'UPDATE placement_economic_obligations')
    && str_contains($apApi, 'SET status = "void"'));
$assert('paid-when-paid links regardless of whether AR or AP is created first',
    str_contains($pwp, 'function apPwpAutoLinkForApBill')
    && str_contains($apApi, 'apPwpAutoLinkForApBill')
    && str_contains($settlementCreate, 'apPwpAutoLinkForArInvoice'));
$assert('placement PWP overrides become enforceable PWP terms',
    str_contains($economics, 'function placementEconomicsResolvedTerms')
    && str_contains($economics, "'PWP_' . \$normal"));
$assert('automatic AR settlement writes the canonical issue date and period',
    str_contains($settlementCreate, "'issue_date'")
    && !str_contains($settlementCreate, "'invoice_date'")
    && str_contains($settlementCreate, "'period_start'"));
$assert('AR invoice creation consumes and snapshots placement client terms',
    str_contains($economics, 'function placementEconomicsReceivableContract')
    && str_contains($settlementCreate, 'placementEconomicsReceivableContract')
    && str_contains($settlementCreate, "'payment_terms'     => \$receivable['payment_terms']")
    && !str_contains($settlementCreate, "strtotime('+30 days')")
    && substr_count($billing, 'placementEconomicsReceivableContract') >= 2
    && str_contains($billingApi, "'payment_terms'     => \$resolvedInvoiceTerms"));
$assert('time settlement prefers purpose-specific cycles',
    str_contains($settlement, 'placement.billing_operating_cycle_id')
    && str_contains($settlement, 'placement.ap_operating_cycle_id')
    && str_contains($settlement, 'placement.payroll_operating_cycle_id'));
$assert('time settlement routes only to economic participants assigned to each channel',
    str_contains($settlement, 'ep.settlement_channel = "ar"')
    && str_contains($settlement, 'ep.settlement_channel = "ap"')
    && str_contains($settlement, 'ep.settlement_channel = "payroll"'));
$assert('payroll-channel participants become commission earnings with obligation audit links',
    str_contains($settlementCreate, 'placementEconomicsPayrollCharges')
    && str_contains($settlementCreate, 'placementEconomicsRecordObligation')
    && str_contains($payrollLib, 'commission_cents')
    && str_contains($payrollCompute, "'code'=>'commission'")
    && str_contains($payrollRuns, 'placement_economic_obligations'));

$assert('JobDiva persists terms and PWP and reconciles economics',
    str_contains($jobdiva, 'jobdivaSyncPlacementEconomicOptions')
    && str_contains($jobdiva, 'client_payment_terms_override')
    && str_contains($jobdiva, 'vendor_payment_terms_override')
    && substr_count($jobdiva, 'placementEconomicsReconcile(') >= 2);
$assert('JobDiva does not invent vendor terms when its payload is silent',
    str_contains($jobdiva, "\$terms !== '' ? placementEconomicsNormaliseTerms(\$terms) : null")
    && str_contains($jobdiva, "\$pwp = \$pwpRaw === '' ? null"));
$assert('JobDiva projects vendor-chain economics and referrals into canonical source rows',
    str_contains($jobdiva, 'jobdivaSyncUpsertPlacementReferral')
    && str_contains($jobdiva, "'payment_terms_override' => \$terms")
    && str_contains($jobdiva, "'is_payable' => \$isPayable"));
$assert('Save and apply mapping reconciles the economic graph',
    str_contains($fieldApply, "\$integration === 'jobdiva' && \$entityType === 'placement'")
    && str_contains($fieldApply, 'placementEconomicsReconcile($tid, $placementId)'));
$assert('JobDiva mappings can write staffing payment terms and PWP controls',
    str_contains($fieldMap, 'client_payment_terms_override')
    && str_contains($fieldMap, 'vendor_payment_terms_override')
    && str_contains($fieldMap, "'payment_terms_override', 'pwp_enabled', 'is_payable'")
    && str_contains($fieldApply, "'vendor_pwp_enabled'")
    && str_contains($fieldApply, "'payment_terms_override' => true"));
$assert('field mapping can populate referral recipients, fees, terms, and PWP',
    str_contains($fieldMap, "'placement_referrals'")
    && str_contains($fieldApply, 'integrationFieldMapEnsurePlacementReferralRow')
    && str_contains($fieldApply, "'placement_referrals' => ['placement_referral']"));

$assert('placement UI presents one Contract workflow',
    str_contains($ui, "{ slug: 'economics',   label: 'Contract' }")
    && str_contains($ui, 'function EconomicsTab'));
$assert('economics screen normalizes companies without exposing schedule machinery',
    str_contains($ui, '<CompanyTypeahead')
    && str_contains($ui, 'Commercial terms by participant')
    && str_contains($ui, '>Billing / payment frequency</th>')
    && str_contains($ui, '>Payment terms</th>')
    && !str_contains($ui, '<h4>Settlement cycles</h4>')
    && !str_contains($ui, "api.post('/modules/placements/api/cycles.php'"));
$assert('paid when paid is an AP term rather than a separate contract switch',
    str_contains($ui, "term === 'PWP' ? 'Paid when paid'")
    && !str_contains($ui, 'Paid when paid for ${party.display_name}'));
$assert('economics screen can add company, person, or internal-user recipients',
    str_contains($ui, '<option value="company">Company or vendor</option>')
    && str_contains($ui, '<option value="person">Person</option>')
    && str_contains($ui, '<option value="user">Internal user</option>'));
$assert('participant editor enforces one company bill-to and correct AP/payroll identities',
    str_contains($economicsApi, 'This placement already has a bill-to client')
    && str_contains($economicsApi, 'Companies are paid through accounts payable')
    && str_contains($economicsApi, 'Internal users are paid through payroll'));
$assert('economics screen exposes readiness blockers and manual participant removal',
    str_contains($ui, 'missing_c2c_vendor')
    && str_contains($ui, 'missing_ar_payment_terms')
    && str_contains($ui, 'multiple_receivable_parties')
    && str_contains($ui, 'multiple_labor_payees')
    && str_contains($ui, 'removeParty(party)'));
$assert('placement activation is gated by complete economic readiness',
    str_contains($placementsApi, 'placement.activation_blocked_economic_setup')
    && str_contains($placementsApi, 'Placement economic setup incomplete'));
$assert('legacy fragmented URLs redirect to Economics',
    str_contains($ui, '<Route path="chain"      element={<Navigate to="../economics" replace />} />')
    && str_contains($ui, '<Route path="cycles"     element={<Navigate to="../economics" replace />} />'));

$failed = array_filter($checks, static fn(array $row): bool => !$row[1]);
exit($failed ? 1 : 0);
