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
        $col = $m[1];
        // Belt-and-suspenders self-heal for known schema drift. If we
        // recognise the column, attempt to add it inline using the same
        // information_schema-guarded DDL as the module migration, then
        // tell the user to retry. Idempotent + tenant-safe.
        $healed = cf_self_heal_known_column($col);
        if ($healed) {
            api_error("Database column '{$col}' was missing — I just added it. Please retry your action.", 503, [
                'self_heal' => true, 'column' => $col,
            ]);
        }
        api_error("Database column '{$col}' is missing — a migration probably needs to run. Try reloading the page; CoreFlux runs pending migrations on every API request so this usually self-heals on the next click.", 500, [
            'hint'   => 'If the error persists after a reload, check /admin/healthcheck for the offending column and re-run modules/<module>/migrations/*.sql manually.',
            'column' => $col,
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

/**
 * Self-heal a known schema-drift column reference. Returns true if the
 * column was missing and has now been added (or skipped because the host
 * table doesn't exist on this tenant). Returns false if we don't have a
 * recipe for this column.
 *
 * Recipes are intentionally narrow + audited per known incident — we DO
 * NOT do arbitrary DDL based on parsed error messages.
 *
 * @param string $colRef A column reference like "te.person_id" or "person_id".
 */
function cf_self_heal_known_column(string $colRef): bool {
    // Recipes: [table => [column => DDL fragment]]
    // Audited list of known schema-drift columns. Each fragment is the
    // bare `ADD COLUMN ...` clause — `cf_self_heal_known_column` prepends
    // the ALTER TABLE. Order matches what runtime code expects to read.
    static $recipes = [
        'time_entries' => [
            'placement_id'          => 'ADD COLUMN placement_id BIGINT UNSIGNED NULL',
            'person_id'             => 'ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER placement_id',
            'period_id'             => 'ADD COLUMN period_id BIGINT UNSIGNED NULL',
            'work_date'             => 'ADD COLUMN work_date DATE NULL',
            'hours'                 => 'ADD COLUMN hours DECIMAL(6,2) NOT NULL DEFAULT 0',
            'category'              => "ADD COLUMN category ENUM('regular_billable','regular_nonbillable','OT_billable','OT_nonbillable','holiday','vacation','sick','bereavement','unpaid_leave','custom') NOT NULL DEFAULT 'regular_billable'",
            'status'                => "ADD COLUMN status ENUM('draft','pending_review','approved','rejected','superseded') NOT NULL DEFAULT 'draft'",
            'source'                => "ADD COLUMN source ENUM('ai_inbox','bulk_upload','manual_entry','client_portal_paste') NOT NULL DEFAULT 'manual_entry'",
            'description'           => 'ADD COLUMN description VARCHAR(500) NULL',
            'created_by_user_id'    => 'ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL',
            'approved_by_user_id'   => 'ADD COLUMN approved_by_user_id BIGINT UNSIGNED NULL',
            'approved_at'           => 'ADD COLUMN approved_at DATETIME NULL',
            'approved_via'          => "ADD COLUMN approved_via ENUM('manual','tokenized_client_email','bulk_pre_approved') NULL",
            'rejected_reason'       => 'ADD COLUMN rejected_reason VARCHAR(500) NULL',
            'rate_snapshot_id'      => 'ADD COLUMN rate_snapshot_id BIGINT UNSIGNED NULL',
            'timesheet_id'          => 'ADD COLUMN timesheet_id BIGINT UNSIGNED NULL',
            'hour_type'             => "ADD COLUMN hour_type ENUM('regular','overtime','doubletime','holiday','pto','sick','bereavement','unpaid','nonbillable') NOT NULL DEFAULT 'regular'",
            'billable'              => 'ADD COLUMN billable TINYINT(1) NOT NULL DEFAULT 1',
            'payable'               => 'ADD COLUMN payable TINYINT(1) NOT NULL DEFAULT 1',
        ],
    ];
    // Resolve alias prefix (te.person_id → person_id, but we still need the table).
    $col = $colRef;
    if (strpos($colRef, '.') !== false) $col = substr($colRef, strpos($colRef, '.') + 1);

    foreach ($recipes as $table => $cols) {
        if (!isset($cols[$col])) continue;
        try {
            $pdo = getDB();
            $ts  = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t");
            $ts->execute(['t' => $table]);
            if ((int) $ts->fetchColumn() !== 1) return true; // table not present on this tenant — nothing to heal, but we're not failing the request either
            $cs = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t AND column_name=:c");
            $cs->execute(['t' => $table, 'c' => $col]);
            if ((int) $cs->fetchColumn() === 1) return true; // already present (race condition between two requests) — call it a win
            $pdo->exec("ALTER TABLE `{$table}` {$cols[$col]}");
            // Force-rerun module migrations so any downstream backfill (UPDATEs, indexes) lands too.
            try { if (function_exists('coreflux_run_migrations')) coreflux_run_migrations(); } catch (\Throwable $_) { /* best effort */ }
            error_log("[cf_self_heal] added column {$table}.{$col}");
            return true;
        } catch (\Throwable $e) {
            error_log("[cf_self_heal] failed to add {$table}.{$col}: " . $e->getMessage());
            return false;
        }
    }
    return false;
}
