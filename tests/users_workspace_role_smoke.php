<?php
declare(strict_types=1);

putenv('COREFLUX_ENV=');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../core/memberships.php';

$passes = 0;
$failures = 0;
$check = static function (string $name, bool $ok) use (&$passes, &$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $passes++ : $failures++;
};

$api = (string) file_get_contents(__DIR__ . '/../api/users.php');
$billApi = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bills.php');
$billUi = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillDetail.jsx');
$check('Role edit uses canonical membership change', str_contains($api, 'membershipChangeWorkspaceRole('));
$check('Role edit and profile update share a transaction', str_contains($api, '$pdo->beginTransaction();')
    && str_contains($api, '$pdo->commit();') && str_contains($api, '$pdo->rollBack();'));
$check('Admin cannot assign Tenant Admin', str_contains($api, "Only a tenant administrator can assign Tenant Admin"));
$check('Bill detail reports actual approval eligibility', str_contains($billApi, "'approval' => \$approval")
    && str_contains($billUi, 'Boolean(data.approval?.can_approve)'));

if (!extension_loaded('pdo_sqlite')) {
    echo 'SKIP SQLite role transition checks (pdo_sqlite unavailable)' . PHP_EOL;
    echo "pass={$passes} fail={$failures}" . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE tenant_memberships (
    id INTEGER PRIMARY KEY, user_id INTEGER, tenant_id INTEGER, persona_type TEXT,
    persona_label TEXT, is_primary INTEGER, status TEXT, linked_entity_type TEXT,
    linked_entity_id INTEGER, updated_at TEXT
)");
$pdo->exec("CREATE TABLE user_tenants (
    id INTEGER PRIMARY KEY, user_id INTEGER, tenant_id INTEGER, role TEXT,
    is_default INTEGER, status TEXT, updated_at TEXT
)");
$pdo->exec("CREATE TABLE membership_module_access (
    membership_id INTEGER, module_key TEXT, access_level TEXT,
    granted_by_user_id INTEGER, PRIMARY KEY (membership_id, module_key)
)");
$pdo->exec("INSERT INTO tenant_memberships VALUES
    (10, 2, 1, 'admin', 'Primary', 1, 'active', 'person', 42, NULL),
    (11, 2, 2, 'employee', 'Primary', 1, 'active', 'person', 42, NULL),
    (12, 3, 1, 'employee', 'Primary', 1, 'active', NULL, NULL, NULL)");
$pdo->exec("INSERT INTO user_tenants VALUES
    (20, 2, 1, 'admin', 1, 'active', NULL),
    (21, 2, 2, 'employee', 1, 'active', NULL),
    (22, 3, 1, 'employee', 1, 'active', NULL)");
$pdo->exec("INSERT INTO membership_module_access VALUES
    (10, 'ap', 'read', 1), (10, 'custom', 'write', 1), (11, 'ap', 'read', 1)");
$labels = membershipRoleLabelsForTenant(1, $pdo);
$check('Workspace role labels are tenant-scoped', ($labels[2] ?? null) === 'admin'
    && ($labels[3] ?? null) === 'employee' && count($labels) === 2);

$changed = membershipChangeWorkspaceRole(2, 1, 'tenant_admin', 1, $pdo);
$row = $pdo->query('SELECT persona_type, linked_entity_type, linked_entity_id FROM tenant_memberships WHERE id = 10')
    ->fetch(PDO::FETCH_ASSOC);
$check('Active membership role changes', $changed && $row['persona_type'] === 'tenant_admin');
$check('Linked person survives role change', $row['linked_entity_type'] === 'person'
    && (int) $row['linked_entity_id'] === 42);
$check('Legacy role stays aligned', $pdo->query('SELECT role FROM user_tenants WHERE id = 20')->fetchColumn() === 'tenant_admin');
$check('Role defaults replace stale and custom grants',
    $pdo->query("SELECT access_level FROM membership_module_access WHERE membership_id = 10 AND module_key = 'ap'")->fetchColumn() === 'admin'
    && (int) $pdo->query("SELECT COUNT(*) FROM membership_module_access WHERE membership_id = 10 AND module_key = 'custom'")->fetchColumn() === 0);
$check('Other tenant and user stay unchanged',
    $pdo->query('SELECT persona_type FROM tenant_memberships WHERE id = 11')->fetchColumn() === 'employee'
    && $pdo->query('SELECT persona_type FROM tenant_memberships WHERE id = 12')->fetchColumn() === 'employee');

$pdo->exec("UPDATE membership_module_access SET access_level = 'read' WHERE membership_id = 10 AND module_key = 'ap'");
$check('No-op role edit preserves customized grants',
    !membershipChangeWorkspaceRole(2, 1, 'tenant_admin', 1, $pdo)
    && $pdo->query("SELECT access_level FROM membership_module_access WHERE membership_id = 10 AND module_key = 'ap'")->fetchColumn() === 'read');

membershipChangeWorkspaceRole(2, 1, 'manager', 1, $pdo);
$check('Downgrade resets access to manager defaults',
    $pdo->query("SELECT access_level FROM membership_module_access WHERE membership_id = 10 AND module_key = 'ap'")->fetchColumn() === 'write'
    && $pdo->query("SELECT access_level FROM membership_module_access WHERE membership_id = 10 AND module_key = 'ai'")->fetchColumn() === 'none');

$pdo->exec("INSERT INTO tenant_memberships VALUES
    (13, 2, 1, 'employee', 'Secondary', 0, 'active', NULL, NULL, NULL)");
try {
    membershipChangeWorkspaceRole(2, 1, 'tenant_admin', 1, $pdo);
    $ambiguousBlocked = false;
} catch (DomainException $e) {
    $ambiguousBlocked = str_contains($e->getMessage(), 'single active workspace role');
}
$check('Multiple active personas require explicit membership edit', $ambiguousBlocked);
$check('Ambiguous edit leaves both roles unchanged',
    $pdo->query('SELECT persona_type FROM tenant_memberships WHERE id = 10')->fetchColumn() === 'manager'
    && $pdo->query('SELECT persona_type FROM tenant_memberships WHERE id = 13')->fetchColumn() === 'employee');

$pdo->exec('DELETE FROM tenant_memberships WHERE id = 13');
$pdo->beginTransaction();
membershipChangeWorkspaceRole(2, 1, 'admin', 1, $pdo);
$check('Role helper respects the surrounding transaction', $pdo->inTransaction());
$pdo->rollBack();
$check('Outer rollback restores both membership roles',
    $pdo->query('SELECT persona_type FROM tenant_memberships WHERE id = 10')->fetchColumn() === 'manager'
    && $pdo->query('SELECT role FROM user_tenants WHERE id = 20')->fetchColumn() === 'manager');

echo "pass={$passes} fail={$failures}" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
