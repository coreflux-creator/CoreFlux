<?php
/**
 * Auth gate helper for installer / updater / diagnostics pages.
 * Returns the user array if they're allowed; redirects to login otherwise.
 *
 * Accepts master_admin / admin in either:
 *   - $_SESSION['user']['role']         (tenant-scoped role from login.php)
 *   - $_SESSION['user']['global_role']  (cross-tenant role from users table)
 *   - $_SESSION['global_role']          (back-compat)
 */
function requireAdminForOps(string $redirectTo = 'install'): array {
    require_once __DIR__ . '/auth.php';
    initSession();
    $user = getCurrentUser();
    $tenantRole = $user['role']        ?? null;
    $globalRole = $user['global_role'] ?? ($_SESSION['global_role'] ?? null);
    $allowed = ['master_admin', 'admin', 'tenant_admin'];
    $ok = $user && (in_array($tenantRole, $allowed, true) || in_array($globalRole, $allowed, true));
    if (!$ok) {
        header('Location: /login.html?redirect=' . urlencode($redirectTo));
        exit;
    }
    return $user;
}
