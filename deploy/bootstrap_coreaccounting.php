<?php
/** Provision the canonical CoreFlux schema on an empty, isolated staging database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || (!in_array('--inspect', $argv, true) && !in_array('--confirm-empty-staging', $argv, true))) {
    fwrite(STDERR, "Staging CLI only. Use --inspect or --confirm-empty-staging --database=NAME.\n");
    exit(2);
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/migrate.php';
require_once __DIR__ . '/../core/installer_helpers.php';

$pdo = getDB();
if (!$pdo) throw new RuntimeException('The isolated staging database is unavailable.');
$connectedDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$existing = (int) $pdo->query(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
)->fetchColumn();
if (in_array('--inspect', $argv, true)) {
    echo json_encode(['database' => $connectedDatabase, 'existing_tables' => $existing,
        'empty' => $existing === 0], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}
$databaseArg = array_values(array_filter($argv, static fn(string $arg): bool => str_starts_with($arg, '--database=')));
$expectedDatabase = count($databaseArg) === 1 ? substr($databaseArg[0], strlen('--database=')) : '';
if ($expectedDatabase === '' || $expectedDatabase !== $connectedDatabase) {
    throw new RuntimeException('Connected database does not match the required --database=NAME argument.');
}
if ($existing !== 0) {
    throw new RuntimeException('Database is not empty; refusing clean-install bootstrap.');
}

$root = dirname(__DIR__);
$prerequisites = [
    'deploy/tenants_base.sql',
    'core/migrations/013_user_tenants_baseline.sql',
    'modules/people/migrations/001_init.sql',
    'modules/placements/migrations/001_init.sql',
    'modules/time/migrations/001_init.sql',
    'modules/staffing/migrations/001_timesheets.sql',
];
$statementsByFile = [];
foreach ($prerequisites as $relative) {
    $sql = file_get_contents($root . '/' . $relative);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Bootstrap prerequisite is missing or empty: ' . $relative);
    }
    $statementsByFile[$relative] = coreflux_split_sql_statements($sql);
    if (!$statementsByFile[$relative]) {
        throw new RuntimeException('Bootstrap prerequisite has no SQL statements: ' . $relative);
    }
}
foreach ($statementsByFile as $relative => $statements) {
    foreach ($statements as $statement) {
        $result = $pdo->query($statement);
        if ($result) $result->closeCursor();
    }
    echo "Applied prerequisite $relative.\n";
}

$migration = coreflux_run_migrations(true);
if ($migration['errors']) {
    throw new RuntimeException('Canonical migrations reported errors: '
        . implode('; ', array_slice($migration['errors'], 0, 5)));
}
$missing = installerCheckBaseSchema($pdo);
if ($missing) throw new RuntimeException('Base tables still missing: ' . implode(', ', $missing));

$required = [
    'accounting_accounts', 'accounting_journal_entries', 'accounting_journal_entry_lines',
    'billing_invoices', 'billing_payments', 'ap_bills', 'accounting_bank_statement_lines',
];
$placeholders = implode(',', array_fill(0, count($required), '?'));
$stmt = $pdo->prepare(
    "SELECT table_name FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name IN ($placeholders)"
);
$stmt->execute($required);
$missing = array_values(array_diff($required, $stmt->fetchAll(PDO::FETCH_COLUMN)));
if ($missing) throw new RuntimeException('Accounting module tables missing: ' . implode(', ', $missing));

echo json_encode(['database' => $connectedDatabase, 'baseline_applied' => count($prerequisites),
    'canonical_migrations_applied' => count($migration['applied_files']),
    'required_tables_verified' => count($required), 'status' => 'schema_ready'], JSON_PRETTY_PRINT) . "\n";
