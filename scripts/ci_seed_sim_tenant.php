<?php
/** Bootstrap a production-shaped MySQL schema for the live business simulation. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../core/migrate.php';

$pdo = getDB();
if (!$pdo) {
    fwrite(STDERR, 'Database unavailable: ' . (getDBLastError() ?? 'unknown') . PHP_EOL);
    exit(2);
}

/**
 * Apply one canonical migration file and fail on the first real error.
 *
 * The production migration ledger upgrades an established installation and
 * includes historical patches whose prerequisite tables predate this repo.
 * The live simulation therefore builds the exact production surfaces it
 * exercises, then applies the current hardening migrations on top.
 */
function ciApplySimulationSql(PDO $pdo, string $relativePath): void
{
    $root = dirname(__DIR__);
    $path = $root . '/' . ltrim($relativePath, '/');
    $sql = is_file($path) ? (string) file_get_contents($path) : '';
    if ($sql === '') throw new RuntimeException("Simulation schema file is missing or empty: {$relativePath}");

    foreach (coreflux_split_sql_statements($sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') continue;
        try {
            $result = $pdo->query($statement);
            if ($result) {
                $result->closeCursor();
                try {
                    while ($result->nextRowset()) { /* drain prepared-statement results */ }
                } catch (Throwable $_) {
                    // Some PDO drivers do not expose additional result sets.
                }
            }
        } catch (Throwable $e) {
            throw new RuntimeException("{$relativePath} failed: {$e->getMessage()}", 0, $e);
        }
    }
}

$schemaFiles = [
    'sql/layer_sandbox_seed.sql',
    'core/migrations/007_subtenant_provisioning.sql',
    'core/migrations/003_mail_service.sql',
    'modules/accounting/migrations/001_init.sql',
    'modules/accounting/migrations/002_phase2.sql',
    'modules/accounting/migrations/006_intercompany.sql',
    'modules/accounting/migrations/009_dimensions_and_close.sql',
    'modules/accounting/migrations/012_account_extensions.sql',
    'modules/accounting/migrations/015_accounting_events.sql',
    'modules/accounting/migrations/016_posting_rules.sql',
    'modules/accounting/migrations/017_journal_templates.sql',
    'modules/accounting/migrations/018_subledger_links.sql',
    'modules/accounting/migrations/019_journal_template_line_source.sql',
    'modules/accounting/migrations/025_journal_entry_source_module_varchar.sql',
    'modules/accounting/migrations/028_journal_line_tenant_scope.sql',
    'core/migrations/024_auto_reversing_accruals.sql',
    'core/migrations/097_audit_log_event_column.sql',
    'modules/ap/migrations/001_init.sql',
    'modules/billing/migrations/001_init.sql',
    'modules/billing/migrations/007_line_item_types.sql',
    'modules/billing/migrations/009_dunning.sql',
    'modules/billing/migrations/012_economic_item_source.sql',
    'modules/billing/migrations/013_item_catalog.sql',
    'modules/people/migrations/003_spec_alignment.sql',
    'modules/accounting/migrations/007_consolidation.sql',
    'modules/accounting/migrations/008_consolidation_runs.sql',
    'modules/placements/migrations/001_init.sql',
    'core/migrations/071_jobdiva_placement_metadata.sql',
    'modules/placements/migrations/002_cycle_config.sql',
    'modules/placements/migrations/002_cycles.sql',
    'modules/people/migrations/004_companies.sql',
    'modules/people/migrations/006_unify_and_extend.sql',
    'modules/staffing/migrations/003_clients.sql',
    'modules/staffing/migrations/005_company_bridge.sql',
    'modules/staffing/migrations/006_jobs.sql',
    'modules/time/migrations/001_init.sql',
    'modules/time/migrations/003_settlement.sql',
    'modules/staffing/migrations/001_timesheets.sql',
    'modules/staffing/migrations/002_timesheet_id_on_entries.sql',
    'core/migrations/078_integration_writable_targets.sql',
    'modules/placements/migrations/006_assignment_dimensions.sql',
    'core/migrations/126_staffing_economic_graph.sql',
    'core/migrations/127_staffing_contract_terms.sql',
    'core/migrations/128_placement_commercial_contract.sql',
    'core/migrations/135_tenant_staffing_economics_defaults.sql',
    'core/migrations/136_c2c_overhead_load.sql',
    'core/migrations/138_placement_one_time_items.sql',
    'core/migrations/146_staffing_time_dimension_snapshots.sql',
    'core/migrations/147_staffing_timesheet_approval_columns.sql',
    'modules/payroll/migrations/001_init.sql',
    'core/migrations/036_event_registry.sql',
    'core/migrations/019_workflow_engine.sql',
    'core/migrations/043_simulation_harness.sql',
    'core/migrations/105_ai_phase1_tool_registry_and_artifact_layer.sql',
    'core/migrations/145_business_logic_hardening.sql',
    'modules/accounting/migrations/029_reconciliation_artifacts.sql',
    'modules/payroll/migrations/008_run_artifacts.sql',
    'core/migrations/096_csv_import_external_ids_wave2.sql',
    'core/migrations/132_bank_transaction_identity.sql',
];

try {
    foreach ($schemaFiles as $schemaFile) ciApplySimulationSql($pdo, $schemaFile);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(3);
}

$tenantId = (int) (getenv('SIM_TENANT_ID') ?: 999);
$pdo->prepare(
    'INSERT INTO tenants (id, name, status, is_simulation)
     VALUES (:id, :name, "active", 1)
     ON DUPLICATE KEY UPDATE name = VALUES(name), status = "active", is_simulation = 1'
)->execute(['id' => $tenantId, 'name' => 'CoreFlux CI Simulation']);

$pdo->prepare(
    'INSERT INTO accounting_entities
        (id, tenant_id, code, legal_name, country, base_currency, active)
     VALUES (1, :tenant_id, "SIM", "CoreFlux CI Simulation", "US", "USD", 1)
     ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id), code = VALUES(code),
         legal_name = VALUES(legal_name), active = 1'
)->execute(['tenant_id' => $tenantId]);

$pdo->prepare(
    'INSERT INTO accounting_fiscal_calendars
        (id, tenant_id, entity_id, name, calendar_type, start_date, end_date, period_count, is_default, active)
     VALUES (1, :tenant_id, 1, "Simulation calendar", "calendar_year", "2026-01-01", "2026-12-31", 12, 1, 1)
     ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id), entity_id = 1, active = 1'
)->execute(['tenant_id' => $tenantId]);

$pdo->prepare(
    'INSERT INTO accounting_periods
        (id, tenant_id, entity_id, calendar_id, period_number, start_date, end_date, status)
     VALUES (1, :tenant_id, 1, 1, 1, "2026-01-01", "2026-12-31", "open")
     ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id), entity_id = 1,
         start_date = VALUES(start_date), end_date = VALUES(end_date), status = "open"'
)->execute(['tenant_id' => $tenantId]);

require_once __DIR__ . '/../core/accounting/system_accounts.php';
require_once __DIR__ . '/../core/posting_engine/seed_defaults.php';
require_once __DIR__ . '/../core/seeds/event_registry_seed.php';
accountingSeedSystemAccounts($tenantId);
postingRulesSeedDefaults($tenantId);
eventRegistrySeedRun($pdo);

// Real tenants rename account labels. Posting must rely on stable codes.
$pdo->prepare(
    "UPDATE accounting_accounts
        SET name = CASE code
            WHEN '1100' THEN 'Trade receivables - custom label'
            WHEN '2000' THEN 'Vendor obligations - custom label'
            ELSE name END
      WHERE tenant_id = :tenant_id AND code IN ('1100', '2000')"
)->execute(['tenant_id' => $tenantId]);

echo "Simulation tenant {$tenantId} seeded with production business-graph schema, books, accounts, and posting rules." . PHP_EOL;
