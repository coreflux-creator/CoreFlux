<?php
/** Bootstrap a production-shaped MySQL schema for the live business simulation. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

$tenantOnly = in_array('--tenant-only', $argv, true);
$requireNew = in_array('--require-new', $argv, true);
if ($requireNew && !$tenantOnly) {
    fwrite(STDERR, "--require-new must be used with --tenant-only.\n");
    exit(2);
}
if ($tenantOnly && !in_array((string) getenv('COREFLUX_ENV'), ['staging', 'development', 'test', 'ci'], true)) {
    fwrite(STDERR, "--tenant-only requires an explicit non-production COREFLUX_ENV.\n");
    exit(2);
}
if ($tenantOnly && (!getenv('SIM_TENANT_ID') || (int) getenv('SIM_TENANT_ID') <= 0)) {
    fwrite(STDERR, "--tenant-only requires a positive SIM_TENANT_ID.\n");
    exit(2);
}

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
    'modules/ap/migrations/001_init.sql',
    'modules/billing/migrations/001_init.sql',
    'modules/accounting/migrations/007_consolidation.sql',
    'modules/accounting/migrations/008_consolidation_runs.sql',
    'modules/payroll/migrations/001_init.sql',
    'core/migrations/036_event_registry.sql',
    'core/migrations/043_simulation_harness.sql',
    'core/migrations/105_ai_phase1_tool_registry_and_artifact_layer.sql',
    'core/migrations/145_business_logic_hardening.sql',
    'modules/accounting/migrations/029_reconciliation_artifacts.sql',
    'modules/payroll/migrations/008_run_artifacts.sql',
];

if (!$tenantOnly) {
    try {
        foreach ($schemaFiles as $schemaFile) ciApplySimulationSql($pdo, $schemaFile);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(3);
    }
}

$tenantId = (int) (getenv('SIM_TENANT_ID') ?: 999);
if ($tenantId <= 0) {
    fwrite(STDERR, "SIM_TENANT_ID must be positive.\n");
    exit(2);
}

try {
    $pdo->beginTransaction();
    $tenant = $pdo->prepare('SELECT is_simulation, status FROM tenants WHERE id = :id FOR UPDATE');
    $tenant->execute(['id' => $tenantId]);
    $existingTenant = $tenant->fetch(PDO::FETCH_ASSOC);
    if ($existingTenant) {
        if ($requireNew) throw new RuntimeException("Tenant {$tenantId} already exists; choose an unused simulation ID");
        if ((int) $existingTenant['is_simulation'] !== 1 || $existingTenant['status'] !== 'active') {
            throw new RuntimeException("Tenant {$tenantId} exists but is not an active simulation tenant");
        }
    } else {
        $pdo->prepare(
            'INSERT INTO tenants (id, name, status, is_simulation)
             VALUES (:id, :name, "active", 1)'
        )->execute(['id' => $tenantId, 'name' => "CoreFlux CI Simulation {$tenantId}"]);
    }

    $entity = $pdo->prepare('SELECT id, active FROM accounting_entities WHERE tenant_id = :tenant_id AND code = "SIM" FOR UPDATE');
    $entity->execute(['tenant_id' => $tenantId]);
    $existingEntity = $entity->fetch(PDO::FETCH_ASSOC);
    if ($existingEntity && (int) $existingEntity['active'] !== 1) {
        throw new RuntimeException("Simulation entity is inactive for tenant {$tenantId}");
    }
    if (!$existingEntity) {
        $pdo->prepare(
            'INSERT INTO accounting_entities (tenant_id, code, legal_name, country, base_currency, active)
             VALUES (:tenant_id, "SIM", "CoreFlux CI Simulation", "US", "USD", 1)'
        )->execute(['tenant_id' => $tenantId]);
    }
    $entityId = $existingEntity ? (int) $existingEntity['id'] : (int) $pdo->lastInsertId();

    $calendar = $pdo->prepare(
        'SELECT id FROM accounting_fiscal_calendars
          WHERE tenant_id = :tenant_id AND entity_id = :entity_id
            AND name = "Simulation calendar" AND start_date = "2026-01-01" AND end_date = "2026-12-31"
          ORDER BY id LIMIT 1 FOR UPDATE'
    );
    $calendar->execute(['tenant_id' => $tenantId, 'entity_id' => $entityId]);
    $calendarId = (int) $calendar->fetchColumn();
    if ($calendarId <= 0) {
        $pdo->prepare(
            'INSERT INTO accounting_fiscal_calendars
                (tenant_id, entity_id, name, calendar_type, start_date, end_date, period_count, is_default, active)
             VALUES (:tenant_id, :entity_id, "Simulation calendar", "calendar_year", "2026-01-01", "2026-12-31", 12, 1, 1)'
        )->execute(['tenant_id' => $tenantId, 'entity_id' => $entityId]);
        $calendarId = (int) $pdo->lastInsertId();
    }

    $period = $pdo->prepare(
        'SELECT id, end_date, status FROM accounting_periods
          WHERE tenant_id = :tenant_id AND entity_id = :entity_id
            AND period_number = 1 AND start_date = "2026-01-01" FOR UPDATE'
    );
    $period->execute(['tenant_id' => $tenantId, 'entity_id' => $entityId]);
    $existingPeriod = $period->fetch(PDO::FETCH_ASSOC);
    if ($existingPeriod) {
        if ($existingPeriod['end_date'] !== '2026-12-31' || $existingPeriod['status'] !== 'open') {
            throw new RuntimeException("Simulation period already exists with different dates or status for tenant {$tenantId}");
        }
    } else {
        $pdo->prepare(
            'INSERT INTO accounting_periods
                (tenant_id, entity_id, calendar_id, period_number, start_date, end_date, status)
             VALUES (:tenant_id, :entity_id, :calendar_id, 1, "2026-01-01", "2026-12-31", "open")'
        )->execute(['tenant_id' => $tenantId, 'entity_id' => $entityId, 'calendar_id' => $calendarId]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(4);
}

require_once __DIR__ . '/../core/accounting/system_accounts.php';
require_once __DIR__ . '/../core/posting_engine/seed_defaults.php';
require_once __DIR__ . '/../core/seeds/event_registry_seed.php';
accountingSeedSystemAccounts($tenantId);
postingRulesSeedDefaults($tenantId);
eventRegistrySeedRun($pdo);

echo "Simulation tenant {$tenantId} seeded with entity {$entityId}, books, accounts, and posting rules." . PHP_EOL;
