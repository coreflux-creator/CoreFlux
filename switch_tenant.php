<?php
/**
 * /switch_tenant.php — server-side tenant context switch.
 *
 * Used by the SPA Header tenant dropdown and the "Choose a tenant" picker
 * after login. Sets `$_SESSION['tenant_id']` (and a couple of legacy
 * aliases), stamps `user_tenants.last_active_at`, then redirects back to
 * the SPA so the next session.php fetch reflects the new tenant.
 *
 * Accepts: ?tenant_id=N (GET) or POST tenant_id field. Master_admin can
 * jump between any tenant; everyone else must have an active membership
 * (or be a tenant_admin of the parent master).
 */

declare(strict_types=1);

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/sub_tenants.php';

initSession();

if (!isAuthenticated()) {
    header('Location: /login.php');
    exit;
}

$user      = getCurrentUser();
$userId    = (int) ($user['id'] ?? 0);
$role      = $user['role'] ?? $_SESSION['role'] ?? 'employee';
$targetId  = (int) ($_REQUEST['tenant_id'] ?? 0);
$nextPath  = $_REQUEST['next'] ?? '/spa.php';

// Whitelist redirect target to local paths only.
if (!is_string($nextPath) || strncmp($nextPath, '/', 1) !== 0) {
    $nextPath = '/spa.php';
}

if (!$targetId) {
    header('Location: ' . $nextPath . '?error=missing_tenant');
    exit;
}

if (!_subTenantSwitchAllowed($userId, $targetId, $role)) {
    header('Location: ' . $nextPath . '?error=unauthorized_tenant');
    exit;
}

$t = subTenantLookup($targetId);
if (!$t || (int)($t['is_active'] ?? 1) !== 1) {
    header('Location: ' . $nextPath . '?error=tenant_inactive');
    exit;
}

$_SESSION['tenant_id']        = $targetId;
$_SESSION['active_tenant_id'] = $targetId;     // legacy compat
$_SESSION['tenant']           = $t['name'] ?? null;

subTenantTouchLastActive($userId, $targetId);

header('Location: ' . $nextPath);
exit;

function _subTenantSwitchAllowed(int $userId, int $targetId, string $role): bool {
    if ($role === 'master_admin') return true;
    $pdo = getDB();
    if (!$pdo) return false;

    // Direct membership wins.
    $stmt = $pdo->prepare(
        "SELECT 1 FROM tenant_memberships
          WHERE user_id = :u AND tenant_id = :t AND status = 'active' LIMIT 1"
    );
    $stmt->execute(['u' => $userId, 't' => $targetId]);
    if ($stmt->fetch()) return true;

    // Tenant admin of a master → can jump into any of their sub-tenants.
    $t = subTenantLookup($targetId);
    if ($t && !empty($t['parent_id'])) {
        $stmt = $pdo->prepare(
            "SELECT persona_type AS role FROM tenant_memberships
              WHERE user_id = :u AND tenant_id = :t AND status = 'active' LIMIT 1"
        );
        $stmt->execute(['u' => $userId, 't' => (int)$t['parent_id']]);
        $r = $stmt->fetch();
        if ($r && in_array($r['role'], ['tenant_admin', 'master_admin'], true)) {
            return true;
        }
    }
    return false;
}
