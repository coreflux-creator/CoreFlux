<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || realpath(dirname(__DIR__)) !== '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html') {
    throw new RuntimeException('Isolated full-Core staging CLI only.');
}
require_once __DIR__ . '/../core/migrate.php';
require_once __DIR__ . '/../core/accounting/schema_contract.php';
require_once __DIR__ . '/../core/integrations/purepay_payroll_authority.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=999')->fetchColumn() !== 1) {
    throw new RuntimeException('Staging database identity mismatch.');
}
$migration = coreflux_run_migrations(true);
if ($migration['errors']) throw new RuntimeException(implode('; ', $migration['errors']));
$schema = coreAccountingInspectSchema($pdo);
if ($schema['missing'] || $schema['incompatible']) throw new RuntimeException('Installed accounting schema is incomplete.');
purepayPayrollAuthoritySchema($pdo);
echo json_encode(['migrations_applied' => $migration['applied_files'], 'schema' => 'ready',
    'payroll_source_guards' => 'ready', 'payroll_authority_enabled' => getenv('COREFLUX_PUREPAY_PAYROLL_AUTHORITY') === '1'], JSON_UNESCAPED_SLASHES), "\n";
