<?php
/**
 * Zoho Books integration — connect (OAuth), disconnect, status, ping,
 * sync_config (get/set).
 *
 * Routes (all prefixed `/api/zoho_books/`):
 *   GET    status              — connection state + recent audit + sync_config
 *   GET    oauth_start         — returns Zoho authorize URL + state nonce
 *   GET    oauth_callback      — Zoho redirects here with ?code & ?state & accounts-server
 *   POST   disconnect          — soft-disconnect, best-effort upstream revoke
 *   POST   ping                — auth round-trip via /organizations
 *   GET    sync_config_get     — per-entity direction map
 *   POST   sync_config_set     — body: { sync_config: {entity: direction} }
 *
 * RBAC: read = `integrations.zoho_books.view`, write = `integrations.zoho_books.manage`.
 * Wildcard `integrations.*` from rbac_config covers both for tenant_admin.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/zoho_books/client.php';
require_once __DIR__ . '/../core/zoho_books/sync_je.php';
require_once __DIR__ . '/../core/zoho_books/sync_accounts.php';
require_once __DIR__ . '/../core/zoho_books/sync_contacts.php';
require_once __DIR__ . '/../core/zoho_books/sync_invoices.php';
require_once __DIR__ . '/../core/zoho_books/sync_bills.php';
require_once __DIR__ . '/../core/zoho_books/sync_payments.php';

$method = api_method();
$action = (string) (api_query('action') ?? '');
if ($action === '') {
    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH) ?: '';
    if (preg_match('#/zoho_books/([a-z_-]+)\.php$#i', $path, $m)) {
        $action = strtolower($m[1]);
    }
}
$action = str_replace('-', '_', strtolower($action));

// ---------------------------------------------------------------------
// OAuth callback runs BEFORE the standard auth guard because Zoho
// redirects the browser here with no CoreFlux session header. We re-use
// the SPA session cookie (PHPSESSID) that was minted before the
// redirect, then verify the state nonce.
// ---------------------------------------------------------------------
if ($action === 'oauth_callback') {
    if ($method !== 'GET') api_error('Method not allowed', 405);
    $ctx = api_require_auth();
    $user = $ctx['user'];
    $tid  = (int) $ctx['tenant_id'];

    $code           = (string) (api_query('code')   ?? '');
    $state          = (string) (api_query('state')  ?? '');
    $errParam       = (string) (api_query('error')  ?? '');
    // Zoho returns `accounts-server` as a query param to tell us the DC.
    // Falls back to the .com DC when absent (eg. user kept on global host).
    $accountsServer = (string) (api_query('accounts-server') ?? api_query('accounts_server') ?? '');

    if ($errParam !== '') {
        zohoBooksAudit($tid, 'oauth_error', [
            'ok' => false, 'actor_user_id' => $user['id'] ?? null,
            'detail' => ['error' => $errParam],
        ]);
        header('Location: /admin/integrations/zoho-books?error=' . urlencode($errParam));
        exit;
    }
    if ($code === '' || $state === '') {
        api_error('code and state are required', 400);
    }
    if (!($sub = zohoBooksConsumeOAuthState($tid, $state))) {
        zohoBooksAudit($tid, 'oauth_state_rejected', [
            'ok' => false, 'actor_user_id' => $user['id'] ?? null,
            'detail' => ['state' => substr($state, 0, 8) . '…'],
        ]);
        api_error('Invalid or expired OAuth state. Click "Connect to Zoho Books" again.', 400);
    }
    try {
        zohoBooksExchangeCode($tid, $code, $accountsServer, $user['id'] ?? null, $sub);
    } catch (\Throwable $e) {
        api_error('Zoho Books token exchange failed: ' . $e->getMessage(), 502);
    }
    header('Location: /admin/integrations/zoho-books?connected=1&entity=' . $sub);
    exit;
}

// All other actions require auth.
$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

/**
 * Per-entity guard — resolves the sub_tenant_id from query/body. Defaults
 * to the parent self-entity (sub_tenant_id = tenant_id) when omitted so
 * legacy single-entity callers keep working without any code changes.
 */
function _zbSub(int $tid): int {
    $raw = api_query('sub_tenant_id');
    if ($raw === null) {
        $body = $_SERVER['REQUEST_METHOD'] === 'POST' ? api_json_body() : [];
        $raw  = $body['sub_tenant_id'] ?? null;
    }
    $n = (int) $raw;
    return $n > 0 ? $n : $tid;
}

switch ($action) {
    case 'status': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.view');
        $sub = _zbSub($tid);
        $row = zohoBooksConnection($tid, $sub);
        $audit = getDB()->prepare(
            'SELECT id, action, entity_type, direction, ok,
                    items_processed, items_skipped, items_failed,
                    detail, occurred_at
               FROM zoho_books_sync_audit
              WHERE tenant_id = :t
                AND (sub_tenant_id IS NULL OR sub_tenant_id = :st)
           ORDER BY occurred_at DESC
              LIMIT 25'
        );
        $audit->execute(['t' => $tid, 'st' => $sub]);
        $rows = $audit->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['ok'] = (bool) (int) $r['ok'];
            $r['items_processed'] = (int) $r['items_processed'];
            $r['items_skipped']   = (int) $r['items_skipped'];
            $r['items_failed']    = (int) $r['items_failed'];
            if (!empty($r['detail'])) {
                $decoded = json_decode((string) $r['detail'], true);
                $r['detail'] = is_array($decoded) ? $decoded : null;
            }
        }
        unset($r);

        $isConnected = $row && $row['status'] === 'active' && (string) $row['organization_id'] !== 'pending';
        // List every Zoho connection in the master tenant so the UI can
        // render an entity picker without a second round-trip.
        $allConns = zohoBooksConnectionsForTenant($tid);
        api_ok([
            'configured'        => zohoBooksConfigured(),
            'connected'         => $isConnected,
            'sub_tenant_id'     => $sub,
            'status'            => $row['status'] ?? null,
            'organization_id'   => $row['organization_id'] ?? null,
            'organization_name' => $row['organization_name'] ?? null,
            'dc'                => $row['dc'] ?? null,
            'scope'             => $row['scope'] ?? null,
            'access_token_exp'  => $row['access_token_exp'] ?? null,
            'last_probe_at'     => $row['last_probe_at']    ?? null,
            'last_probe_error'  => $row['last_probe_error'] ?? null,
            'sync_config'       => zohoBooksSyncConfigRead($tid, $sub),
            'entities'          => ZOHO_BOOKS_SYNC_ENTITIES,
            'directions'        => ZOHO_BOOKS_SYNC_DIRECTIONS,
            'audit'             => $rows,
            'all_connections'   => $allConns,
        ]);
    }

    case 'oauth_start': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        $sub = _zbSub($tid);
        try {
            $res = zohoBooksBuildAuthorizeUrl($tid, $user['id'] ?? null, $sub);
        } catch (\Throwable $e) {
            api_error($e->getMessage(), 422);
        }
        api_ok(['authorize_url' => $res['url']]);
    }

    case 'disconnect': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        $sub = _zbSub($tid);
        zohoBooksDisconnect($tid, $user['id'] ?? null, $sub);
        api_ok(['ok' => true]);
    }

    case 'ping': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        $sub = _zbSub($tid);
        api_ok(zohoBooksPing($tid, $user['id'] ?? null, $sub));
    }

    case 'sync_config_get': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.view');
        $sub = _zbSub($tid);
        api_ok([
            'sync_config' => zohoBooksSyncConfigRead($tid, $sub),
            'entities'    => ZOHO_BOOKS_SYNC_ENTITIES,
            'directions'  => ZOHO_BOOKS_SYNC_DIRECTIONS,
        ]);
    }

    case 'sync_config_set': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        $body = api_json_body();
        $sub  = _zbSub($tid);
        $cfg  = $body['sync_config'] ?? [];
        if (!is_array($cfg)) api_error('sync_config must be an object', 422);
        try {
            $saved = zohoBooksSyncConfigWrite($tid, $cfg, $user['id'] ?? null, $sub);
        } catch (\InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            api_error('sync_config_set failed: ' . $e->getMessage(), 500);
        }
        api_ok(['sync_config' => $saved]);
    }

    case 'sync_config_copy': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        $body = api_json_body();
        $from = (int) ($body['from_sub_tenant_id'] ?? 0);
        $to   = (int) ($body['to_sub_tenant_id']   ?? 0);
        if ($from <= 0 || $to <= 0) api_error('from_sub_tenant_id and to_sub_tenant_id required', 422);
        try {
            $res = zohoBooksSyncConfigCopy($tid, $from, $to, (bool) ($body['overwrite_existing'] ?? true));
        } catch (\InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            api_error('sync_config_copy failed: ' . $e->getMessage(), 500);
        }
        api_ok($res);
    }

    case 'sync_je': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        $body   = api_json_body();
        $opts   = [];
        if (isset($body['limit']))   $opts['limit']   = (int) $body['limit'];
        if (!empty($body['dry_run'])) $opts['dry_run'] = true;
        if (isset($body['je_ids']) && is_array($body['je_ids'])) $opts['je_ids'] = $body['je_ids'];
        try {
            $_zbo = is_array($opts) ? $opts : []; $_zbo["sub_tenant_id"] = _zbSub($tid); $res = zohoBooksSyncJournalEntries($tid, $user['id'] ?? null, $_zbo);
        } catch (\Throwable $e) {
            api_error('sync_je failed: ' . $e->getMessage(), 502);
        }
        api_ok($res);
    }

    case 'sync_accounts': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        try {
            $_zbo = is_array([]) ? [] : []; $_zbo["sub_tenant_id"] = _zbSub($tid); $res = zohoBooksSyncChartOfAccounts($tid, $user['id'] ?? null, $_zbo);
        } catch (\Throwable $e) {
            api_error('sync_accounts failed: ' . $e->getMessage(), 502);
        }
        api_ok($res);
    }

    case 'sync_customers': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        try {
            $_zbo = is_array([]) ? [] : []; $_zbo["sub_tenant_id"] = _zbSub($tid); $res = zohoBooksSyncContactsCustomers($tid, $user['id'] ?? null, $_zbo);
        } catch (\Throwable $e) {
            api_error('sync_customers failed: ' . $e->getMessage(), 502);
        }
        api_ok($res);
    }

    case 'sync_vendors': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        try {
            $_zbo = is_array([]) ? [] : []; $_zbo["sub_tenant_id"] = _zbSub($tid); $res = zohoBooksSyncContactsVendors($tid, $user['id'] ?? null, $_zbo);
        } catch (\Throwable $e) {
            api_error('sync_vendors failed: ' . $e->getMessage(), 502);
        }
        api_ok($res);
    }

    case 'sync_invoices': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        $body = api_json_body();
        $opts = [];
        if (isset($body['limit']))   $opts['limit']   = (int) $body['limit'];
        if (!empty($body['dry_run'])) $opts['dry_run'] = true;
        try {
            $_zbo = is_array($opts) ? $opts : []; $_zbo["sub_tenant_id"] = _zbSub($tid); $res = zohoBooksSyncInvoices($tid, $user['id'] ?? null, $_zbo);
        } catch (\Throwable $e) {
            api_error('sync_invoices failed: ' . $e->getMessage(), 502);
        }
        api_ok($res);
    }

    case 'sync_bills': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        $body = api_json_body();
        $opts = [];
        if (isset($body['limit']))   $opts['limit']   = (int) $body['limit'];
        if (!empty($body['dry_run'])) $opts['dry_run'] = true;
        try {
            $_zbo = is_array($opts) ? $opts : []; $_zbo["sub_tenant_id"] = _zbSub($tid); $res = zohoBooksSyncBills($tid, $user['id'] ?? null, $_zbo);
        } catch (\Throwable $e) {
            api_error('sync_bills failed: ' . $e->getMessage(), 502);
        }
        api_ok($res);
    }

    case 'sync_payments': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        $body = api_json_body();
        $opts = [];
        if (isset($body['limit']))   $opts['limit']   = (int) $body['limit'];
        if (!empty($body['dry_run'])) $opts['dry_run'] = true;
        try {
            $_zbo = is_array($opts) ? $opts : []; $_zbo["sub_tenant_id"] = _zbSub($tid); $res = zohoBooksSyncVendorPayments($tid, $user['id'] ?? null, $_zbo);
        } catch (\Throwable $e) {
            api_error('sync_payments failed: ' . $e->getMessage(), 502);
        }
        api_ok($res);
    }
}

api_error('Unknown action: ' . $action, 400);
