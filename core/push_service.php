<?php
/**
 * Tenant-scoped push notification primitive (Sprint 3 / CORE).
 *
 *   pushSendToUser($tenantId, $userId, $title, $body, $data?, $opts?)
 *   pushSendToTenant($tenantId, $title, $body, $data?, $opts?)
 *   pushDispatchOutbox($limit)   (worker entry — cron + queue both call this)
 *
 * Driver model: pluggable. The "log" driver is the default and is always
 * safe to invoke (writes a row to tenant_push_outbox + an error_log line +
 * marks status='delivered'). Real APNs/FCM dispatch wires in via:
 *
 *   • env APNS_AUTH_KEY_PATH / APNS_KEY_ID / APNS_TEAM_ID / APNS_BUNDLE_ID
 *   • env FCM_SERVICE_ACCOUNT_JSON (path) or FCM_SERVER_KEY (legacy)
 *
 * When creds are absent we silently fall back to the log driver — never
 * crash a user-facing approval flow because pushes aren't configured.
 *
 * VERTICAL-AGNOSTIC. Only knows about tenants + users + devices.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PUSH_DRIVER_LOG  = 'log';
const PUSH_DRIVER_APNS = 'apns';
const PUSH_DRIVER_FCM  = 'fcm';

/**
 * Send a push to all active devices of a single user. Always returns
 * the number of devices the message was queued/sent to (0 if user has
 * no devices). Never throws — push failures must never block the
 * caller's main flow (approval, post, etc.).
 *
 * @param array $opts {
 *   @var string $category       e.g. 'ap_bill_approval'
 *   @var string $deep_link      e.g. '/modules/ap/bills/123'
 *   @var string $source_module  e.g. 'ap'
 *   @var string $source_event   e.g. 'bill.routed_for_approval'
 *   @var string $source_ref_type, int $source_ref_id
 * }
 */
function pushSendToUser(int $tenantId, int $userId, string $title, string $body, array $data = [], array $opts = []): int {
    $pdo = getDB();
    if (!$pdo) return 0;

    // Look up active devices.
    $stmt = $pdo->prepare(
        "SELECT id, device_id, platform, apns_token, fcm_token
           FROM tenant_mobile_devices
          WHERE tenant_id = :t AND user_id = :u AND revoked_at IS NULL"
    );
    $stmt->execute(['t' => $tenantId, 'u' => $userId]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Even if user has zero devices, queue ONE row so the outbox has a
    // record of the intent (handy for "you would have been notified" UX
    // and for late-arriving devices to backfill on first connect).
    $rows = $devices ?: [['device_id' => null, 'platform' => null, 'apns_token' => null, 'fcm_token' => null]];

    $count = 0;
    foreach ($rows as $d) {
        $driver = _pushPickDriver((string) ($d['platform'] ?? ''), (string) ($d['apns_token'] ?? ''), (string) ($d['fcm_token'] ?? ''));
        $ins = $pdo->prepare(
            "INSERT INTO tenant_push_outbox
              (tenant_id, user_id, device_id, title, body, data_json, category, deep_link,
               driver, status, source_module, source_event, source_ref_type, source_ref_id, created_at)
             VALUES
              (:t, :u, :d, :ti, :bo, :dj, :ca, :dl,
               :dr, 'queued', :sm, :se, :st, :si, NOW())"
        );
        $ins->execute([
            't'  => $tenantId,
            'u'  => $userId,
            'd'  => $d['device_id'] ?? null,
            'ti' => substr($title, 0, 255),
            'bo' => $body,
            'dj' => $data ? json_encode($data, JSON_UNESCAPED_SLASHES) : null,
            'ca' => $opts['category'] ?? null,
            'dl' => $opts['deep_link'] ?? null,
            'dr' => $driver,
            'sm' => substr((string) ($opts['source_module'] ?? 'system'), 0, 40),
            'se' => $opts['source_event'] ?? null,
            'st' => $opts['source_ref_type'] ?? null,
            'si' => isset($opts['source_ref_id']) ? (int) $opts['source_ref_id'] : null,
        ]);
        $outboxId = (int) $pdo->lastInsertId();

        // Synchronous fast-path: if log driver, mark delivered immediately so
        // the outbox doesn't accumulate 'queued' rows in dev.
        if ($driver === PUSH_DRIVER_LOG) {
            try { _pushDriverLog($outboxId, $tenantId, $userId, $title, $body, $data); } catch (\Throwable $_) {}
        }
        $count++;
    }
    return $count;
}

/**
 * Fan-out helper — push to every active user of a tenant matching $rolesOrPerms.
 *
 * @param array $rolesOrPerms ['admin', 'tenant_admin']  (role match)
 *                            OR ['perm:ap.approve']     (permission match — Sprint 4 wiring)
 */
function pushSendToTenant(int $tenantId, string $title, string $body, array $data = [], array $rolesOrPerms = [], array $opts = []): int {
    $pdo = getDB();
    if (!$pdo) return 0;
    $where = "ut.tenant_id = :t AND ut.status = 'active'";
    $params = ['t' => $tenantId];
    if ($rolesOrPerms) {
        $roleList = array_values(array_filter($rolesOrPerms, fn($r) => stripos($r, 'perm:') !== 0));
        if ($roleList) {
            $in = [];
            foreach ($roleList as $i => $r) { $k = "r{$i}"; $in[] = ":{$k}"; $params[$k] = $r; }
            $where .= " AND ut.role IN (" . implode(',', $in) . ")";
        }
    }
    $stmt = $pdo->prepare("SELECT DISTINCT user_id FROM user_tenants ut WHERE $where");
    $stmt->execute($params);
    $userIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'user_id'));
    $count = 0;
    foreach ($userIds as $uid) {
        $count += pushSendToUser($tenantId, $uid, $title, $body, $data, $opts);
    }
    return $count;
}

/**
 * Drain the outbox — invoked by a cron / queue worker. Each row is
 * dispatched through its picked driver. Returns counts by status.
 */
function pushDispatchOutbox(int $limit = 50): array {
    $pdo = getDB();
    if (!$pdo) return ['fetched' => 0, 'delivered' => 0, 'failed' => 0];
    $stmt = $pdo->prepare(
        "SELECT * FROM tenant_push_outbox
          WHERE status = 'queued' AND attempts < 5
          ORDER BY id ASC LIMIT :n"
    );
    $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $delivered = $failed = 0;
    foreach ($rows as $r) {
        try {
            switch ($r['driver']) {
                case PUSH_DRIVER_APNS: _pushDriverApns($r); $delivered++; break;
                case PUSH_DRIVER_FCM:  _pushDriverFcm($r);  $delivered++; break;
                default:               _pushDriverLog((int) $r['id'], (int) $r['tenant_id'], (int) $r['user_id'], $r['title'], $r['body'], $r['data_json'] ? (array) json_decode((string) $r['data_json'], true) : []); $delivered++;
            }
        } catch (\Throwable $e) {
            $failed++;
            $upd = $pdo->prepare("UPDATE tenant_push_outbox SET status='failed', attempts=attempts+1, last_error=:e, failed_at=NOW() WHERE id=:id");
            $upd->execute(['e' => substr($e->getMessage(), 0, 1000), 'id' => (int) $r['id']]);
        }
    }
    return ['fetched' => count($rows), 'delivered' => $delivered, 'failed' => $failed];
}

/* ---------------------------------------------------------------------- */
/* Drivers                                                                 */
/* ---------------------------------------------------------------------- */

/** @internal */
function _pushPickDriver(string $platform, string $apnsToken, string $fcmToken): string {
    $apnsConfigured = (bool) getenv('APNS_AUTH_KEY_PATH');
    $fcmConfigured  = (bool) (getenv('FCM_SERVICE_ACCOUNT_JSON') ?: getenv('FCM_SERVER_KEY'));
    if ($platform === 'ios'      && $apnsToken && $apnsConfigured) return PUSH_DRIVER_APNS;
    if ($platform === 'android'  && $fcmToken  && $fcmConfigured)  return PUSH_DRIVER_FCM;
    if ($platform === 'web'      && $fcmToken  && $fcmConfigured)  return PUSH_DRIVER_FCM;
    return PUSH_DRIVER_LOG;
}

/** @internal Log driver — always available. */
function _pushDriverLog(int $outboxId, int $tenantId, int $userId, string $title, string $body, array $data): void {
    error_log(sprintf('[push:log] outbox#%d tenant=%d user=%d title=%s body=%s data=%s',
        $outboxId, $tenantId, $userId, $title, $body, json_encode($data, JSON_UNESCAPED_SLASHES)));
    $pdo = getDB();
    if (!$pdo) return;
    $upd = $pdo->prepare("UPDATE tenant_push_outbox SET status='delivered', delivered_at=NOW(), attempts=attempts+1 WHERE id=:id");
    $upd->execute(['id' => $outboxId]);
}

/** @internal APNs driver — requires APNS_* env. Stub-throws until creds wired. */
function _pushDriverApns(array $row): void {
    $key = getenv('APNS_AUTH_KEY_PATH');
    if (!$key) throw new \RuntimeException('APNS_AUTH_KEY_PATH not configured');
    // TODO: real HTTP/2 dispatch to api.push.apple.com — stubbed.
    throw new \RuntimeException('APNs driver not wired yet (creds available, dispatch pending Sprint 5 mobile build)');
}

/** @internal FCM driver — requires FCM_* env. Stub-throws until creds wired. */
function _pushDriverFcm(array $row): void {
    $cfg = getenv('FCM_SERVICE_ACCOUNT_JSON') ?: getenv('FCM_SERVER_KEY');
    if (!$cfg) throw new \RuntimeException('FCM credentials not configured');
    // TODO: real POST to fcm.googleapis.com — stubbed.
    throw new \RuntimeException('FCM driver not wired yet (creds available, dispatch pending Sprint 5 mobile build)');
}
