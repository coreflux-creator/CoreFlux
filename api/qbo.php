<?php
/**
 * QuickBooks Online integration — connect (OAuth), disconnect, status,
 * ping, sync_config (get/set).
 *
 * Routes (all prefixed `/api/qbo/`):
 *   GET    status              — connection state + recent audit + sync_config
 *   GET    oauth_start         — returns Intuit authorize URL + state nonce
 *   GET    oauth_callback      — Intuit redirects here with ?code & ?realmId & ?state
 *   POST   disconnect          — soft-disconnect, best-effort upstream revoke
 *   POST   ping                — auth round-trip via /companyinfo
 *   GET    sync_config_get     — per-entity direction map
 *   POST   sync_config_set     — body: { sync_config: {entity: direction} }
 *   POST   sync_je             — Slice 2: push posted JEs to QBO. Opts:
 *                                 { limit?: int, dry_run?: bool, je_ids?: int[] }
 *   GET    skipped_jes         — Slice 2.5: aggregated view of JEs that
 *                                 sync_je skipped because their account
 *                                 had no QBO mapping. Groups by unresolved
 *                                 account, returning code + name + count
 *                                 + recent JE numbers so a controller can
 *                                 fix the root cause in one click.
 *
 * RBAC: read = `integrations.qbo.view`, write = `integrations.qbo.manage`.
 * Wildcard `integrations.*` from rbac_config covers both for tenant_admin.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/qbo/client.php';
require_once __DIR__ . '/../core/qbo/sync_je.php';
require_once __DIR__ . '/../core/qbo/sync_in.php';

$method = api_method();
$action = (string) (api_query('action') ?? '');
if ($action === '') {
    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH) ?: '';
    if (preg_match('#/qbo/([a-z_-]+)\.php$#i', $path, $m)) {
        $action = strtolower($m[1]);
    }
}
$action = str_replace('-', '_', strtolower($action));

// ---------------------------------------------------------------------
// OAuth callback runs BEFORE the standard auth guard because Intuit
// redirects the browser here with no CoreFlux session header. We re-use
// the SPA session cookie (PHPSESSID) that was minted before the redirect,
// then verify the state nonce.
// ---------------------------------------------------------------------
if ($action === 'oauth_callback') {
    if ($method !== 'GET') api_error('Method not allowed', 405);
    $ctx = api_require_auth();
    $user = $ctx['user'];
    $tid  = (int) $ctx['tenant_id'];

    $code     = (string) (api_query('code')    ?? '');
    $realm    = (string) (api_query('realmId') ?? '');
    $state    = (string) (api_query('state')   ?? '');
    $errParam = (string) (api_query('error')   ?? '');

    if ($errParam !== '') {
        qboAudit($tid, 'oauth_error', [
            'ok' => false, 'actor_user_id' => $user['id'] ?? null,
            'detail' => ['error' => $errParam],
        ]);
        // Redirect back to settings page with the error in a hash fragment.
        header('Location: /admin/integrations/qbo?error=' . urlencode($errParam));
        exit;
    }
    if ($code === '' || $realm === '' || $state === '') {
        api_error('code, realmId, and state are all required', 400);
    }
    if (!qboConsumeOAuthState($tid, $state)) {
        qboAudit($tid, 'oauth_state_rejected', [
            'ok' => false, 'actor_user_id' => $user['id'] ?? null,
            'detail' => ['state' => substr($state, 0, 8) . '…'],
        ]);
        api_error('Invalid or expired OAuth state. Click "Connect to QuickBooks" again.', 400);
    }
    try {
        $res = qboExchangeCode($tid, $code, $realm, $user['id'] ?? null);
    } catch (\Throwable $e) {
        api_error('QBO token exchange failed: ' . $e->getMessage(), 502);
    }
    header('Location: /admin/integrations/qbo?connected=1');
    exit;
}

// All other actions require auth.
$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

switch ($action) {
    case 'status': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.qbo.view');
        $row = qboConnection($tid);
        $audit = getDB()->prepare(
            'SELECT id, action, entity_type, direction, ok,
                    items_processed, items_skipped, items_failed,
                    detail, occurred_at
               FROM qbo_sync_audit
              WHERE tenant_id = :t
           ORDER BY occurred_at DESC
              LIMIT 25'
        );
        $audit->execute(['t' => $tid]);
        $auditRows = array_map(static function ($r) {
            $r['id'] = (int) $r['id'];
            $r['ok'] = (int) $r['ok'] === 1;
            $r['items_processed'] = (int) $r['items_processed'];
            $r['items_skipped']   = (int) $r['items_skipped'];
            $r['items_failed']    = (int) $r['items_failed'];
            $r['detail'] = $r['detail'] !== null ? json_decode((string) $r['detail'], true) : null;
            return $r;
        }, $audit->fetchAll(\PDO::FETCH_ASSOC) ?: []);

        api_ok([
            'configured'        => qboConfigured(),
            'environment'       => qboEnvironment(),
            'connected'         => $row !== null && $row['status'] === 'active',
            'status'            => $row['status'] ?? 'disconnected',
            'realm_id'          => $row['realm_id']     ?? null,
            'company_name'      => $row['company_name'] ?? null,
            'scope'             => $row['scope']        ?? null,
            'access_token_exp'  => $row['access_token_exp']  ?? null,
            'refresh_token_exp' => $row['refresh_token_exp'] ?? null,
            'last_probe_at'     => $row['last_probe_at']     ?? null,
            'last_probe_error'  => $row['last_probe_error']  ?? null,
            'sync_config'       => qboSyncConfigRead($tid),
            'entities'          => QBO_SYNC_ENTITIES,
            'directions'        => QBO_SYNC_DIRECTIONS,
            'recent_audit'      => $auditRows,
        ]);
    }

    case 'oauth_start': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.qbo.manage');
        if (!qboConfigured()) api_error('QuickBooks is not configured on this pod.', 503);
        try {
            $res = qboBuildAuthorizeUrl($tid, $user['id'] ?? null);
        } catch (\Throwable $e) {
            api_error($e->getMessage(), 500);
        }
        api_ok(['authorize_url' => $res['url'], 'state' => $res['state']]);
    }

    case 'disconnect': {
        if (!in_array($method, ['POST', 'DELETE'], true)) api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.qbo.manage');
        qboDisconnect($tid, $user['id'] ?? null);
        api_ok(['ok' => true]);
    }

    case 'ping': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.qbo.manage');
        if (!qboConnection($tid)) api_error('QuickBooks is not connected', 404);
        api_ok(qboPing($tid, $user['id'] ?? null));
    }

    case 'sync_config_get': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.qbo.view');
        api_ok([
            'sync_config' => qboSyncConfigRead($tid),
            'entities'    => QBO_SYNC_ENTITIES,
            'directions'  => QBO_SYNC_DIRECTIONS,
        ]);
    }

    case 'sync_config_set': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.qbo.manage');
        $body   = api_json_body();
        $config = $body['sync_config'] ?? null;
        if (!is_array($config)) api_error('sync_config object required', 422);
        try {
            $merged = qboSyncConfigWrite($tid, $config, $user['id'] ?? null);
        } catch (\InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            api_error($e->getMessage(), 409);
        }
        api_ok(['sync_config' => $merged]);
    }

    case 'sync_je': {
        // Slice 2 — push posted CoreFlux JEs into QBO. Requires
        // sync_config.journal_entries in ('push','two_way').
        if ($method !== 'POST') api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.qbo.manage');
        $body = api_json_body();
        $opts = [];
        if (isset($body['limit']))   $opts['limit']   = (int) $body['limit'];
        if (isset($body['dry_run'])) $opts['dry_run'] = (bool) $body['dry_run'];
        if (isset($body['je_ids']) && is_array($body['je_ids'])) $opts['je_ids'] = $body['je_ids'];
        try {
            $res = qboSyncJournalEntries($tid, $user['id'] ?? null, $opts);
        } catch (\RuntimeException $e) {
            api_error($e->getMessage(), 409);
        } catch (\Throwable $e) {
            api_error('JE sync failed: ' . $e->getMessage(), 502);
        }
        api_ok($res);
    }

    case 'sync_customers':
    case 'sync_vendors': {
        // Slice 3 — pull Customer / Vendor masters from QBO. Requires
        // sync_config.{entity} in ('pull','two_way').
        if ($method !== 'POST') api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.qbo.manage');
        $body = api_json_body();
        $opts = [];
        if (isset($body['limit']))     $opts['limit']     = (int) $body['limit'];
        if (isset($body['max_pages'])) $opts['max_pages'] = (int) $body['max_pages'];
        try {
            $res = $action === 'sync_customers'
                ? qboSyncCustomers($tid, $user['id'] ?? null, $opts)
                : qboSyncVendors($tid, $user['id'] ?? null, $opts);
        } catch (\RuntimeException $e) {
            api_error($e->getMessage(), 409);
        } catch (\Throwable $e) {
            api_error(ucfirst(substr($action, 5)) . ' sync failed: ' . $e->getMessage(), 502);
        }
        api_ok($res);
    }

    case 'skipped_jes': {
        // Slice 2.5 — Skipped JE inbox. Aggregates audit rows with
        // action='sync_je_skip' over the last 30 days, groups by the
        // unresolved account id surfaced in detail.unresolved_account_ids,
        // joins accounting_accounts for code+name, returns actionable rows.
        if ($method !== 'GET') api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.qbo.view');
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT id, detail, occurred_at
               FROM qbo_sync_audit
              WHERE tenant_id = :t
                AND action = 'sync_je_skip'
                AND occurred_at >= (NOW() - INTERVAL 30 DAY)
           ORDER BY occurred_at DESC
              LIMIT 500"
        );
        $stmt->execute(['t' => $tid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $blockers = []; // account_id => { ids:Set<je_id>, jes:[{je_id, last_seen}] }
        $jeNumbers = []; // je_id => je_number (looked up in batch below)
        foreach ($rows as $r) {
            $d = $r['detail'] ? json_decode((string) $r['detail'], true) : null;
            if (!is_array($d)) continue;
            $jeId = (int) ($d['je_id'] ?? 0);
            $unresolved = $d['unresolved_account_ids'] ?? [];
            if (!is_array($unresolved)) continue;
            foreach ($unresolved as $acctId) {
                $acctId = (int) $acctId;
                if ($acctId <= 0) continue;
                if (!isset($blockers[$acctId])) $blockers[$acctId] = ['je_ids' => [], 'most_recent_at' => $r['occurred_at']];
                if (!in_array($jeId, $blockers[$acctId]['je_ids'], true)) {
                    $blockers[$acctId]['je_ids'][] = $jeId;
                }
            }
        }
        if ($blockers) {
            // Resolve JE numbers in one query.
            $allJeIds = [];
            foreach ($blockers as $b) foreach ($b['je_ids'] as $id) $allJeIds[$id] = true;
            if ($allJeIds) {
                $ph = implode(',', array_fill(0, count($allJeIds), '?'));
                $jstmt = $pdo->prepare("SELECT id, je_number FROM accounting_journal_entries WHERE id IN ($ph) AND tenant_id = ?");
                $bind = array_keys($allJeIds);
                $bind[] = $tid;
                $jstmt->execute($bind);
                foreach ($jstmt->fetchAll(\PDO::FETCH_ASSOC) as $j) {
                    $jeNumbers[(int) $j['id']] = (string) $j['je_number'];
                }
            }
            // Resolve account code+name.
            $ph = implode(',', array_fill(0, count($blockers), '?'));
            $astmt = $pdo->prepare("SELECT id, code, name FROM accounting_accounts WHERE id IN ($ph) AND tenant_id = ?");
            $bind = array_keys($blockers); $bind[] = $tid;
            $astmt->execute($bind);
            $acctMeta = [];
            foreach ($astmt->fetchAll(\PDO::FETCH_ASSOC) as $aRow) {
                $acctMeta[(int) $aRow['id']] = ['code' => (string) $aRow['code'], 'name' => (string) $aRow['name']];
            }
        }
        $out = [];
        foreach ($blockers as $acctId => $b) {
            $meta = $acctMeta[$acctId] ?? ['code' => null, 'name' => null];
            $jeShortlist = array_slice($b['je_ids'], 0, 5);
            $out[] = [
                'account_id'        => $acctId,
                'account_code'      => $meta['code'],
                'account_name'      => $meta['name'],
                'blocked_je_count'  => count($b['je_ids']),
                'recent_je_numbers' => array_map(fn($id) => $jeNumbers[$id] ?? ('#' . $id), $jeShortlist),
                'most_recent_at'    => $b['most_recent_at'],
            ];
        }
        // Sort: most-blocked first, then most-recent.
        usort($out, function ($a, $b) {
            return ($b['blocked_je_count'] <=> $a['blocked_je_count'])
                ?: strcmp((string) $b['most_recent_at'], (string) $a['most_recent_at']);
        });
        api_ok([
            'blockers'      => $out,
            'window_days'   => 30,
            'total_skipped' => count($rows),
        ]);
    }
}

api_error('Unknown action: ' . $action, 400);
