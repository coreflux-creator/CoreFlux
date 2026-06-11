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
require_once __DIR__ . '/auditor.php';
// Membership read-fallback shim — exposes membershipReadSourceSql() so any
// API endpoint can swap a direct `FROM tenant_memberships` for the UNIONed
// subquery that also surfaces un-backfilled legacy `user_tenants` rows.
require_once __DIR__ . '/memberships.php';
// RBAC B2 resolver — new tenant_memberships grid. Loaded alongside the
// legacy /core/RBAC.php (different class name on purpose; see header in
// /core/rbac/permissions.php). Safe to require at bootstrap so $ctx can
// carry membership-aware data for every endpoint.
require_once __DIR__ . '/rbac/permissions.php';
// RBAC B4 bridge — legacy permission string → (module, action) translator.
// Exposes rbac_legacy_can() / rbac_legacy_require() for the sweep.
require_once __DIR__ . '/rbac/legacy_map.php';

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

    // -----------------------------------------------------------------
    // Auditor mode — read-only enforcement at the bootstrap layer.
    // -----------------------------------------------------------------
    // When the session was redeemed via /auditor.php every non-GET request
    // is rejected with 403 right here. Endpoints don't have to opt in —
    // this is a defense-in-depth blanket gate.
    if (function_exists('auditorModeActive') && auditorModeActive()) {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            api_error('Forbidden — external auditor sessions are read-only', 403, [
                'auditor_mode' => true,
            ]);
        }
        // Log the page view (best-effort).
        if (!empty($_SESSION['auditor_token_id'])) {
            auditorLog(
                (int) $_SESSION['auditor_token_id'],
                (int) ($_SESSION['tenant_id'] ?? 0),
                'view',
                (string) ($_SERVER['REQUEST_URI'] ?? '')
            );
        }
    }

    // -----------------------------------------------------------------
    // Platform-mode bypass for master_admin / is_global_admin.
    // -----------------------------------------------------------------
    // master_admin is a *platform* role, not a per-tenant role. If the
    // user's `users.role` is master_admin (or `users.is_global_admin=1`)
    // they retain that role even when no tenant is pinned (platform
    // dashboard view) and CANNOT be downgraded by a per-tenant
    // `user_tenants.role` of 'tenant_admin'/'admin' below.
    $globalRole    = (string) ($user['global_role'] ?? $_SESSION['global_role'] ?? $user['role'] ?? 'employee');
    $isPlatformMA  = ($globalRole === 'master_admin');
    if (!$isPlatformMA && $user) {
        // Authoritative re-check from DB to defend against stale session.
        try {
            $st = getDB()->prepare('SELECT role, COALESCE(is_global_admin,0) AS iga FROM users WHERE id = :id LIMIT 1');
            $st->execute(['id' => (int) ($user['id'] ?? 0)]);
            $u  = $st->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                if ((string) ($u['role'] ?? '') === 'master_admin' || (int) ($u['iga'] ?? 0) === 1) {
                    $isPlatformMA = true;
                    $globalRole   = 'master_admin';
                    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                        $_SESSION['user']['global_role'] = 'master_admin';
                    }
                    $_SESSION['global_role'] = 'master_admin';
                }
            }
        } catch (\Throwable $_) { /* keep session view */ }
    }

    if ($requireTenant && !$tenantId && !$isPlatformMA) {
        api_error('No tenant context', 400);
    }

    // Effective role resolution. The session-baked role on the user reflects
    // login state at *that* tenant; switching tenants without refreshing the
    // session would leave a tenant_admin acting like a tenant_admin when they
    // should be master_admin (and vice-versa). Re-derive from user_tenants
    // for the active tenant. Falls through silently on DB hiccups so unrelated
    // endpoints don't 500 on this path.
    //
    // STRICT FLOOR (P0 fix, 2026-02): if the platform-mode bypass above
    // identified this user as `master_admin`/`is_global_admin=1`, that role
    // CANNOT be downgraded by a per-tenant `user_tenants` row. The platform
    // role is the floor; per-tenant role is only the override for non-globals.
    $effectiveRole = $isPlatformMA ? 'master_admin' : ($user['role'] ?? 'employee');
    if (!$isPlatformMA && $user && $tenantId) {
        try {
            $st = getDB()->prepare(
                'SELECT role FROM user_tenants
                  WHERE user_id = :u AND tenant_id = :t AND status = "active"
                  LIMIT 1'
            );
            $st->execute(['u' => (int) ($user['id'] ?? 0), 't' => (int) $tenantId]);
            $r = $st->fetchColumn();
            if ($r !== false && $r !== null && $r !== '') {
                $effectiveRole = (string) $r;
                // Mirror back to the session so downstream code that reads
                // $_SESSION['user']['role'] directly sees the active value.
                if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                    $_SESSION['user']['role'] = $effectiveRole;
                }
            }
        } catch (\Throwable $_) { /* keep session role */ }
    }

    // -----------------------------------------------------------------
    // RBAC B2 — hydrate the active membership for the new resolver.
    // -----------------------------------------------------------------
    // Independent of legacy user_tenants: we look up tenant_memberships
    // via RBACResolver so $ctx callers can ask `can()` directly. When
    // the membership exists, its persona_type overrides the legacy role
    // string (a single user can be Admin in tenant A and Employee in
    // tenant B without a re-login).  When it doesn't, we fall through
    // and leave the legacy $effectiveRole as-is.
    //
    // STRICT FLOOR (P0 fix, 2026-02): the persona_type override is also
    // suppressed when $isPlatformMA — master_admin must stay master_admin
    // even if their per-tenant persona_label happens to be 'admin'.
    $membershipId    = null;
    $personaType     = null;
    $isGlobalAdmin   = $isPlatformMA;
    if ($user && $tenantId && class_exists('RBACResolver')) {
        try {
            // Trust the platform-mode flag if already set; else ask the resolver.
            if (!$isGlobalAdmin) {
                $isGlobalAdmin = RBACResolver::isGlobalAdmin((int) ($user['id'] ?? 0));
            }
            $personaId     = isset($_SESSION['active_persona_id']) ? (int) $_SESSION['active_persona_id'] : null;
            $membership    = RBACResolver::activeMembership(
                (int) ($user['id'] ?? 0),
                (int) $tenantId,
                $personaId
            );
            if ($membership) {
                $membershipId = (int) $membership['id'];
                $personaType  = (string) ($membership['persona_type'] ?? '');
                if ($personaType !== '' && !$isPlatformMA) {
                    $effectiveRole = $personaType;
                    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                        $_SESSION['user']['role'] = $effectiveRole;
                    }
                }
            }
        } catch (\Throwable $_) { /* legacy fall-through */ }
    }

    return [
        'user'           => $user,
        'tenant_id'      => $tenantId,
        'role'           => $effectiveRole,
        'global_role'    => $globalRole,
        'membership_id'  => $membershipId,
        'persona_type'   => $personaType,
        'is_global_admin'=> $isGlobalAdmin,
    ];
}

/**
 * Permission check using the new RBACResolver (B2). Wraps the resolver so
 * endpoints don't have to import it directly. Returns false if no auth
 * context is present rather than throwing — callers decide whether to
 * 401/403 themselves.
 */
function api_can(string $module, string $action = 'read', ?int $subTenantId = null): bool {
    $user     = getCurrentUser();
    $tenantId = currentTenantId();
    if (!$user || !$tenantId || !class_exists('RBACResolver')) return false;
    $personaId = isset($_SESSION['active_persona_id']) ? (int) $_SESSION['active_persona_id'] : null;
    return RBACResolver::can((int) ($user['id'] ?? 0), (int) $tenantId, $module, $action, $subTenantId, $personaId);
}

/**
 * Enforce a permission via RBACResolver. Emits 403 if denied. Use this in
 * endpoints that have migrated off the legacy role-list checks.
 */
function api_require_can(string $module, string $action = 'read', ?int $subTenantId = null): void {
    if (api_can($module, $action, $subTenantId)) return;
    api_error('Forbidden', 403, [
        'required_module' => $module,
        'required_action' => $action,
    ]);
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
 * Gate CFO-only surfaces (CFO Dashboard, send-report email, formula CRUD).
 *
 * Allowed:
 *   - users.role = 'master_admin' OR users.is_global_admin = 1 (platform)
 *   - tenant_admin / admin at the active tenant
 *   - explicit grant via membership_module_access.module_key = 'cfo'
 *     with access_level in ('read','write','admin')
 *
 * Emits 403 if none match. Returns the standard $ctx on success.
 */
function api_require_cfo(): array {
    $ctx = api_require_auth();
    $role        = (string) ($ctx['role'] ?? 'employee');
    $globalRole  = (string) ($ctx['global_role'] ?? $role);
    $isGlobalAdm = (bool) ($ctx['is_global_admin'] ?? false);

    // Platform admins always allowed.
    if ($globalRole === 'master_admin' || $isGlobalAdm) return $ctx;
    // Tenant admins / admins of the active tenant always allowed.
    if (in_array($role, ['tenant_admin', 'admin'], true)) return $ctx;
    // External auditor (token-redeemed session) is read-only and explicitly
    // permitted on CFO surfaces — the read-only enforcement happens at the
    // bootstrap layer (every non-GET 403s).
    if ($role === 'auditor' || !empty($_SESSION['auditor_mode'])) return $ctx;

    // Explicit per-membership grant of the synthetic 'cfo' module.
    $membershipId = (int) ($ctx['membership_id'] ?? 0);
    if ($membershipId > 0 && class_exists('RBACResolver')) {
        try {
            $row = RBACResolver::moduleAccessFor($membershipId, 'cfo');
            $level = (string) ($row['access_level'] ?? 'none');
            if (in_array($level, ['read', 'write', 'admin'], true)) return $ctx;
        } catch (\Throwable $_) { /* fall through */ }
    }

    api_error('Forbidden — CFO surface requires master_admin, tenant_admin, or an explicit CFO grant', 403, [
        'required_module' => 'cfo',
        'global_role'     => $globalRole,
    ]);
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
 * Fatal-error trap. set_exception_handler() above catches uncaught
 * THROWABLES (Exception, TypeError, PDOException, …). It does NOT catch
 * E_ERROR / E_PARSE / E_CORE_ERROR / out-of-memory / "Call to undefined
 * function" — those bypass user-land handlers entirely.
 *
 * In production with `display_errors=Off` (the recommended setting), a
 * fatal therefore emits a 500 with an empty body and `Content-Type:
 * text/html`. The UI sees "Request failed" with no detail, indistinguishable
 * from a network error.
 *
 * register_shutdown_function() runs even after a fatal. Inside it we read
 * error_get_last() and, if the last error was fatal-severity, emit a JSON
 * envelope so the front-end has something to display.
 *
 * Observed 2026-02 on /api/admin/integrations/field_map.php after deploying
 * a try/catch wrap — the endpoint still 500-emptied because the actual
 * error was a fatal, not a throwable. This shutdown handler unmasks it.
 */
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) return;
    // Severity codes that PHP treats as fatal — anything that aborts the
    // script before normal output flushing.
    $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING
               | E_COMPILE_ERROR | E_COMPILE_WARNING | E_USER_ERROR;
    if (($err['type'] & $fatalMask) === 0) return;

    // If headers have already been sent (e.g. api_ok flushed before the
    // fatal occurred mid-shutdown) we cannot rewrite the response.
    if (headers_sent()) return;

    @http_response_code(500);
    @header('Content-Type: application/json; charset=utf-8');
    @header('Cache-Control: no-store');

    // Discard any partial output the dying script may have echoed
    // (otherwise it would prefix our JSON envelope and break the parse).
    while (ob_get_level() > 0) { @ob_end_clean(); }

    error_log('[api/fatal] ' . $err['message'] . ' @ ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?'));

    echo json_encode([
        'error'  => 'Fatal PHP error: ' . $err['message'],
        'status' => 500,
        'kind'   => 'fatal',
        'file'   => isset($err['file']) ? basename((string) $err['file']) : null,
        'line'   => $err['line'] ?? null,
        'hint'   => 'PHP fatal errors are NOT caught by exception handlers. The most common cause on this stack is a missing function/class (e.g. Call to undefined function …) after a partial deploy. Check the server error log for the full path and stack.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

/**
 * Belt-and-braces shutdown handler that rolls back any dangling DB
 * transaction.  Defends against handlers that throw mid-INSERT without
 * a matching rollback (the Feb-2026 "There is already an active
 * transaction" report on /api/ap/bills was traced to a prior handler
 * exiting with `api_error()` before its rollback line ran — the next
 * request would have started fine on a fresh PDO, but the SAME request
 * with chained handlers would carry the dangling tx into the next
 * `beginTransaction()` call).
 *
 * Cheap, idempotent, runs after the response is flushed so it never
 * delays the user.
 */
register_shutdown_function(static function (): void {
    try {
        if (function_exists('getDB')) {
            $pdo = @getDB();
            if ($pdo && $pdo->inTransaction()) {
                error_log('[api/tx] shutdown: rolling back dangling transaction (handler missed rollback)');
                @$pdo->rollBack();
            }
        }
    } catch (\Throwable $_) { /* best effort */ }
});

/**
 * Safe `beginTransaction()` wrapper.  Use in any new handler that
 * starts a transaction — protects against the "already an active
 * transaction" failure mode by first rolling back any inherited tx.
 *
 * Returns the PDO so the caller can chain.
 */
function cf_begin_transaction(): \PDO {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');
    if ($pdo->inTransaction()) {
        error_log('[api/tx] cf_begin_transaction: rolling back stale active transaction before begin');
        $pdo->rollBack();
    }
    $pdo->beginTransaction();
    return $pdo;
}

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
            'approved_via'          => "ADD COLUMN approved_via ENUM('manual','tokenized_client_email','bulk_pre_approved','external_email') NULL",
            'rejected_reason'       => 'ADD COLUMN rejected_reason VARCHAR(500) NULL',
            'rate_snapshot_id'      => 'ADD COLUMN rate_snapshot_id BIGINT UNSIGNED NULL',
            'timesheet_id'          => 'ADD COLUMN timesheet_id BIGINT UNSIGNED NULL',
            'hour_type'             => "ADD COLUMN hour_type ENUM('regular','overtime','doubletime','holiday','pto','sick','bereavement','unpaid','nonbillable') NOT NULL DEFAULT 'regular'",
            'billable'              => 'ADD COLUMN billable TINYINT(1) NOT NULL DEFAULT 1',
            'payable'               => 'ADD COLUMN payable TINYINT(1) NOT NULL DEFAULT 1',
        ],
        'staffing_timesheets' => [
            'approved_via'            => "ADD COLUMN approved_via VARCHAR(32) NOT NULL DEFAULT 'internal_app'",
            'external_approver_email' => 'ADD COLUMN external_approver_email VARCHAR(255) NULL',
            'external_approver_name'  => 'ADD COLUMN external_approver_name VARCHAR(255) NULL',
            'approval_note'           => 'ADD COLUMN approval_note VARCHAR(1000) NULL',
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
