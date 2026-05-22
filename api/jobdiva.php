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
require_once __DIR__ . '/../core/jobdiva/sync.php';

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
    // Backwards-compatible primary header; the verifier itself looks at
    // X-Hub-Signature (JobDiva's actual default), X-Hub-Signature-256, and
    // the legacy X-JobDiva-Signature, so passing any one — or none — works.
    $sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE']
                  ?? $_SERVER['HTTP_X_HUB_SIGNATURE_256']
                  ?? $_SERVER['HTTP_X_JOBDIVA_SIGNATURE']
                  ?? '');
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

    // Real-time placement ingestion. JobDiva fires `start.created` /
    // `start.updated` (and historically `placement.*`) when a recruiter
    // saves a start in the JobDiva UI. We process inline so the operator
    // doesn't have to wait for the next manual sync. If JobDiva sends the
    // full record in `payload.data`, we ingest it directly; otherwise we
    // re-fetch via searchStart using the start ID in the event.
    $eventLc = strtolower($eventType);
    if (strpos($eventLc, 'placement') !== false || strpos($eventLc, 'start') !== false) {
        require_once __DIR__ . '/../core/jobdiva/sync.php';
        require_once __DIR__ . '/../core/jobdiva/sync_placements.php';
        try {
            $record = $payload['data'] ?? $payload['record'] ?? $payload['placement'] ?? $payload['start'] ?? null;
            $items  = [];
            if (is_array($record)) {
                $items = [$record];
            } else {
                $startId = (string) (
                    $payload['startId']
                    ?? $payload['placementId']
                    ?? $payload['id']
                    ?? ($payload['data']['id'] ?? '')
                );
                if ($startId !== '') {
                    $resp = jobdivaCall($tid, 'POST', JOBDIVA_PATH_SEARCH_START, ['startId' => $startId]);
                    $items = jobdivaPlacementsExtractList($resp);
                }
            }
            if (count($items) > 0) {
                $result = jobdivaSyncPlacements($tid, null, ['items_override' => $items, '_webhook' => true]);
                getDB()->prepare(
                    'UPDATE jobdiva_webhook_events
                        SET status = "processed", processed_at = NOW()
                      WHERE tenant_id = :t AND id = LAST_INSERT_ID()'
                )->execute(['t' => $tid]);
                api_ok([
                    'ok' => true, 'queued' => false, 'processed' => true,
                    'event_type' => $eventType, 'placement_result' => $result,
                ]);
            }
        } catch (\Throwable $e) {
            // Webhook ingestion failure shouldn't 5xx — the event row is
            // still queued and the operator can re-process via Sync now.
            getDB()->prepare(
                'UPDATE jobdiva_webhook_events
                    SET status = "error", process_error = :err, processed_at = NOW()
                  WHERE tenant_id = :t AND jd_event_id = :eid'
            )->execute(['t' => $tid, 'eid' => $eventId !== '' ? $eventId : '', 'err' => substr($e->getMessage(), 0, 500)]);
            jobdivaAudit($tid, 'webhook_process_failed', [
                'ok' => false, 'direction' => 'pull',
                'detail' => ['event_type' => $eventType, 'error' => substr($e->getMessage(), 0, 500)],
            ]);
        }
    }

    api_ok(['ok' => true, 'queued' => true, 'event_type' => $eventType]);
}

// Everything else requires CoreFlux auth.
$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

switch ($action) {
    case 'status': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.jobdiva.view');
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
            'sync_config'       => jobdivaSyncConfigRead($tid),
            'webhook_url'       => jobdivaWebhookUrl($tid),
            'recent_audit'      => $auditRows,
            'recent_events'     => $whEvents->fetchAll(\PDO::FETCH_ASSOC) ?: [],
        ]);
    }

    case 'connect': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.jobdiva.manage');
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
        rbac_legacy_require($user, 'integrations.jobdiva.manage');
        jobdivaDisconnect($tid, $user['id'] ?? null);
        api_ok(['ok' => true]);
    }

    case 'ping': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.jobdiva.manage');
        $row = jobdivaConnection($tid);
        if (!$row) api_error('JobDiva is not connected', 404);
        api_ok(jobdivaPing($tid, $user['id'] ?? null));
    }

    case 'sync': {
        // Slice A3: pulls Companies, Contacts, Placements via the agnostic
        // entity-mapping pipeline. NO candidates / applicants / open positions.
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.jobdiva.manage');
        $row = jobdivaConnection($tid);
        if (!$row) api_error('JobDiva is not connected', 404);
        $body = api_json_body();
        $opts = [];
        if (!empty($body['modified_since'])) $opts['modified_since'] = (string) $body['modified_since'];
        try {
            $result = jobdivaSyncAll($tid, $user['id'] ?? null, $opts);
        } catch (\Throwable $e) {
            jobdivaAudit($tid, 'sync', [
                'ok' => false, 'actor_user_id' => $user['id'] ?? null,
                'detail' => ['error' => substr($e->getMessage(), 0, 500)],
            ]);
            api_error('Sync failed: ' . $e->getMessage(), 502);
        }
        api_ok([
            'ok'         => true,
            'counts'     => $result['counts'],
            'total'      => $result['total'],
            'latency_ms' => $result['latency_ms'],
            'by_entity'  => $result['by_entity'],
        ]);
    }

    case 'sync_config_get': {
        // Slice A4: per-entity sync config. Returns the merged config
        // (stored ∪ defaults) so the UI can render every entity row.
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.jobdiva.view');
        api_ok([
            'sync_config' => jobdivaSyncConfigRead($tid),
            'entities'    => JOBDIVA_SYNC_ENTITIES,
            'sources'     => JOBDIVA_SYNC_SOURCES,
            'directions'  => JOBDIVA_SYNC_DIRECTIONS,
        ]);
    }

    case 'sync_config_set': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.jobdiva.manage');
        $body = api_json_body();
        $config = $body['sync_config'] ?? null;
        if (!is_array($config)) api_error('sync_config object required', 422);
        try {
            $merged = jobdivaSyncConfigWrite($tid, $config, $user['id'] ?? null);
        } catch (\InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        }
        api_ok(['sync_config' => $merged]);
    }
}

api_error('Unknown action: ' . $action, 400);

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function jobdivaWebhookUrl(int $tenantId): string
{
    // Prefer the explicit constant / env if the operator set one.
    $base = (defined('CF_PUBLIC_BASE_URL') ? CF_PUBLIC_BASE_URL
                                            : (getenv('CF_PUBLIC_BASE_URL') ?: ''));

    // Otherwise derive the absolute origin from the current request so the
    // URL pasted into JobDiva is something JobDiva can actually call.
    // Returning a relative path here used to leak `/api/jobdiva/webhook.php?...`
    // into the tenant UI, which JobDiva would reject on save.
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                  || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                  || (($_SERVER['SERVER_PORT'] ?? '') === '443')
                ? 'https' : 'http';
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if ($host !== '') $base = $scheme . '://' . $host;
    }

    $path = "/api/jobdiva/webhook.php?tenant_id={$tenantId}";
    return $base === '' ? $path : rtrim($base, '/') . $path;
}
