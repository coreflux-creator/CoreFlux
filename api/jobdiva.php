<?php
/**
 * JobDiva integration — connect, disconnect, status, ping, sync, webhook.
 * Sprint 8a / Slice A1.
 *
 * Routes (all prefixed `/api/jobdiva/`):
 *   POST   connect       — body: {client_id, username, password, webhook_secret?}
 *   POST   disconnect    — soft-disconnect (preserves audit history)
 *   GET    status        — connection state + recent audit (no secrets)
 *   POST   ping          — round-trip auth check (mints/refreshes token)
 *   POST   sync          — manual "sync now" (A1: no-op, audits + ping)
 *   POST   webhook       — receiver, signature-verified, queues event
 *
 * RBAC: read = `integrations.jobdiva.view`, write = `integrations.jobdiva.manage`.
 * master_admin's `*` covers both.
 *
 * Action selection: ?action=NAME (kebab or snake), defaults from path.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/jobdiva/client.php';

$method = api_method();
$action = (string) (api_query('action') ?? '');
if ($action === '') {
    // Allow last-segment-driven dispatch (e.g. /api/jobdiva/connect → action=connect).
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH) ?: '';
    if (preg_match('#/jobdiva/([a-z_-]+)\.php$#i', $path, $m)) {
        $action = strtolower($m[1]);
    }
}
$action = str_replace('-', '_', strtolower($action));

// Webhook receives signature verification BEFORE auth, since JobDiva
// doesn't have a CoreFlux session.
if ($action === 'webhook') {
    if ($method !== 'POST') api_error('Method not allowed', 405);
    $tid = (int) (api_query('tenant_id') ?? 0);   // tenant identifies itself in URL
    if ($tid <= 0) api_error('tenant_id required', 400);
    $raw = (string) file_get_contents('php://input');
    $sig = (string) ($_SERVER['HTTP_X_JOBDIVA_SIGNATURE'] ?? '');
    $ok  = jobdivaWebhookVerify($tid, $raw, $sig);
    $payload = json_decode($raw, true);
    if (!is_array($payload)) $payload = ['_raw' => $raw];

    $eventId   = (string) ($payload['event_id'] ?? $payload['id'] ?? '');
    $eventType = (string) ($payload['event_type'] ?? $payload['type'] ?? 'unknown');

    try {
        getDB()->prepare(
            'INSERT INTO jobdiva_webhook_events
                (tenant_id, jd_event_id, event_type, payload, signature_ok, status)
             VALUES (:t, :eid, :et, :pl, :sok, :st)
             ON DUPLICATE KEY UPDATE id = id'
        )->execute([
            't'   => $tid,
            'eid' => $eventId !== '' ? $eventId : null,
            'et'  => $eventType,
            'pl'  => json_encode($payload),
            'sok' => (int) $ok,
            'st'  => $ok ? 'queued' : 'skipped',
        ]);
    } catch (\Throwable $e) {
        api_error('Webhook persist failed: ' . $e->getMessage(), 500);
    }
    jobdivaAudit($tid, 'webhook', [
        'ok' => $ok, 'direction' => 'pull',
        'detail' => ['event_type' => $eventType, 'jd_event_id' => $eventId, 'signature_ok' => $ok],
    ]);
    if (!$ok) api_error('Invalid signature', 401);
    api_ok(['ok' => true, 'queued' => true, 'event_type' => $eventType]);
}

// Everything else requires CoreFlux auth.
$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

switch ($action) {
    case 'status': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.jobdiva.view');
        $row = jobdivaConnection($tid);
        if (!$row) {
            api_ok([
                'connected'      => false,
                'status'         => 'disconnected',
                'recent_events'  => [],
                'recent_audit'   => [],
                'webhook_url'    => jobdivaWebhookUrl($tid),
            ]);
        }
        // Recent audit (last 25) + recent webhook events (last 10).
        $audit = getDB()->prepare(
            'SELECT id, action, entity_type, direction, ok,
                    items_processed, items_skipped, items_failed,
                    detail, occurred_at
               FROM jobdiva_sync_audit
              WHERE tenant_id = :t
           ORDER BY occurred_at DESC
              LIMIT 25'
        );
        $audit->execute(['t' => $tid]);
        $auditRows = array_map(static function ($r) {
            $r['id']  = (int) $r['id'];
            $r['ok']  = (int) $r['ok'] === 1;
            $r['items_processed'] = (int) $r['items_processed'];
            $r['items_skipped']   = (int) $r['items_skipped'];
            $r['items_failed']    = (int) $r['items_failed'];
            $r['detail'] = $r['detail'] !== null ? json_decode((string) $r['detail'], true) : null;
            return $r;
        }, $audit->fetchAll(\PDO::FETCH_ASSOC) ?: []);

        $whEvents = getDB()->prepare(
            'SELECT id, jd_event_id, event_type, signature_ok, status,
                    process_error, received_at, processed_at
               FROM jobdiva_webhook_events
              WHERE tenant_id = :t
           ORDER BY received_at DESC
              LIMIT 10'
        );
        $whEvents->execute(['t' => $tid]);

        api_ok([
            'connected'         => $row['status'] !== 'disconnected',
            'status'            => $row['status'],
            'client_id'         => $row['client_id'],
            'username'          => $row['username'],
            'has_webhook_secret'=> !empty($row['webhook_secret_enc']),
            'last_sync_at'      => $row['last_sync_at'],
            'last_sync_error'   => $row['last_sync_error'],
            'last_ping_at'      => $row['last_ping_at'],
            'session_token_exp' => $row['session_token_exp'],
            'webhook_url'       => jobdivaWebhookUrl($tid),
            'recent_audit'      => $auditRows,
            'recent_events'     => $whEvents->fetchAll(\PDO::FETCH_ASSOC) ?: [],
        ]);
    }

    case 'connect': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.jobdiva.manage');
        $body = api_json_body();
        try {
            $res = jobdivaSaveConnection($tid, [
                'client_id'      => (string) ($body['client_id']      ?? ''),
                'username'       => (string) ($body['username']       ?? ''),
                'password'       => (string) ($body['password']       ?? ''),
                'webhook_secret' => isset($body['webhook_secret']) ? (string) $body['webhook_secret'] : null,
            ], $user['id'] ?? null);
        } catch (\InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        }
        // Verify by minting a session token. If JobDiva rejects, we still
        // keep the row so the user can fix it — but mark error.
        $ping = jobdivaPing($tid, $user['id'] ?? null);
        api_ok([
            'ok'         => $ping['ok'],
            'connection' => $res,
            'ping'       => $ping,
            'webhook_url'=> jobdivaWebhookUrl($tid),
        ]);
    }

    case 'disconnect': {
        if (!in_array($method, ['POST', 'DELETE'], true)) api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.jobdiva.manage');
        jobdivaDisconnect($tid, $user['id'] ?? null);
        api_ok(['ok' => true]);
    }

    case 'ping': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.jobdiva.manage');
        $row = jobdivaConnection($tid);
        if (!$row) api_error('JobDiva is not connected', 404);
        api_ok(jobdivaPing($tid, $user['id'] ?? null));
    }

    case 'sync': {
        // Slice A1: manual "sync now" is a no-op that just refreshes the
        // session token and writes an audit row. Entity sync wiring lands
        // in A2 (companies + contacts).
        if ($method !== 'POST') api_error('Method not allowed', 405);
        RBAC::requirePermission($user, 'integrations.jobdiva.manage');
        $row = jobdivaConnection($tid);
        if (!$row) api_error('JobDiva is not connected', 404);
        $ping = jobdivaPing($tid, $user['id'] ?? null);
        getDB()->prepare(
            'UPDATE jobdiva_connections SET last_sync_at = NOW() WHERE tenant_id = :t'
        )->execute(['t' => $tid]);
        jobdivaAudit($tid, 'sync', [
            'actor_user_id' => $user['id'] ?? null,
            'ok'            => $ping['ok'],
            'detail'        => ['note' => 'Slice A1 placeholder — entity sync arrives in A2'],
        ]);
        api_ok([
            'ok'   => $ping['ok'],
            'note' => 'Slice A1 — manual sync is wired but entity pipelines (companies, contacts, placements, time) ship in subsequent slices.',
            'ping' => $ping,
        ]);
    }
}

api_error('Unknown action: ' . $action, 400);

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function jobdivaWebhookUrl(int $tenantId): string
{
    $base = (defined('CF_PUBLIC_BASE_URL') ? CF_PUBLIC_BASE_URL
                                            : (getenv('CF_PUBLIC_BASE_URL') ?: ''));
    if ($base === '') return "/api/jobdiva/webhook.php?tenant_id={$tenantId}";
    return rtrim($base, '/') . "/api/jobdiva/webhook.php?tenant_id={$tenantId}";
}
