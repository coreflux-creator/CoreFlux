<?php
/** Bootstrap a fresh MySQL service for the nightly live simulation. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../core/migrate.php';

$pdo = getDB();
if (!$pdo) {
    fwrite(STDERR, 'Database unavailable: ' . (getDBLastError() ?? 'unknown') . PHP_EOL);
    exit(2);
}

// The migration set extends these platform roots but intentionally does not
// create them on an established install. CI starts from an empty database.
$bootstrap = (string) file_get_contents(__DIR__ . '/../sql/layer_sandbox_seed.sql');
foreach (coreflux_split_sql_statements($bootstrap) as $statement) {
    $statement = trim($statement);
    if ($statement !== '') $pdo->exec($statement);
}

$migrationStatus = coreflux_run_migrations(true);
if (!empty($migrationStatus['errors'])) {
    fwrite(STDERR, "Migration errors:\n" . implode("\n", $migrationStatus['errors']) . PHP_EOL);
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

echo "Simulation tenant {$tenantId} seeded with migrations, books, accounts, and posting rules." . PHP_EOL;
