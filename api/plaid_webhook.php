<?php
/**
 * Plaid — POST /api/plaid_webhook
 *
 * Verifies the JWT signature on the Plaid-Verification header, persists
 * the event to plaid_webhook_events, and routes by webhook_type:
 *
 *   ITEM         → ITEM_LOGIN_REQUIRED / PENDING_EXPIRATION → mark requires_update
 *                  USER_PERMISSION_REVOKED → mark revoked
 *   TRANSACTIONS → SYNC_UPDATES_AVAILABLE → triggers sync (queued by default)
 *   AUTH         → DEFAULT_UPDATE → log only (treasurer should re-run /auth/get)
 *
 * ALWAYS returns 200 to ack receipt — Plaid stops retrying on non-200 within
 * 24h, but a non-200 here would cause amplified retry storms.
 *
 * IMPORTANT: this endpoint deliberately skips the api_require_auth gate —
 * Plaid is the caller, not a logged-in user. Verification is via JWT.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/plaid_service.php';

$rawBody = (string) file_get_contents('php://input');
$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$jwt     = (string) ($headers['plaid-verification'] ?? '');

$verified = $jwt !== '' && plaidVerifyWebhook($jwt, $rawBody);
$payload  = json_decode($rawBody, true) ?: [];
$type     = (string) ($payload['webhook_type'] ?? '');
$code     = (string) ($payload['webhook_code'] ?? '');
$itemIdEx = (string) ($payload['item_id']      ?? '');

// Match item to a tenant before logging, when possible.
$item = null;
if ($itemIdEx !== '') {
    try {
        $stmt = getDB()->prepare(
            'SELECT id, tenant_id FROM plaid_items WHERE item_id = :iid LIMIT 1'
        );
        $stmt->execute(['iid' => $itemIdEx]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) $item = $row;
    } catch (\Throwable $e) { /* fall through */ }
}

// Persist for audit / replay regardless of verification result.
$eventId = null;
try {
    $stmt = getDB()->prepare(
        'INSERT INTO plaid_webhook_events
            (tenant_id, plaid_item_pk, item_id_external, webhook_type, webhook_code,
             payload_json, signature_verified)
         VALUES (:t, :pk, :iid, :wt, :wc, :pj, :sv)'
    );
    $stmt->execute([
        't'   => $item['tenant_id'] ?? null,
        'pk'  => $item['id']        ?? null,
        'iid' => $itemIdEx ?: null,
        'wt'  => $type ?: 'UNKNOWN',
        'wc'  => $code ?: 'UNKNOWN',
        'pj'  => $rawBody,
        'sv'  => $verified ? 1 : 0,
    ]);
    $eventId = (int) getDB()->lastInsertId();
} catch (\Throwable $e) {
    error_log('[plaid.webhook] persist failed: ' . $e->getMessage());
}

// Reject unverified webhooks AFTER logging (so an attacker can't blind us).
if (!$verified) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'reason' => 'signature_invalid', 'event_id' => $eventId]);
    exit;
}

// Route + execute (best-effort, never fail the webhook).
$processedAt = date('Y-m-d H:i:s');
$err = null;
try {
    if ($item) {
        $tenantId = (int) $item['tenant_id'];
        $itemPk   = (int) $item['id'];
        if ($type === 'ITEM') {
            if (in_array($code, ['ITEM_LOGIN_REQUIRED','PENDING_EXPIRATION'], true)) {
                getDB()->prepare(
                    'UPDATE plaid_items SET status = "requires_update", last_webhook_at = NOW(),
                            last_error_code = :c, last_error_message = :m
                     WHERE id = :id AND tenant_id = :t'
                )->execute(['c' => $code, 'm' => 'Plaid said: ' . $code, 'id' => $itemPk, 't' => $tenantId]);
            } elseif ($code === 'USER_PERMISSION_REVOKED') {
                getDB()->prepare(
                    'UPDATE plaid_items SET status = "revoked", last_webhook_at = NOW()
                     WHERE id = :id AND tenant_id = :t'
                )->execute(['id' => $itemPk, 't' => $tenantId]);
            }
        } elseif ($type === 'TRANSACTIONS' && $code === 'SYNC_UPDATES_AVAILABLE') {
            // The actual sync is heavy → defer to the cron worker rather than
            // running it inline (Plaid's 10s budget). Just touch last_webhook_at
            // so the worker's "stale" filter picks it up next run.
            getDB()->prepare(
                'UPDATE plaid_items SET last_webhook_at = NOW() WHERE id = :id AND tenant_id = :t'
            )->execute(['id' => $itemPk, 't' => $tenantId]);
        }
    }
    if ($eventId) {
        // tenant-leak-allow: webhook row was just inserted; UPDATE by primary id
        getDB()->prepare(
            'UPDATE plaid_webhook_events SET processed_at = :p WHERE id = :id'
        )->execute(['p' => $processedAt, 'id' => $eventId]);
    }
} catch (\Throwable $e) {
    $err = $e->getMessage();
    error_log('[plaid.webhook] route failed: ' . $err);
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
echo json_encode(['ok' => true, 'event_id' => $eventId, 'verified' => true, 'processed' => $err === null]);
