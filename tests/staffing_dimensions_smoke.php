<?php
/** Static contract smoke for assignment-owned staffing dimensions. */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    {$name}\n"; }
    else { $fail++; echo "  FAIL  {$name}\n"; }
};
$read = static fn(string $path): string => (string) file_get_contents($path);

echo "Assignment master\n";
$migration = $read(__DIR__ . '/../modules/placements/migrations/006_assignment_dimensions.sql');
foreach (['branch','service_line','workers_comp_class','department','cost_center','accounting_entity_id'] as $field) {
    $assert("migration adds {$field}", str_contains($migration, "COLUMN {$field}"));
}
$placementLib = $read(__DIR__ . '/../modules/placements/lib/placements.php');
$placementApi = $read(__DIR__ . '/../modules/placements/api/placements.php');
$csvImport = $read(__DIR__ . '/../modules/placements/api/csv_import.php');
$csvExport = $read(__DIR__ . '/../modules/placements/api/csv_export.php');
$jobdivaSync = $read(__DIR__ . '/../core/jobdiva/sync.php');
foreach (['branch','service_line','workers_comp_class','department','cost_center','accounting_entity_id','staffing_job_id'] as $field) {
    $assert("placement model exposes {$field}", str_contains($placementLib, "'{$field}'"));
    $assert("placement API writes {$field}", str_contains($placementApi, "'{$field}'"));
    $assert("placement CSV imports {$field}", str_contains($csvImport, "'{$field}'"));
    $assert("placement CSV exports {$field}", str_contains($csvExport, $field));
}

echo "\nDimension resolution and posting\n";
$dimensions = $read(__DIR__ . '/../modules/staffing/lib/dimensions.php');
foreach (['client','placement','worker','job','recruiter','account_manager','branch','service_line','work_state','wc_class','department','cost_center','legal_entity'] as $key) {
    $assert("resolver emits {$key}", str_contains($dimensions, "'{$key}' =>"));
}
$assert('resolver validates legal entity in accounting scope',
    str_contains($dimensions, "effectiveTenantIdForModule('accounting'")
    && str_contains($dimensions, 'FROM accounting_entities'));
$assert('resolver uses economic-party vendor graph',
    str_contains($dimensions, 'FROM placement_economic_parties')
    && str_contains($dimensions, "settlement_channel = 'ap'"));
$assert('vendor dimension is stable across placements',
    str_contains($dimensions, '$vendorDimension = $vendorCompanyId')
    && str_contains($dimensions, "'economic_party:' . \$vendorEconomicPartyId"));
$assert('assignment owners outrank commission-attribution fallbacks',
    str_contains($dimensions, "?: (trim((string) (\$placement['recruiter_name'] ?? '')) ?: (\$owners['recruiter'] ?? null))")
    && str_contains($dimensions, "?: (trim((string) (\$placement['account_manager_name'] ?? '')) ?: (\$owners['account_manager'] ?? null))"));

$timesheets = $read(__DIR__ . '/../modules/staffing/lib/timesheets.php');
$assert('approved-hour event is assignment-grained',
    str_contains($timesheets, "':placement:' . \$placementId")
    && str_contains($timesheets, 'te.placement_id, te.dimension_snapshot_hash'));
$assert('approved-hour event is dimension-versioned',
    str_contains($timesheets, "':segment:' . substr(\$snapshotHash, 0, 24)"));
$assert('event posts in accounting tenant',
    str_contains($timesheets, "effectiveTenantIdForModule('accounting'")
    && str_contains($timesheets, 'accountingProcessEvent($accountingTenantId'));
$assert('legacy posted bucket cannot double book',
    str_contains($timesheets, '$legacyPosted')
    && str_contains($timesheets, "AND status = 'posted'"));
$assert('event payload carries dimensions and vendor',
    str_contains($timesheets, "'dimensions'      => \$dimensionContext['dimensions']")
    && str_contains($timesheets, "'vendor_dimension'=> \$dimensionContext['vendor_dimension']"));
$assert('time approval preflights assignment completeness',
    str_contains($timesheets, 'Complete placement #{$placementId} before approving time')
    && str_contains($timesheets, 'staffingDimensionMissingLabels($missing)'));
$assert('reporting gaps are distinct from transaction blockers',
    str_contains($dimensions, "'reporting_gaps' => \$reportingGaps")
    && str_contains($dimensions, 'function staffingDimensionBlockingMissing'));
$assert('time accounting blocks only purpose-required dimensions',
    substr_count($timesheets, "staffingDimensionBlockingMissing(\$dimensionContext, 'time')") >= 2
    && str_contains($timesheets, 'missing dimensions required for time accounting'));

$engine = $read(__DIR__ . '/../core/posting_engine/process.php');
$assert('posting engine hands canonical dims to accounting',
    str_contains($engine, "'dims'        => array_replace("));
$assert('template dimension references are resolved',
    str_contains($engine, 'function postingEngineResolveDimensions')
    && str_contains($engine, 'formulaResolveRef($value, $context, false)'));

$seed = $read(__DIR__ . '/../modules/staffing/lib/posting_rules_seed.php');
$assert('seeder registers all staffing dimensions',
    str_contains($seed, '$dimensionDefinitions')
    && str_contains($seed, 'INSERT IGNORE INTO accounting_dimensions'));
$assert('vendor is scoped to AP-side template lines',
    substr_count($seed, "['vendor' => 'payload.vendor_dimension']") >= 4);

$billingPost = $read(__DIR__ . '/../modules/billing/api/invoices.php');
$assert('invoice revenue remains grouped by placement',
    str_contains($billingPost, 'GROUP BY item_type, gl_revenue_account_code, placement_id')
    && str_contains($billingPost, "'dims' => \$lineDimensions"));
$assert('invoice event carries line and document dimensions',
    str_contains($billingPost, "'dims'          => (array) (\$l['dims'] ?? [])")
    && str_contains($billingPost, "'dimensions'     => \$documentDimensions"));
$assert('placement invoice refuses incomplete assignment dimensions',
    str_contains($billingPost, 'Complete placement #{$placementId} before posting invoice')
    && str_contains($billingPost, "staffingDimensionBlockingMissing(")
    && str_contains($billingPost, 'accountingRequiredDimensionKeysForAccountCodes'));
$assert('placement invoice enforces the assignment client relationship',
    str_contains($billingPost, '$placementClientCompanyId')
    && str_contains($billingPost, 'is for a different client than placement'));
$assert('accrued invoice event uses reclassification lines',
    str_contains($billingPost, '$eventPostingLines = $reclassifyOnly ? $reclassLines : $lines')
    && str_contains($billingPost, "'posting_mode'   => \$reclassifyOnly ? 'ar_reclassification'"));

$apPost = $read(__DIR__ . '/../modules/ap/api/bills.php');
$assert('bill expense lines retain placement and vendor dimensions',
    str_contains($apPost, "\$lineDimensions['vendor'] = \$lineVendorDimension")
    && str_contains($apPost, "'dims'          => \$lineDimensions"));
$assert('manual bill liability retains canonical vendor dimension',
    str_contains($apPost, "'vendor' => \$documentVendorDimension")
    && str_contains($apPost, "!isset(\$documentDimensions['vendor'])"));
$assert('AP documents resolve and persist an issuing entity',
    str_contains($apPost, 'activeEntityResolveForTenant(')
    && str_contains($apPost, 'UPDATE ap_bills SET entity_id'));
$assert('billing documents repair legacy missing entity ownership',
    str_contains($billingPost, 'activeEntityResolveForTenant(')
    && str_contains($billingPost, 'UPDATE billing_invoices SET entity_id'));
$assert('bill posting separates input tax from direct cost',
    str_contains($apPost, "'debit'        => (float) \$bl['subtotal']")
    && str_contains($apPost, "'account_code' => '1310'"));
$assert('placement bill refuses incomplete assignment dimensions',
    str_contains($apPost, 'Complete placement #{$placementId} before posting bill')
    && str_contains($apPost, "staffingDimensionBlockingMissing("));
$assert('AP assignment vendor is required only by the expense account',
    str_contains($apPost, '$placementRequiresAssignmentVendor')
    && str_contains($apPost, "in_array('vendor', \$accountRequiredDimensions[\$acct], true)")
    && str_contains($apPost, "(\$documentDimensions['vendor'] ?? null)"));
$assert('accrued bill event uses reclassification lines',
    str_contains($apPost, '$eventPostingLines = $reclassifyOnly ? $reclassLines : $payloadLines')
    && str_contains($apPost, "'posting_mode' => \$reclassifyOnly ? 'ap_reclassification'"));
$assert('manual AP bills never clear time accruals merely because the feature is enabled',
    str_contains($apPost, '$allLinesWereAccrued')
    && str_contains($apPost, "['time', 'time_entry', 'economic_item']")
    && str_contains($apPost, '!empty($settings[\'multi_period_split_enabled\']) && $allLinesWereAccrued'));

$apLib = $read(__DIR__ . '/../modules/ap/lib/ap.php');
$defaultSeed = $read(__DIR__ . '/../core/posting_engine/seed_defaults.php');
$assert('AP cash clearing inherits vendor and legal entity',
    str_contains($apLib, "'vendor_dimension' => \$posting['vendor_dimension']")
    && str_contains($apLib, "'legal_entity_dimension' => \$posting['legal_entity_dimension']")
    && str_contains($defaultSeed, "['vendor' => 'payload.vendor_dimension', 'legal_entity' => 'payload.legal_entity_dimension']"));
$assert('AR cash clearing template retains client and legal entity',
    str_contains($defaultSeed, "['client' => 'payload.client_dimension', 'legal_entity' => 'payload.legal_entity_dimension']")
    && str_contains($defaultSeed, 'backfillLineDimensions'));

$multiPeriod = $read(__DIR__ . '/../modules/accounting/lib/multi_period.php');
$assert('bundle accrual resolves assignment dimensions',
    str_contains($multiPeriod, 'staffingAssignmentDimensionContext(')
    && substr_count($multiPeriod, "'dims' => \$assignmentDimensions") >= 4);
$assert('bundle accrual posts to assignment legal entity',
    str_contains($multiPeriod, "'entity_id'       => \$entityId")
    && str_contains($multiPeriod, 'needs a legal entity before its accrual can post'));
$assert('bundle accrual refuses incomplete assignment dimensions',
    str_contains($multiPeriod, 'before posting its accrual: missing')
    && str_contains($multiPeriod, 'staffingDimensionBlockingMissing'));
$assert('control accounts receive safe default dimension rules',
    str_contains($seed, '$controlDimensionRules')
    && str_contains($seed, "'1100' => ['client', 'legal_entity']")
    && str_contains($seed, "'2000' => ['vendor', 'legal_entity']")
    && str_contains($seed, "'1500' => ['legal_entity', 'counterparty_entity']"));
$assert('assignment-specific accounts enforce their intrinsic dimensions',
    str_contains($seed, "'4010' => ['client', 'placement', 'worker', 'legal_entity']")
    && str_contains($seed, "'4020' => ['client', 'placement', 'recruiter', 'legal_entity']")
    && str_contains($seed, "'5010' => ['placement', 'vendor', 'legal_entity']")
    && str_contains($seed, "'5050' => ['placement', 'worker', 'wc_class', 'legal_entity']")
    && str_contains($seed, "'5070' => ['placement', 'vendor', 'legal_entity']"));
$assert('ordinary AP lines do not silently become direct labor',
    str_contains($apPost, "?: '6990'")
    && str_contains($apPost, 'A blank coding choice is an exception to resolve, not direct labor.'));
$assert('manual AP vendors receive a durable reporting identity',
    str_contains($apPost, 'INSERT INTO ap_vendors_index')
    && str_contains($apPost, "'ap_vendor:' . (int) \$vendorRow['id']"));

echo "\nReporting and integrity\n";
$view = $read(__DIR__ . '/../modules/time/migrations/010_referral_costs_view.sql');
$assert('profitability view uses billable and payable flags',
    str_contains($view, 'te.billable = 1') && str_contains($view, 'te.payable <> 1'));
$assert('profitability view includes recurring burden',
    str_contains($view, 'pr.workers_comp_pct')
    && str_contains($view, 'pr.benefits_load_pct')
    && str_contains($view, 'pr.c2c_overhead_pct'));
$integrity = $read(__DIR__ . '/../core/business_integrity.php');
foreach (['active_referral_vendor_linkage','active_placement_payable_parties','active_contractor_vendor_linkage','active_placement_dimension_coverage'] as $check) {
    $assert("integrity audit includes {$check}", str_contains($integrity, "'{$check}'"));
}
$assert('dimension integrity flags assignments without workers',
    str_contains($integrity, "IF(COALESCE(p.person_id, 0) = 0, 'worker', NULL)"));

echo "\nAPI and UI contract\n";
$assignmentDimensionsApi = $read(__DIR__ . '/../modules/staffing/api/assignment_dimensions.php');
$journalEditor = $read(__DIR__ . '/../modules/accounting/ui/JournalEntryCreate.jsx');
$assert('manual accounting can resolve dimensions from one assignment',
    str_contains($assignmentDimensionsApi, 'staffingAssignmentDimensionContext(')
    && str_contains($assignmentDimensionsApi, "'dimensions' => \$dimensions")
    && str_contains($assignmentDimensionsApi, "'vendor_dimension' => \$context['vendor_dimension']")
    && str_contains($assignmentDimensionsApi, "'entity_matches_requested'"));
$assert('journal assignment picker inherits context instead of re-keying it',
    str_contains($journalEditor, 'PlacementPicker')
    && str_contains($journalEditor, 'applyAssignmentDimensions')
    && str_contains($journalEditor, '/modules/staffing/api/assignment_dimensions.php')
    && str_contains($journalEditor, 'refreshAssignmentDimensions'));
$assert('journal only inherits assignment vendor on payable accounts',
    str_contains($journalEditor, 'accountNeedsVendor')
    && str_contains($journalEditor, 'inherited.vendor = data.vendor_dimension')
    && str_contains($journalEditor, 'relevantAssignmentMissing'));
$assert('journal blocks assignments owned by another entity',
    str_contains($journalEditor, 'accounting-je-assignment-entity-error')
    && str_contains($journalEditor, 'assignmentsMatchEntity'));
$schema = $read(__DIR__ . '/../graphql/subgraph-coreflux/schema.graphql');
$shape = $read(__DIR__ . '/../graphql/subgraph-coreflux/src/index.ts');
foreach (['staffingJobId','branch','serviceLine','workersCompClass','department','costCenter','accountingEntityId'] as $field) {
    $assert("GraphQL exposes {$field}", str_contains($schema, $field . ':'));
    $assert("GraphQL maps {$field}", str_contains($shape, $field . ':'));
}
$createUi = $read(__DIR__ . '/../modules/placements/ui/PlacementCreate.jsx');
$detailUi = $read(__DIR__ . '/../modules/placements/ui/PlacementDetail.jsx');
$assert('create flow captures assignment reporting dimensions',
    str_contains($createUi, 'Assignment reporting')
    && str_contains($createUi, 'placement-create-legal-entity')
    && str_contains($createUi, 'placement-create-recruiter')
    && str_contains($createUi, 'placement-create-account-manager'));
$assert('detail flow shows and edits assignment reporting dimensions',
    str_contains($detailUi, 'tab-overview-section-dimensions')
    && str_contains($detailUi, 'placement-dimension-readiness')
    && str_contains($detailUi, 'overview-edit-accounting-entity-id')
    && str_contains($detailUi, 'overview-edit-recruiter')
    && str_contains($detailUi, 'overview-edit-account-manager'));
$assert('placement creation persists owner dimensions and a real legal entity',
    str_contains($placementApi, "'recruiter_email'")
    && str_contains($placementApi, "'account_manager_email'")
    && str_contains($placementApi, 'accountingDefaultEntity($accountingTenantId)'));
$assert('placement detail reports dimension gaps before downstream work begins',
    str_contains($placementApi, "'dimension_readiness' => \$dimensionReadiness")
    && str_contains($placementApi, "'reporting_gaps' => array_values(\$reportingGaps)")
    && str_contains($placementApi, "staffingDimensionBlockingMissing(\$dimensionContext, 'time')"));
$assert('new placement CSV rows inherit the default legal entity',
    str_contains($csvImport, '$defaultAssignmentEntityId')
    && str_contains($csvImport, "'accounting_entity_id' => \$rowEntityId"));
$assert('placement CSV preview validates job and legal entity references',
    str_contains($csvImport, '$validJobs')
    && str_contains($csvImport, '$validEntities')
    && str_contains($csvImport, 'is not an active legal entity in this accounting workspace'));
$accountingDimensions = $read(__DIR__ . '/../modules/accounting/lib/dimensions.php');
$recurringJe = $read(__DIR__ . '/../modules/accounting/lib/recurring_je.php');
$accountingImport = $read(__DIR__ . '/../modules/accounting/api/import.php');
$assert('manual and recurring journals use account-specific dimension requirements',
    str_contains($accountingDimensions, 'function accountingRequiredDimensionKeysForAccountCodes')
    && str_contains($recurringJe, 'staffingDimensionMissingForRequirements')
    && str_contains($accountingImport, 'staffingDimensionMissingForRequirements'));
$assert('new JobDiva assignments inherit the default legal entity',
    str_contains($jobdivaSync, "require_once __DIR__ . '/../../modules/accounting/lib/accounting.php'")
    && str_contains($jobdivaSync, 'vendor_pwp_enabled, accounting_entity_id)')
    && str_contains($jobdivaSync, "'aeid'  => \$resolveDefaultAccountingEntityId()"));
$assert('JobDiva sync only repairs missing assignment legal entities',
    str_contains($jobdivaSync, "'accounting_entity_id' => \$existingAccountingEntityId <= 0")
    && str_contains($jobdivaSync, "'accounting_entity_id' => ['aeid',  \$assignmentAccountingEntityId]"));

echo "\nDeployment contract\n";
$lightDeployPath = __DIR__ . '/../.github/workflows/deploy-light-workspace.yml';
if (is_file($lightDeployPath)) {
    $lightDeploy = str_replace("\r\n", "\n", $read($lightDeployPath));
    foreach ([
        'modules/accounting/migrations/030_staffing_system_account_map.sql',
        'modules/accounting/migrations/031_recurring_je_dimensions.sql',
        'modules/payroll/migrations/009_payroll_account_map_repair.sql',
        'modules/placements/migrations/005_referral_engagement_type.sql',
        'modules/placements/migrations/006_assignment_dimensions.sql',
        'modules/staffing/api/assignment_dimensions.php',
        'modules/staffing/lib/dimensions.php',
        'graphql/router/supergraph.graphql',
        'graphql/subgraph-coreflux/schema.graphql',
        'graphql/subgraph-coreflux/src/index.ts',
        'modules/time/migrations/010_referral_costs_view.sql',
        'modules/treasury/migrations/007_liability_account_entity.sql',
    ] as $releasePath) {
        $assert("release packages {$releasePath}", str_contains($lightDeploy, "            {$releasePath} \\\n"));
    }
    foreach ([
        'modules/accounting/migrations/030_staffing_system_account_map.sql',
        'modules/accounting/migrations/031_recurring_je_dimensions.sql',
        'modules/payroll/migrations/009_payroll_account_map_repair.sql',
        'modules/placements/migrations/005_referral_engagement_type.sql',
        'modules/placements/migrations/006_assignment_dimensions.sql',
        'modules/time/migrations/010_referral_costs_view.sql',
        'modules/treasury/migrations/007_liability_account_entity.sql',
    ] as $migrationPath) {
        $assert("release verifies {$migrationPath}", str_contains(
            $lightDeploy,
            "php deploy/run_migrations.php --status | grep -F '[ok] {$migrationPath}'"
        ));
    }
    $assert('production runs the assignment-dimension regression',
        str_contains($lightDeploy, 'php tests/staffing_dimensions_smoke.php'));
    $assert('production runs the treasury-dimension regression',
        str_contains($lightDeploy, 'php tests/treasury_dimension_integrity_smoke.php'));
}

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
