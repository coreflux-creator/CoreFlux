<?php
/**
 * CoreFlux Authentication Helpers
 * Session management and demo login support
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/modules.php';

/**
 * Initialize session with proper settings
 */
function initSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Check if user is authenticated
 */
function isAuthenticated(): bool {
    initSession();
    return isset($_SESSION['user']) && !empty($_SESSION['user']);
}

/**
 * Get current user from session
 */
function getCurrentUser(): ?array {
    initSession();
    return $_SESSION['user'] ?? null;
}

/**
 * Get current tenant from session
 */
function getCurrentTenant(): ?string {
    initSession();
    return $_SESSION['tenant'] ?? null;
}

/**
 * Get user's accessible modules
 */
function getSessionModules(): array {
    initSession();
    return $_SESSION['modules'] ?? [];
}

/**
 * Get active module
 */
function getActiveModule(): ?array {
    initSession();
    return $_SESSION['active_module'] ?? null;
}

/**
 * Set active module
 */
function setActiveModule(string $moduleId): bool {
    initSession();
    $modules = $_SESSION['modules'] ?? [];
    
    foreach ($modules as $module) {
        if ($module['id'] === $moduleId) {
            $_SESSION['active_module'] = $module;
            return true;
        }
    }
    return false;
}

/**
 * Create demo session (for development without DB)
 */
function createDemoSession(string $role = 'admin'): void {
    initSession();
    
    $demoUsers = [
        'admin' => [
            'id' => 1,
            'first_name' => 'Demo',
            'last_name' => 'Admin',
            'email' => 'admin@coreflux.demo',
            'role' => 'admin',
            'avatar' => null,
            'tenants' => [
                ['id' => 1, 'name' => 'Acme Corp'],
                ['id' => 2, 'name' => 'Beta Industries'],
            ]
        ],
        'employee' => [
            'id' => 2,
            'first_name' => 'John',
            'last_name' => 'Employee',
            'email' => 'employee@coreflux.demo',
            'role' => 'employee',
            'avatar' => null,
            'tenants' => [
                ['id' => 1, 'name' => 'Acme Corp'],
            ]
        ],
        'manager' => [
            'id' => 3,
            'first_name' => 'Sarah',
            'last_name' => 'Manager',
            'email' => 'manager@coreflux.demo',
            'role' => 'manager',
            'avatar' => null,
            'tenants' => [
                ['id' => 1, 'name' => 'Acme Corp'],
            ]
        ],
    ];
    
    $user = $demoUsers[$role] ?? $demoUsers['admin'];
    $modules = getUserModules($user['role']);
    
    $_SESSION['user'] = $user;
    $_SESSION['modules'] = $modules;
    $_SESSION['tenant'] = $user['tenants'][0]['name'];
    $_SESSION['tenant_id'] = $user['tenants'][0]['id'];
    $_SESSION['active_module'] = $modules[0] ?? null;
}

/**
 * Destroy session and logout
 */
function logout(): void {
    initSession();
    session_destroy();
}

/**
 * RBAC B2 — persona switching for the tenant/role toggle.
 *
 * The SPA header lets a user with multiple memberships in the same
 * tenant flip between them (e.g. "Admin" vs "Employee" persona).
 * Storing the chosen persona id in the session means api_require_auth()
 * will hydrate $ctx['membership_id'] / $ctx['persona_type'] from that
 * specific membership row on subsequent requests.
 *
 * Returns true when the persona belongs to the current user + tenant.
 */
function setActivePersona(int $personaId): bool {
    initSession();
    if (!class_exists('RBACResolver')) {
        require_once __DIR__ . '/rbac/permissions.php';
    }
    $user     = getCurrentUser();
    $tenantId = $_SESSION['tenant_id'] ?? null;
    if (!$user || !$tenantId) return false;
    $memberships = RBACResolver::memberships((int) ($user['id'] ?? 0), (int) $tenantId);
    foreach ($memberships as $m) {
        if ((int) $m['id'] === $personaId && ($m['status'] ?? 'active') === 'active') {
            $_SESSION['active_persona_id'] = $personaId;
            return true;
        }
    }
    return false;
}

function getActivePersonaId(): ?int {
    initSession();
    return isset($_SESSION['active_persona_id']) ? (int) $_SESSION['active_persona_id'] : null;
}

function clearActivePersona(): void {
    initSession();
    unset($_SESSION['active_persona_id']);
}
