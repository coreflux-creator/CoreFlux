<?php
/** Create the first tenant on an isolated, fully migrated staging database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !in_array('--confirm-first-tenant', $argv, true)) {
    fwrite(STDERR, "Staging CLI only. Use --confirm-first-tenant --database=NAME.\n");
    exit(2);
}

require_once __DIR__ . '/../core/memberships.php';
require_once __DIR__ . '/../core/installer_helpers.php';
require_once __DIR__ . '/../core/accounting/entity_setup.php';
require_once __DIR__ . '/../core/accounting/system_accounts.php';
require_once __DIR__ . '/../core/posting_engine/seed_defaults.php';
require_once __DIR__ . '/../core/seeds/event_registry_seed.php';
require_once __DIR__ . '/../modules/staffing/lib/posting_rules_seed.php';

$pdo = getDB();
if (!$pdo) throw new RuntimeException('The isolated staging database is unavailable.');

$databaseArgs = array_values(array_filter(
    $argv,
    static fn(string $arg): bool => str_starts_with($arg, '--database=')
));
$expectedDatabase = count($databaseArgs) === 1
    ? substr($databaseArgs[0], strlen('--database=')) : '';
$connectedDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($expectedDatabase === '' || $expectedDatabase !== $connectedDatabase) {
    throw new RuntimeException('Connected database does not match the required --database=NAME argument.');
}

$tenantName = trim((string) getenv('COREFLUX_INITIAL_TENANT_NAME'));
$adminName = trim((string) getenv('COREFLUX_INITIAL_ADMIN_NAME'));
$adminEmail = strtolower(trim((string) getenv('COREFLUX_INITIAL_ADMIN_EMAIL')));
$adminPassword = (string) getenv('COREFLUX_INITIAL_ADMIN_PASSWORD');
$slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($tenantName)), '-');
if ($tenantName === '' || strlen($tenantName) > 190 || $slug === '' || strlen($slug) > 190
    || $adminName === '' || strlen($adminName) > 150
    || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)
    || strlen($adminPassword) < 16) {
    throw new RuntimeException('Initial tenant name, admin name/email, and a 16+ character password are required in the environment.');
}
$entityProfile = accountingNewEntityProfile([
    'code' => 'MAIN',
    'legal_name' => (string) getenv('COREFLUX_INITIAL_ENTITY_LEGAL_NAME'),
    'country' => (string) getenv('COREFLUX_INITIAL_ENTITY_COUNTRY'),
    'base_currency' => (string) getenv('COREFLUX_INITIAL_ENTITY_BASE_CURRENCY'),
    'entity_type' => (string) getenv('COREFLUX_INITIAL_ENTITY_TYPE'),
    'accounting_basis' => (string) getenv('COREFLUX_INITIAL_ACCOUNTING_BASIS'),
    'fiscal_year_start_month' => (string) getenv('COREFLUX_INITIAL_FISCAL_YEAR_START_MONTH'),
]);
$year = accountingFirstFiscalYear((string) getenv('COREFLUX_INITIAL_FISCAL_YEAR'));

$missing = installerCheckBaseSchema($pdo);
if ($missing) throw new RuntimeException('Base schema is incomplete: ' . implode(', ', $missing));
$migrationRows = $pdo->query('SELECT filename, sha256, last_error FROM _migrations')
    ->fetchAll(PDO::FETCH_ASSOC);
$recordedMigrations = [];
foreach ($migrationRows as $row) {
    $recordedMigrations[$row['filename']] = $row;
}
$root = str_replace('\\', '/', dirname(__DIR__));
$migrationFiles = array_merge(
    glob($root . '/core/migrations/*.sql') ?: [],
    glob($root . '/modules/*/migrations/*.sql') ?: []
);
$missingOrChanged = [];
foreach ($migrationFiles as $file) {
    $file = str_replace('\\', '/', $file);
    if (preg_match('#/modules/_[^/]+/#', $file)) continue;
    $name = str_starts_with($file, $root . '/modules/')
        ? ltrim(substr($file, strlen($root)), '/') : basename($file);
    $hash = hash_file('sha256', $file);
    $recorded = $recordedMigrations[$name] ?? null;
    if ($hash === false || $recorded === null || $recorded['sha256'] !== $hash
        || $recorded['last_error'] !== null) {
        $missingOrChanged[] = $name;
    }
}
if (!$migrationFiles || $missingOrChanged) {
    throw new RuntimeException('Canonical migrations are missing, changed, or failed: '
        . implode(', ', array_slice($missingOrChanged, 0, 5)));
}

$lockName = 'coreacc:first:' . substr(hash('sha256', $connectedDatabase), 0, 40);
$lock = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
$lock->execute(['name' => $lockName]);
if ((int) $lock->fetchColumn() !== 1) {
    throw new RuntimeException('Another first-tenant provisioning run is active.');
}

try {
    $pdo->beginTransaction();
    $tenantCount = (int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($tenantCount !== 0 || $userCount !== 0) {
        throw new RuntimeException('Database already has a tenant or user; refusing first-tenant provisioning.');
    }

    $tenant = $pdo->prepare(
        'INSERT INTO tenants (name, slug, subdomain, status, is_active, is_simulation)
         VALUES (:name, :slug, "", "active", 1, 0)'
    );
    $tenant->execute(['name' => $tenantName, 'slug' => $slug]);
    $tenantId = (int) $pdo->lastInsertId();

    $user = $pdo->prepare(
        'INSERT INTO users (tenant_id, name, email, password_hash, role, is_active)
         VALUES (:tenant_id, :name, :email, :password_hash, "tenant_admin", 1)'
    );
    $user->execute([
        'tenant_id' => $tenantId,
        'name' => $adminName,
        'email' => $adminEmail,
        'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
    ]);
    $userId = (int) $pdo->lastInsertId();
    provisionMembership($userId, $tenantId, 'tenant_admin', [
        'is_primary' => true, 'status' => 'active',
    ]);

    $approvalPolicy = $pdo->prepare(
        'INSERT INTO ap_approval_policies
            (tenant_id, name, description, priority, chain_json, active)
         VALUES (:tenant_id, :name, :description, 100, :chain_json, 1)'
    );
    $approvalPolicy->execute([
        'tenant_id' => $tenantId,
        'name' => 'Initial finance approval',
        'description' => 'Route AP bills to another active tenant administrator until a reviewed policy replaces this one.',
        'chain_json' => json_encode([[
            'step' => 1,
            'include_active_tenant_admins' => true,
            'quorum' => 1,
            'label' => 'Finance approval',
        ]], JSON_THROW_ON_ERROR),
    ]);
    $approvalPolicyId = (int) $pdo->lastInsertId();

    $createdEntity = accountingCreateEntityWithCalendar($pdo, $tenantId, $entityProfile, $year);
    $entityId = $createdEntity['entity_id'];

    $accounts = accountingSeedSystemAccounts($tenantId);
    $rules = postingRulesSeedDefaults($tenantId);
    $staffingRules = staffingSeedPostingRules($tenantId);
    if (!($staffingRules['ok'] ?? false)) {
        throw new RuntimeException('Staffing posting-rule setup failed.');
    }
    $events = eventRegistrySeedRun($pdo);

    $pdo->commit();
    echo json_encode([
        'database' => $connectedDatabase,
        'tenant_id' => $tenantId,
        'admin_user_id' => $userId,
        'ap_approval_policy_id' => $approvalPolicyId,
        'entity_id' => $entityId,
        'entity_profile' => [
            'legal_name' => $entityProfile['legal_name'],
            'country' => $entityProfile['country'],
            'base_currency' => $entityProfile['base_currency'],
            'entity_type' => $entityProfile['entity_type'],
            'accounting_basis' => $entityProfile['accounting_basis'],
            'fiscal_year_start_month' => $entityProfile['fiscal_year_start_month'],
            'fiscal_year' => $year,
        ],
        'calendar_id' => $createdEntity['calendar_id'],
        'periods_created' => $createdEntity['periods_created'],
        'accounts' => $accounts,
        'rules' => $rules,
        'staffing_rules' => $staffingRules,
        'events' => $events,
        'status' => 'first_tenant_ready',
    ], JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
    $release->execute(['name' => $lockName]);
}
