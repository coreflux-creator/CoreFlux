<?php
/**
 * CoreFlux RBAC — role-based access control.
 *
 * Thin layer that answers one question: *can this user do this thing?*
 *
 *   RBAC::hasPermission($user, 'people.banking.view')   // bool
 *   RBAC::requirePermission($user, 'payroll.runs.approve')   // throws/aborts
 *   RBAC::getEffectivePermissions($user)   // list<string>
 *
 * Inputs:
 *   $user is whatever the auth context provides — at minimum:
 *     ['role' => 'admin']
 *   Optionally also 'global_role' (master_admin override) and 'tenant_role'
 *   (per-tenant role for multi-tenant users). When both are present
 *   tenant_role wins; global_role only matters for 'master_admin'.
 *
 * Storage: roles are mapped to permission patterns in /core/rbac_config.php.
 * Patterns may include `*` and `<module>.*` style wildcards. See that file
 * for the current grants.
 *
 * Posture: default-deny. Anything not granted (directly or via wildcard) is
 * denied. There is no implicit "admin can do anything" — admins are
 * granted `*` patterns explicitly in config.
 *
 * Discoverable permissions: this class also exposes the universe of
 * declared permissions (sourced from ModuleRegistry) so admin UIs can
 * render checkboxes, audits can validate, etc.
 */

declare(strict_types=1);

require_once __DIR__ . '/ModuleRegistry.php';

class RBAC {

    /** @var array<string, list<string>>|null role => patterns; null = unloaded */
    private static ?array $roleMap = null;

    /** Per-request cache: user signature => effective permission list */
    private static array $effectiveCache = [];

    // -----------------------------------------------------------------
    // Configuration loading
    // -----------------------------------------------------------------

    public static function loadConfig(?string $path = null): void {
        $path = $path ?? __DIR__ . '/rbac_config.php';
        if (!file_exists($path)) {
            self::$roleMap = [];
            return;
        }
        /** @var mixed $data */
        $data = require $path;
        if (!is_array($data)) {
            self::$roleMap = [];
            error_log("RBAC: config at $path did not return array");
            return;
        }
        $clean = [];
        foreach ($data as $role => $patterns) {
            if (!is_string($role) || !is_array($patterns)) continue;
            $clean[$role] = array_values(array_filter($patterns, 'is_string'));
        }
        self::$roleMap = $clean;
    }

    public static function reset(): void {
        self::$roleMap = null;
        self::$effectiveCache = [];
    }

    private static function ensureLoaded(): void {
        if (self::$roleMap === null) self::loadConfig();
    }

    // -----------------------------------------------------------------
    // Role resolution
    // -----------------------------------------------------------------

    /**
     * Determine which role applies to the given user object.
     *
     * Precedence:
     *   1. global_role === 'master_admin'  → master_admin
     *   2. tenant_role (per-tenant role)
     *   3. role (legacy field)
     *   4. fallback: 'employee'
     */
    public static function resolveRole(array $user): string {
        $globalRole = $user['global_role'] ?? null;
        if ($globalRole === 'master_admin') return 'master_admin';

        $role = $user['tenant_role'] ?? $user['role'] ?? null;
        return is_string($role) && $role !== '' ? $role : 'employee';
    }

    // -----------------------------------------------------------------
    // Permission checks (the public API)
    // -----------------------------------------------------------------

    public static function hasPermission(array $user, string $permission): bool {
        $perms = self::getEffectivePermissions($user);
        return self::matchesAny($permission, $perms);
    }

    /**
     * Block the request if user lacks the permission. Renders a 403 JSON
     * body via api_error() (which calls exit), so subsequent code does not
     * run on denial.
     */
    public static function requirePermission(array $user, string $permission): void {
        if (self::hasPermission($user, $permission)) return;

        if (function_exists('api_error')) {
            api_error("Forbidden: missing permission '{$permission}'", 403, [
                'required' => $permission,
                'role'     => self::resolveRole($user),
            ]);
        } else {
            // Fallback for non-API contexts (CLI, future server-rendered pages).
            http_response_code(403);
            echo "Forbidden: missing permission '{$permission}'";
            exit;
        }
    }

    /**
     * Effective permission patterns for a user (after wildcard expansion is
     * still as patterns, not concrete strings — wildcards are matched at
     * check-time, not enumerated here).
     *
     * @return list<string>
     */
    public static function getEffectivePermissions(array $user): array {
        self::ensureLoaded();
        $role = self::resolveRole($user);
        $cacheKey = $role; // user-independent: only role drives grants today
        if (isset(self::$effectiveCache[$cacheKey])) {
            return self::$effectiveCache[$cacheKey];
        }
        $patterns = self::$roleMap[$role] ?? [];
        return self::$effectiveCache[$cacheKey] = $patterns;
    }

    /**
     * The full universe of declared permission keys (from manifests).
     * Useful for an admin UI rendering a permission grid.
     *
     * @return list<string>
     */
    public static function getAllDeclaredPermissions(): array {
        return ModuleRegistry::getInstance()->getAllPermissions();
    }

    // -----------------------------------------------------------------
    // Pattern matching
    // -----------------------------------------------------------------

    /**
     * Does any pattern in $patterns grant $permission?
     *
     * Pattern semantics:
     *   '*'                  → matches anything
     *   'people.*'           → matches everything starting with 'people.'
     *   'people.banking.*'   → matches 'people.banking.X'
     *   'people.banking.view' → exact only
     *
     * Note: a single '*' wildcard segment matches one OR many segments.
     * That keeps `people.*` covering both `people.view` and
     * `people.banking.view` without requiring a second pattern.
     */
    private static function matchesAny(string $permission, array $patterns): bool {
        foreach ($patterns as $pattern) {
            if (self::patternMatches($pattern, $permission)) return true;
        }
        return false;
    }

    private static function patternMatches(string $pattern, string $permission): bool {
        if ($pattern === '*' || $pattern === $permission) return true;
        if (!str_contains($pattern, '*')) return false;

        // Convert glob-ish pattern to regex. Escape literal segments,
        // turn '*' into '.*'.
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
        return (bool) preg_match($regex, $permission);
    }
}
