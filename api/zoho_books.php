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
    if (!zohoBooksConsumeOAuthState($tid, $state)) {
        zohoBooksAudit($tid, 'oauth_state_rejected', [
            'ok' => false, 'actor_user_id' => $user['id'] ?? null,
            'detail' => ['state' => substr($state, 0, 8) . '…'],
        ]);
        api_error('Invalid or expired OAuth state. Click "Connect to Zoho Books" again.', 400);
    }
    try {
        zohoBooksExchangeCode($tid, $code, $accountsServer, $user['id'] ?? null);
    } catch (\Throwable $e) {
        api_error('Zoho Books token exchange failed: ' . $e->getMessage(), 502);
    }
    header('Location: /admin/integrations/zoho-books?connected=1');
    exit;
}

// All other actions require auth.
$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

switch ($action) {
    case 'status': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.view');
        $row = zohoBooksConnection($tid);
        $audit = getDB()->prepare(
            'SELECT id, action, entity_type, direction, ok,
                    items_processed, items_skipped, items_failed,
                    detail, occurred_at
               FROM zoho_books_sync_audit
              WHERE tenant_id = :t
           ORDER BY occurred_at DESC
              LIMIT 25'
        );
        $audit->execute(['t' => $tid]);
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
        api_ok([
            'configured'        => zohoBooksConfigured(),
            'connected'         => $isConnected,
            'status'            => $row['status'] ?? null,
            'organization_id'   => $row['organization_id'] ?? null,
            'organization_name' => $row['organization_name'] ?? null,
            'dc'                => $row['dc'] ?? null,
            'scope'             => $row['scope'] ?? null,
            'access_token_exp'  => $row['access_token_exp'] ?? null,
            'last_probe_at'     => $row['last_probe_at']    ?? null,
            'last_probe_error'  => $row['last_probe_error'] ?? null,
            'sync_config'       => zohoBooksSyncConfigRead($tid),
            'entities'          => ZOHO_BOOKS_SYNC_ENTITIES,
            'directions'        => ZOHO_BOOKS_SYNC_DIRECTIONS,
            'audit'             => $rows,
        ]);
    }

    case 'oauth_start': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        try {
            $res = zohoBooksBuildAuthorizeUrl($tid, $user['id'] ?? null);
        } catch (\Throwable $e) {
            api_error($e->getMessage(), 422);
        }
        api_ok(['authorize_url' => $res['url']]);
    }

    case 'disconnect': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        zohoBooksDisconnect($tid, $user['id'] ?? null);
        api_ok(['ok' => true]);
    }

    case 'ping': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        api_ok(zohoBooksPing($tid, $user['id'] ?? null));
    }

    case 'sync_config_get': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.view');
        api_ok([
            'sync_config' => zohoBooksSyncConfigRead($tid),
            'entities'    => ZOHO_BOOKS_SYNC_ENTITIES,
            'directions'  => ZOHO_BOOKS_SYNC_DIRECTIONS,
        ]);
    }

    case 'sync_config_set': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.zoho_books.manage');
        $body = api_json_body();
        $cfg  = $body['sync_config'] ?? [];
        if (!is_array($cfg)) api_error('sync_config must be an object', 422);
        try {
            $saved = zohoBooksSyncConfigWrite($tid, $cfg, $user['id'] ?? null);
        } catch (\InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            api_error('sync_config_set failed: ' . $e->getMessage(), 500);
        }
        api_ok(['sync_config' => $saved]);
    }
}

api_error('Unknown action: ' . $action, 400);
