<?php
/**
 * QBO Webhook receiver — POST /api/webhooks/qbo.php
 *
 * Intuit posts entity-change notifications here when invoices,
 * payments, bills, or bill-payments mutate inside QuickBooks Online.
 * Replaces "wait 30 minutes for the next polling cycle" with a sub-
 * second push that triggers an immediate targeted pull of the changed
 * entity into the qbo_inbound_* shadow tables.
 *
 * Wire-shape (Intuit Webhooks v2):
 *
 *   POST /api/webhooks/qbo.php
 *   Headers:
 *     intuit-signature: base64(HMAC-SHA256(<verifier_token>, <raw_body>))
 *   Body:
 *     { "eventNotifications": [
 *         { "realmId": "<companyId>",
 *           "dataChangeEvent": {
 *             "entities": [
 *               { "name": "Invoice",  "id": "131", "operation": "Update",
 *                 "lastUpdated": "2026-02-15T18:21:03.000Z" },
 *               …
 *             ] } },
 *         … ]
 *     }
 *
 * Verifier token: stored per-pod in env QBO_WEBHOOK_VERIFIER_TOKEN.
 * (Single token across all tenants — Intuit issues one per app, not per
 * realm.) When unset we still persist the event for forensics but mark
 * it `verified=false` and skip side-effects.
 *
 * Idempotency: each entity-update triggers a targeted pull which goes
 * through the same shadow-upsert path as the 30-min cron, so duplicate
 * deliveries are safe.
 *
 * Liveness probe: any non-POST returns 200 immediately so Intuit's
 * health probe doesn't retry-storm us.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/qbo/client.php';
require_once __DIR__ . '/../../core/qbo/sync_in_arap.php';
require_once __DIR__ . '/../../core/qbo/auto_reconcile.php';

if (api_method() !== 'POST') {
    api_ok(['ok' => true, 'note' => 'POST expected; returning 200 for liveness probe.']);
}

// Ensure the events table exists (idempotent).
try {
    getDB()->exec(
        "CREATE TABLE IF NOT EXISTS qbo_webhook_events (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            realm_id        VARCHAR(40)     NOT NULL,
            tenant_id       INT UNSIGNED    NULL,
            event_id        VARCHAR(64)     NOT NULL,
            entity_name     VARCHAR(32)     NOT NULL,
            entity_qbo_id   VARCHAR(64)     NOT NULL,
            operation       VARCHAR(16)     NOT NULL,
            last_updated    DATETIME        NULL,
            verified        TINYINT(1)      NOT NULL DEFAULT 0,
            verify_error    VARCHAR(120)    NULL,
            processed_at    DATETIME        NULL,
            processing_outcome VARCHAR(40)  NULL,
            processing_error VARCHAR(500)   NULL,
            received_at     DATETIME        NOT NULL,
            raw_payload     MEDIUMTEXT      NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_event (event_id),
            KEY idx_realm (realm_id),
            KEY idx_tenant_received (tenant_id, received_at)
        )"
    );
} catch (\Throwable $_) { /* table will exist in prod via migration */ }

$rawBody = (string) file_get_contents('php://input');
$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$sigB64  = (string) ($headers['intuit-signature'] ?? '');
$now     = date('Y-m-d H:i:s');

$verifier = (string) (getenv('QBO_WEBHOOK_VERIFIER_TOKEN')
    ?: (defined('QBO_WEBHOOK_VERIFIER_TOKEN') ? constant('QBO_WEBHOOK_VERIFIER_TOKEN') : ''));

$verified    = false;
$verifyError = null;
if ($verifier === '') {
    $verifyError = 'verifier_token_unconfigured';
} elseif ($sigB64 === '') {
    $verifyError = 'header_missing';
} else {
    $expected = base64_encode(hash_hmac('sha256', $rawBody, $verifier, true));
    if (hash_equals($expected, $sigB64)) {
        $verified = true;
    } else {
        $verifyError = 'signature_mismatch';
    }
}

$parsed     = null;
$parseError = null;
try {
    $decoded = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    if (is_array($decoded)) {
        $parsed = $decoded;
    } else {
        $parseError = 'not_object';
    }
} catch (\Throwable $e) {
    $parseError = 'json_decode_failed';
}

$processed = ['entities' => 0, 'pulls_fired' => 0, 'auto_recon_invoked' => 0];

if ($parsed && empty($verifyError)) {
    foreach ((array) ($parsed['eventNotifications'] ?? []) as $note) {
        $realmId  = (string) ($note['realmId'] ?? '');
        $entities = (array)  ($note['dataChangeEvent']['entities'] ?? []);
        if ($realmId === '' || !$entities) continue;

        // Resolve tenant by realmId. Multi-tenant pods may have N
        // connections — the realm is the join key.
        $tenantId = _qboWebhookFindTenant($realmId);

        foreach ($entities as $ent) {
            $name   = (string) ($ent['name']      ?? '');
            $qboId  = (string) ($ent['id']        ?? '');
            $opType = (string) ($ent['operation'] ?? '');
            $last   = (string) ($ent['lastUpdated'] ?? '');
            $eventId = sha1($realmId . '|' . $name . '|' . $qboId . '|' . $opType . '|' . $last);

            // Persist event (idempotent on uniq_event).
            try {
                getDB()->prepare(
                    'INSERT INTO qbo_webhook_events
                        (realm_id, tenant_id, event_id, entity_name, entity_qbo_id,
                         operation, last_updated, verified, verify_error,
                         received_at, raw_payload)
                     VALUES (:r, :t, :e, :n, :q, :o, :lu, :v, :ve, :rc, :rp)'
                )->execute([
                    'r' => $realmId, 't' => $tenantId ?: null, 'e' => $eventId,
                    'n' => $name, 'q' => $qboId, 'o' => $opType,
                    'lu'=> $last !== '' ? date('Y-m-d H:i:s', strtotime($last) ?: time()) : null,
                    'v' => 1, 've' => null,
                    'rc'=> $now, 'rp' => json_encode($ent),
                ]);
            } catch (\Throwable $_) { /* duplicate — already persisted, fine */ }

            $processed['entities']++;
            if (!$tenantId) continue;

            // Fire a targeted pull for the entity that changed. Each
            // pull dedupes via the existing shadow-upsert + LastUpdatedTime
            // filter, so repeated webhooks for the same row are safe.
            try {
                $fn = _qboWebhookPullFn($name);
                if ($fn) {
                    // Pull only items modified in the past 24h around this
                    // event to keep API quota tight while absorbing clock
                    // skew. The shadow upsert is keyed by qbo_id so we
                    // converge regardless of overlap.
                    $since = $last !== ''
                        ? gmdate('Y-m-d\TH:i:s', max(0, strtotime($last) - 60))
                        : gmdate('Y-m-d\TH:i:s', time() - 86400);
                    $res = $fn($tenantId, ['modified_since' => $since, 'limit' => 50, 'max_pages' => 1]);
                    $processed['pulls_fired']++;
                    _qboWebhookFinalize($eventId, 'pulled', null);

                    // If the inbound pull surfaced any new paid_out_of_band
                    // drift AND the tenant is auto-recon enabled, close
                    // the loop synchronously.
                    if (($res['drift_rows_written'] ?? 0) > 0 && qboAutoReconcileEnabled($tenantId)) {
                        qboAutoReconcileTenant($tenantId, null);
                        $processed['auto_recon_invoked']++;
                    }
                } else {
                    _qboWebhookFinalize($eventId, 'ignored_entity', null);
                }
            } catch (\Throwable $e) {
                _qboWebhookFinalize($eventId, 'pull_error', substr($e->getMessage(), 0, 240));
            }
        }
    }
}

api_ok([
    'ok'         => true,
    'verified'   => $verified,
    'verify_error' => $verifyError,
    'parse_error'  => $parseError,
    'processed'    => $processed,
]);

// ─────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────

function _qboWebhookFindTenant(string $realmId): ?int
{
    try {
        $stmt = getDB()->prepare(
            'SELECT tenant_id FROM qbo_connections
              WHERE realm_id = :r AND status = "active"
              ORDER BY tenant_id LIMIT 1'
        );
        $stmt->execute(['r' => $realmId]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $r ? (int) $r['tenant_id'] : null;
    } catch (\Throwable $_) {
        return null;
    }
}

function _qboWebhookPullFn(string $entityName): ?string
{
    static $map = [
        'Invoice'     => 'qboPullInvoices',
        'Payment'     => 'qboPullPayments',
        'Deposit'     => 'qboPullDeposits',
        'Bill'        => 'qboPullBills',
        'BillPayment' => 'qboPullBillPayments',
    ];
    $fn = $map[$entityName] ?? null;
    return ($fn && function_exists($fn)) ? $fn : null;
}

function _qboWebhookFinalize(string $eventId, string $outcome, ?string $error): void
{
    try {
        getDB()->prepare(
            'UPDATE qbo_webhook_events
                SET processed_at = :p, processing_outcome = :o, processing_error = :e
              WHERE event_id = :id'
        )->execute([
            'p' => date('Y-m-d H:i:s'),
            'o' => $outcome,
            'e' => $error,
            'id'=> $eventId,
        ]);
    } catch (\Throwable $_) { /* best-effort */ }
}
