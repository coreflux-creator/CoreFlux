<?php
/**
 * CoreFlux API Bootstrap
 *
 * Standard entry point for ALL module API endpoints.
 * Handles: session init, auth guard, tenant context, JSON I/O helpers,
 * consistent error shape, and method/CORS plumbing.
 *
 * Usage in a module endpoint (e.g. /modules/payroll/api/employees.php):
 *
 *     require_once __DIR__ . '/../../../core/api_bootstrap.php';
 *     $ctx = api_require_auth();                 // { user, tenant_id, role }
 *     $method = api_method();
 *
 *     if ($method === 'GET') {
 *         api_ok(['employees' => []]);
 *     }
 *     if ($method === 'POST') {
 *         $body = api_json_body();
 *         // ... create record scoped to $ctx['tenant_id'] ...
 *         api_ok(['id' => 123], 201);
 *     }
 *     api_error('Method not allowed', 405);
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tenant_scope.php';

// ---------------------------------------------------------------------------
// Session + headers
// ---------------------------------------------------------------------------
initSession();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Credentials: true');

// Same-origin in production; allow preview origins for local dev.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && preg_match('#^https?://(localhost|127\.0\.0\.1|.*\.corefluxapp\.com|.*\.preview\.emergentagent\.com)(:\d+)?$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------------
// Auto-migrate: run pending schema migrations on first request per process.
// Cached in-memory so subsequent requests skip the work. Failures are
// non-fatal — they get surfaced via /api/migrate.php for admin review,
// but never 500 the user-facing endpoint.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/migrate.php';
try { coreflux_run_migrations(); } catch (\Throwable $_) { /* non-fatal */ }

// ---------------------------------------------------------------------------
// Response helpers
// ---------------------------------------------------------------------------
function api_ok($data = null, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $message, int $status = 400, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge([
        'error'  => $message,
        'status' => $status,
    ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// Request helpers
// ---------------------------------------------------------------------------
function api_method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function api_query(string $key, $default = null) {
    return $_GET[$key] ?? $default;
}

function api_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        api_error('Invalid JSON body: ' . json_last_error_msg(), 400);
    }
    return is_array($data) ? $data : [];
}

/**
 * Validate required fields. On failure, returns a 422 with the list of missing keys.
 */
function api_require_fields(array $data, array $required): void {
    $missing = [];
    foreach ($required as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
            $missing[] = $field;
        }
    }
    if ($missing) {
        api_error('Missing required fields', 422, ['fields' => $missing]);
    }
}

// ---------------------------------------------------------------------------
// Auth + tenant guards
// ---------------------------------------------------------------------------
/**
 * Require an authenticated user. Returns a context array with user, tenant_id, role.
 * Emits 401 if not authenticated, 400 if no tenant selected.
 */
function api_require_auth(bool $requireTenant = true): array {
    // Accept JWT bearer first (mobile / API clients), fall back to session cookie (web SPA).
    if (!isAuthenticated()) {
        require_once __DIR__ . '/jwt.php';
        $payload = jwtFromRequest();
        if ($payload && !empty($payload['user_id']) && !empty($payload['tenant_id'])) {
            // Hydrate session-shape context for the rest of the stack to use.
            $_SESSION['user'] = [
                'id'       => (int) $payload['user_id'],
                'name'     => (string) ($payload['name'] ?? ''),
                'email'    => (string) ($payload['email'] ?? ''),
                'role'     => (string) ($payload['role'] ?? 'employee'),
                'auth_via' => 'jwt',
            ];
            $_SESSION['tenant_id'] = (int) $payload['tenant_id'];
        } else {
            api_error('Not authenticated', 401);
        }
    }
    $user     = getCurrentUser();
    $tenantId = currentTenantId();

    if ($requireTenant && !$tenantId) {
        api_error('No tenant context', 400);
    }

    return [
        'user'      => $user,
        'tenant_id' => $tenantId,
        'role'      => $user['role'] ?? 'employee',
    ];
}

/**
 * Require one of the given roles. Emits 403 if not permitted.
 */
function api_require_role(array $allowedRoles): array {
    $ctx = api_require_auth();
    if (!in_array($ctx['role'], $allowedRoles, true) && $ctx['role'] !== 'master_admin') {
        api_error('Forbidden', 403);
    }
    return $ctx;
}

/**
 * Fatal error trap so modules never leak stack traces as HTML.
 */
set_exception_handler(function (Throwable $e) {
    error_log('[api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    // Recognise "table doesn't exist" — happens when a module's migration
    // hasn't been run yet on the target database. Helps the operator spot
    // it instantly instead of a generic 500.
    $msg = $e->getMessage();
    if (preg_match("/Base table or view not found.*?'([^']+)'|Table '[^']*\.([^']+)' doesn't exist/i", $msg, $m)) {
        $table = $m[1] ?? $m[2] ?? 'unknown';
        api_error("Database table '{$table}' does not exist. Run the module's migration on this database.", 500, [
            'hint' => 'See modules/<module>/migrations/*.sql or memory/PEOPLE_DEPLOY_NOTES.md',
            'table' => $table,
        ]);
    }
    if (preg_match("/Unknown column '([^']+)'/i", $msg, $m)) {
        api_error("Database column '{$m[1]}' is missing — a migration probably needs to run.", 500, [
            'hint'   => 'Run the relevant migration in modules/*/migrations/*.sql then retry.',
            'column' => $m[1],
        ]);
    }
    if (preg_match("/SQLSTATE\\[(\\w+)\\]/i", $msg, $m)) {
        // Surface a redacted SQL error rather than the generic phrase the user keeps seeing.
        $clean = preg_replace('/in \\/[\\w\\/.\\-]+\\.php:\\d+/', '', $msg);
        api_error("Database error ({$m[1]}). Details: " . trim((string) $clean), 500, ['kind' => 'sql']);
    }

    if (defined('APP_DEBUG') && APP_DEBUG) {
        api_error($e->getMessage(), 500, ['trace' => $e->getTraceAsString()]);
    }
    // Last-resort: include the original message so the user sees something
    // diagnosable on screen instead of a literal "Internal server error".
    api_error('Server error: ' . $msg, 500, ['kind' => 'unhandled']);
});
