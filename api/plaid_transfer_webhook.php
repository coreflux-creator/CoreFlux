<?php
/**
 * /api/plaid_transfer_webhook.php — dedicated webhook for Plaid Transfer
 * events, separate from /api/plaid_webhook.php so transfer event traffic
 * doesn't co-mingle with item/transactions updates.
 *
 * Configured in Plaid Dashboard under "Transfer → Webhook URL". Plaid sends
 * `TRANSFER_EVENTS_UPDATE` notifications whenever new transfer events
 * (pending / posted / settled / returned / failed) are available; we then
 * call /transfer/event/sync to fetch the deltas.
 *
 * Pattern matches plaid_webhook.php: verify JWT, persist for audit, ALWAYS
 * return 200 so Plaid doesn't retry-storm us.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/plaid_service.php';
require_once __DIR__ . '/../core/plaid_transfer_sync.php';

$rawBody = (string) file_get_contents('php://input');
$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$jwt     = (string) ($headers['plaid-verification'] ?? '');

$verified = $jwt !== '' && plaidVerifyWebhook($jwt, $rawBody);
$payload  = json_decode($rawBody, true) ?: [];
$type     = (string) ($payload['webhook_type'] ?? '');
$code     = (string) ($payload['webhook_code'] ?? '');

// Persist (audit/replay regardless of verification).
$eventId = null;
try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO plaid_webhook_events
            (tenant_id, plaid_item_pk, item_id_external, webhook_type, webhook_code,
             payload_json, signature_verified)
         VALUES (NULL, NULL, NULL, :wt, :wc, :pj, :sv)'
    );
    $stmt->execute([
        'wt' => $type ?: 'UNKNOWN',
        'wc' => $code ?: 'UNKNOWN',
        'pj' => $rawBody,
        'sv' => $verified ? 1 : 0,
    ]);
    $eventId = (int) $pdo->lastInsertId();
} catch (\Throwable $e) {
    error_log('[plaid.transfer.webhook] persist failed: ' . $e->getMessage());
}

if (!$verified) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'reason' => 'signature_invalid', 'event_id' => $eventId]);
    exit;
}

$err = null;
$syncedTenants = 0;
$totalFetched  = 0;
$totalUpdated  = 0;

try {
    if ($type === 'TRANSFER' && in_array($code, ['TRANSFER_EVENTS_UPDATE', 'RECURRING_NEW_TRANSFER'], true)) {
        // Plaid doesn't include tenant_id in the webhook (we only sent one
        // webhook URL, and the events are scoped by client_id+secret which
        // is global). The /transfer/event/sync endpoint returns events for
        // ALL transfers we've originated, so we need to sync for every
        // tenant that has linked Plaid Transfer.
        $pdo  = getDB();
        $stmt = $pdo->query(
            "SELECT DISTINCT tenant_id FROM tenant_payment_rails
              WHERE rail = 'plaid_transfer' AND status = 'linked'"
        );
        while (($tid = $stmt->fetchColumn()) !== false) {
            $r = plaidTransferSync((int) $tid);
            $syncedTenants++;
            $totalFetched += (int) $r['fetched'];
            $totalUpdated += (int) $r['updated_payments'];
        }
    }
    if ($eventId) {
        // tenant-leak-allow: webhook row was just inserted; UPDATE by primary id
        getDB()->prepare(
            'UPDATE plaid_webhook_events SET processed_at = NOW() WHERE id = :id'
        )->execute(['id' => $eventId]);
    }
} catch (\Throwable $e) {
    $err = $e->getMessage();
    error_log('[plaid.transfer.webhook] route failed: ' . $err);
    if ($eventId) {
        try {
            // tenant-leak-allow: webhook row was just inserted; UPDATE by primary id
            getDB()->prepare(
                'UPDATE plaid_webhook_events SET error_message = :m WHERE id = :id'
            )->execute(['m' => substr($err, 0, 500), 'id' => $eventId]);
        } catch (\Throwable $e2) {}
    }
}

http_response_code(200);
echo json_encode([
    'ok'              => true,
    'event_id'        => $eventId,
    'verified'        => true,
    'processed'       => $err === null,
    'synced_tenants'  => $syncedTenants,
    'fetched'         => $totalFetched,
    'updated_payments' => $totalUpdated,
]);
