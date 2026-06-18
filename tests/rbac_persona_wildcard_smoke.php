<?php
/**
 * Smoke — RBAC persona-type wildcard for master_admin / tenant_admin.
 *
 * Symptom that triggered this smoke:
 *   Operators with master_admin / tenant_admin memberships were locked
 *   out of every action that runs through `api_can($module, $action)`
 *   (timesheets, journal entries, etc) because:
 *     1. Migration 055 created their `tenant_memberships` rows with
 *        `persona_type='master_admin'`/`'tenant_admin'`.
 *     2. No corresponding `membership_module_access` rows were backfilled
 *        for those admin personas (they were historically wildcard via
 *        role, not per-module).
 *     3. `RBACResolver::can()` saw the membership existed (so skipped
 *        the legacy fallback) but found no module access row → denied.
 *
 * The fix wires a `persona_type ∈ {master_admin, tenant_admin}` shortcut
 * in `can()` that bypasses the module-access lookup. Sub-tenant scope
 * is intentionally NOT enforced for these personas — they're tenant-wide
 * by definition.
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nRBAC persona-type wildcard smoke\n";
echo "==================================\n\n";

// ─── Source-level check ───
echo "── /app/core/rbac/permissions.php ──\n";
$src = (string) file_get_contents('/app/core/rbac/permissions.php');
check('persona_type wildcard shortcut declared',
    str_contains($src, "in_array(\$personaType, ['master_admin', 'tenant_admin'], true)"));
check('shortcut returns true (grants everything)',
    preg_match("/in_array\\(\\\$personaType, \\['master_admin', 'tenant_admin'\\], true\\)\\s*\\)\\s*\\{\\s*return true;/", $src) === 1);
check('shortcut runs AFTER membership-exists check',
    strpos($src, "self::activeMembership(\$userId, \$tenantId, \$personaId)") <
    strpos($src, "in_array(\$personaType, ['master_admin', 'tenant_admin'], true)"));
check('shortcut runs BEFORE moduleAccessFor lookup',
    strpos($src, "in_array(\$personaType, ['master_admin', 'tenant_admin'], true)") <
    strpos($src, "moduleAccessFor((int) \$membership['id'], \$module)"));

// ─── Live exercise ───
echo "\n── live resolver behaviour ──\n";

// Set up our SQLite mirror BEFORE pulling in the resolver, so the
// require_once at the top of permissions.php (which pulls core/db.php
// → declares getDB()) honours our stub by way of function precedence.
$GLOBALS['pdo'] = new \PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo = $GLOBALS['pdo'];

$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, is_global_admin INT DEFAULT 0, role TEXT)");
$pdo->exec("CREATE TABLE user_tenants (user_id INT, tenant_id INT, role TEXT, status TEXT DEFAULT 'active')");
$pdo->exec("CREATE TABLE tenant_memberships (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, tenant_id INT,
    persona_label TEXT, persona_type TEXT,
    linked_entity_type TEXT, linked_entity_id INT,
    is_primary INT DEFAULT 0, status TEXT DEFAULT 'active', last_active_at TEXT)");
$pdo->exec("CREATE TABLE membership_module_access (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    membership_id INT, module_key TEXT, access_level TEXT,
    sub_tenant_scope TEXT)");
$pdo->exec("CREATE TABLE membership_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, membership_id INT,
    action TEXT, actor_user_id INT, target_user_id INT, detail TEXT)");

// Three users, all members of tenant 101:
//  u=1 : master_admin (no module access rows)        ← regression target
//  u=2 : tenant_admin (no module access rows)        ← regression target
//  u=3 : employee with explicit staffing:read grant
//  u=4 : employee with NO grant                       ← should be denied
$pdo->exec("INSERT INTO users (id, role) VALUES (1, 'master_admin'), (2, 'tenant_admin'), (3, 'employee'), (4, 'employee')");
$pdo->exec("INSERT INTO user_tenants (user_id, tenant_id, role) VALUES (1,101,'master_admin'), (2,101,'tenant_admin'), (3,101,'employee'), (4,101,'employee')");
$pdo->exec("INSERT INTO tenant_memberships (user_id, tenant_id, persona_type, status) VALUES
    (1, 101, 'master_admin', 'active'),
    (2, 101, 'tenant_admin', 'active'),
    (3, 101, 'employee',     'active'),
    (4, 101, 'employee',     'active')");
// Only u=3 gets an explicit module grant.
$emp3MembershipId = (int) $pdo->query("SELECT id FROM tenant_memberships WHERE user_id=3")->fetchColumn();
$pdo->prepare("INSERT INTO membership_module_access (membership_id, module_key, access_level) VALUES (?, 'staffing', 'read')")
    ->execute([$emp3MembershipId]);

// Load the resolver. Strip its require_once of ../db.php (we have a
// stub registered already) so we don't trip "Cannot redeclare getDB".
if (!function_exists('getDB')) { function getDB(): \PDO { return $GLOBALS['pdo']; } }
$permSrc = (string) file_get_contents('/app/core/rbac/permissions.php');
$permSrc = preg_replace("/require_once __DIR__ \\. '\\/\\.\\.\\/db\\.php';/", '', $permSrc);
$permSrc = preg_replace('/^\s*<\?php/', '', $permSrc);
eval($permSrc);
RBACResolver::resetCache();

// master_admin should pass every check, regardless of module / action.
check('master_admin can read staffing',         RBACResolver::can(1, 101, 'staffing', 'read'));
check('master_admin can write staffing',        RBACResolver::can(1, 101, 'staffing', 'write'));
check('master_admin can admin accounting',      RBACResolver::can(1, 101, 'accounting', 'admin'));
check('master_admin can post journal entries',  RBACResolver::can(1, 101, 'accounting', 'write'));
check('master_admin can hit cfo module',         RBACResolver::can(1, 101, 'cfo', 'admin'));

// tenant_admin same shortcut.
check('tenant_admin can read timesheets',       RBACResolver::can(2, 101, 'staffing', 'read'));
check('tenant_admin can write timesheets',      RBACResolver::can(2, 101, 'staffing', 'write'));
check('tenant_admin can admin engagements',     RBACResolver::can(2, 101, 'engagements', 'admin'));

// employee with explicit grant.
check('employee with staffing:read grant can read',   RBACResolver::can(3, 101, 'staffing', 'read'));
check('employee with staffing:read grant cannot write', !RBACResolver::can(3, 101, 'staffing', 'write'));
check('employee with staffing grant cannot access engagements',
    !RBACResolver::can(3, 101, 'engagements', 'read'));

// employee WITHOUT any module grant.
check('employee with no grants denied staffing.read',  !RBACResolver::can(4, 101, 'staffing', 'read'));
check('employee with no grants denied accounting',     !RBACResolver::can(4, 101, 'accounting', 'read'));

// Cross-tenant safety: the legacy fallback in legacyRole() reads
// users.role as a last resort, which can grant a user.role='master_admin'
// access to a tenant they have no membership in. That's preserved
// legacy behaviour (matches how the system has always worked); not
// affected by our fix. So we ASSERT it (not deny it) — it documents
// the boundary clearly for future readers.
RBACResolver::resetCache();
check('master_admin in OTHER tenant falls through to users.role fallback (preserved legacy)',
    RBACResolver::can(1, 102, 'staffing', 'write'));

echo "\nrbac_persona_wildcard smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
