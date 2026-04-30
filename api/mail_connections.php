<?php
/**
 * Platform API — tenant mail inbox connections (M365 OAuth, list, revoke).
 *
 *   GET  /api/mail_connections.php
 *        → { connections: [...], folders: [...] }   (per current tenant)
 *
 *   POST /api/mail_connections.php?action=oauth_start&provider=m365
 *        → { authorize_url, state }
 *           Stores PKCE verifier in $_SESSION; caller redirects browser to
 *           the authorize URL.
 *
 *   POST /api/mail_connections.php?action=watch_folder
 *        body: { connection_id, folder_id_at_provider, folder_path, module }
 *        → { folder_id }
 *
 *   POST /api/mail_connections.php?action=poll_now&folder_id=N
 *        → { messages_seen, next_cursor, sample: [...] }
 *
 *   DELETE /api/mail_connections.php?id=N
 *        → revokes connection (soft delete)
 *
 * Gated by 'tenant.manage' for write actions; 'tenant.view' for reads.
 */
require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/mail/M365GraphDriver.php';

use Core\Mail\M365GraphDriver;

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

function _m365_check_env(): void {
    foreach (['MICROSOFT_CLIENT_ID', 'MICROSOFT_CLIENT_SECRET', 'MICROSOFT_REDIRECT_URI'] as $k) {
        if (!getenv($k)) {
            api_error("{$k} not configured. Set it in Cloudways environment variables and restart PHP-FPM.", 503);
        }
    }
}

if ($method === 'GET') {
    RBAC::requirePermission($user, 'tenant.manage');
    $pdo = getDB();
    $cs = $pdo->prepare(
        'SELECT id, provider, purpose, display_name, account_address, oauth_expires_at, status, error_message, created_at
         FROM tenant_mail_connections WHERE tenant_id = :tid ORDER BY id DESC'
    );
    $cs->execute(['tid' => $tid]);
    $connections = $cs->fetchAll(\PDO::FETCH_ASSOC);

    $fs = $pdo->prepare(
        'SELECT id, connection_id, module, folder_path, folder_id_at_provider,
                polling_enabled, polling_interval_seconds, last_polled_at, last_message_cursor
         FROM tenant_mail_folders WHERE tenant_id = :tid ORDER BY id DESC'
    );
    $fs->execute(['tid' => $tid]);
    $folders = $fs->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($folders as &$f) {
        $f['has_cursor'] = !empty($f['last_message_cursor']);
        unset($f['last_message_cursor']); // never expose raw cursor in UI
    }
    api_ok(['connections' => $connections, 'folders' => $folders]);
}

if ($method === 'POST' && $action === 'oauth_start') {
    RBAC::requirePermission($user, 'tenant.manage');
    $provider = $_GET['provider'] ?? 'm365';
    if ($provider !== 'm365') api_error('Only m365 supported in this drop', 400);
    _m365_check_env();

    $verifier = bin2hex(random_bytes(32));
    $state    = bin2hex(random_bytes(16));
    $_SESSION['m365_oauth'] = [
        'verifier'  => $verifier,
        'state'     => $state,
        'tenant_id' => $tid,
        'user_id'   => $user['id'] ?? null,
        'expires'   => time() + 600, // 10-minute window
    ];

    $driver = new M365GraphDriver();
    $url    = $driver->build_authorize_url($state, $verifier);
    api_ok(['authorize_url' => $url, 'state' => $state]);
}

if ($method === 'POST' && $action === 'list_folders') {
    RBAC::requirePermission($user, 'tenant.manage');
    $cid = (int) ($_GET['connection_id'] ?? 0);
    if ($cid <= 0) api_error('connection_id required', 400);
    $row = scopedFind('SELECT id FROM tenant_mail_connections WHERE tenant_id = :tenant_id AND id = :id', ['id' => $cid]);
    if (!$row) api_error('Connection not found', 404);
    _m365_check_env();
    $driver = new M365GraphDriver();
    $folders = $driver->list_mail_folders($cid, isset($_GET['parent']) ? (string) $_GET['parent'] : null);
    api_ok(['folders' => $folders]);
}

if ($method === 'POST' && $action === 'watch_folder') {
    RBAC::requirePermission($user, 'tenant.manage');
    $body = api_json_body();
    api_require_fields($body, ['connection_id', 'folder_id_at_provider', 'folder_path', 'module']);
    $cid = (int) $body['connection_id'];
    $row = scopedFind('SELECT id FROM tenant_mail_connections WHERE tenant_id = :tenant_id AND id = :id', ['id' => $cid]);
    if (!$row) api_error('Connection not found', 404);
    if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', (string) $body['module'])) api_error('module slug invalid', 422);

    $pdo = getDB();
    // Upsert by (connection_id, module, folder_path)
    $existing = scopedFind(
        'SELECT id FROM tenant_mail_folders WHERE tenant_id = :tenant_id
         AND connection_id = :cid AND module = :m AND folder_path = :p',
        ['cid' => $cid, 'm' => $body['module'], 'p' => $body['folder_path']]
    );
    if ($existing) {
        $pdo->prepare('UPDATE tenant_mail_folders SET folder_id_at_provider = :fid, polling_enabled = 1 WHERE id = :id')
            ->execute(['fid' => $body['folder_id_at_provider'], 'id' => $existing['id']]);
        api_ok(['folder_id' => (int) $existing['id']]);
    }
    $newId = scopedInsert('tenant_mail_folders', [
        'connection_id'             => $cid,
        'module'                    => $body['module'],
        'folder_path'               => $body['folder_path'],
        'folder_id_at_provider'     => $body['folder_id_at_provider'],
        'polling_enabled'           => 1,
        'polling_interval_seconds'  => (int) ($body['polling_interval_seconds'] ?? 600),
    ]);
    api_ok(['folder_id' => $newId], 201);
}

if ($method === 'POST' && $action === 'poll_now') {
    RBAC::requirePermission($user, 'tenant.manage');
    $fid = (int) ($_GET['folder_id'] ?? 0);
    if ($fid <= 0) api_error('folder_id required', 400);
    $row = scopedFind('SELECT id FROM tenant_mail_folders WHERE tenant_id = :tenant_id AND id = :id', ['id' => $fid]);
    if (!$row) api_error('Folder not found', 404);
    _m365_check_env();
    $driver = new M365GraphDriver();
    $res = $driver->poll($fid, null); // cursor read from row
    $sample = array_slice($res['messages'] ?? [], 0, 25);
    api_ok([
        'messages_seen' => count($res['messages'] ?? []),
        'next_cursor'   => $res['next_cursor'] ?: null,
        'sample'        => $sample,
    ]);
}

if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'tenant.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $row = scopedFind('SELECT id FROM tenant_mail_connections WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);

    $pdo = getDB();
    $pdo->prepare('UPDATE tenant_mail_connections SET status = "revoked", error_message = NULL WHERE id = :id AND tenant_id = :tid')
        ->execute(['id' => $id, 'tid' => $tid]);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
