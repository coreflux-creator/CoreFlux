<?php
/**
 * core/mercury_webhooks.php — Mercury webhook ingestion + processing.
 *
 * Mercury sends signed POSTs to a per-tenant URL of the form
 * /api/webhooks/mercury.php?t=<tenant_id> whenever a transaction or
 * balance changes. We verify the HMAC-SHA256 signature against the
 * tenant's stored secret, persist every event (even unverified — for
 * audit), and for verified `transaction.updated` events we look up
 * the matching payment_instruction by mercury_txn_id and call
 * mpAdvance() to push its state machine forward immediately. This
 * collapses the polling latency from "next cron tick" to seconds.
 *
 * Public surface:
 *   mercuryWebhookGet(int $tenantId): ?array
 *   mercuryWebhookSaveSecret(int $tenantId, string $secret, ?string $url, ?string $endpointId, ?int $userId): array
 *   mercuryWebhookPause(int $tenantId, bool $paused): void
 *   mercuryWebhookVerifySignature(string $header, string $rawBody, string $secret, int $toleranceSec = 300): array
 *   mercuryWebhookRecordEvent(int $tenantId, array $event, bool $verified, ?string $verifyError, string $rawBody): bool
 *   mercuryWebhookProcessEvent(int $tenantId, string $eventId, array $event): array
 *   mercuryWebhookRecentEvents(int $tenantId, int $limit = 50): array
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/encryption.php';

const MERCURY_WEBHOOK_REPLAY_TOLERANCE_SEC = 300; // Mercury recommends 5 minutes.

function mercuryWebhookGet(int $tenantId): ?array
{
    try {
        $stmt = getDB()->prepare(
            'SELECT id, tenant_id, mercury_endpoint_id, url,
                    signing_secret_last4, status,
                    last_event_at, last_error,
                    created_at, updated_at
               FROM mercury_webhook_endpoints
              WHERE tenant_id = :t LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['id']        = (int) $row['id'];
        $row['tenant_id'] = (int) $row['tenant_id'];
        return $row;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Decrypt + return the active signing secret for a tenant. Never
 * surfaces over the API. Used only by the webhook receiver.
 */
function mercuryWebhookGetSecret(int $tenantId): ?string
{
    try {
        $stmt = getDB()->prepare(
            'SELECT signing_secret_ct, status FROM mercury_webhook_endpoints
              WHERE tenant_id = :t LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ($row['status'] !== 'active') return null;
        $secret = decryptField($row['signing_secret_ct']);
        return is_string($secret) && $secret !== '' ? $secret : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function mercuryWebhookSaveSecret(
    int $tenantId,
    string $secret,
    ?string $url = null,
    ?string $endpointId = null,
    ?int $userId = null
): array {
    $secret = trim($secret);
    if ($secret === '') throw new \InvalidArgumentException('signing secret required');
    if (strlen($secret) < 16) throw new \InvalidArgumentException('signing secret must be ≥ 16 characters');

    $ct = encryptField($secret);
    $last4 = substr($secret, -4);

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO mercury_webhook_endpoints
            (tenant_id, mercury_endpoint_id, url,
             signing_secret_ct, signing_secret_last4, status, created_by_user_id)
         VALUES (:t, :e, :u, :ct, :l4, "active", :uid)
         ON DUPLICATE KEY UPDATE
            mercury_endpoint_id = VALUES(mercury_endpoint_id),
            url                 = VALUES(url),
            signing_secret_ct   = VALUES(signing_secret_ct),
            signing_secret_last4= VALUES(signing_secret_last4),
            status              = "active",
            last_error          = NULL'
    )->execute([
        't'   => $tenantId,
        'e'   => $endpointId,
        'u'   => $url,
        'ct'  => $ct,
        'l4'  => $last4,
        'uid' => $userId,
    ]);

    return mercuryWebhookGet($tenantId);
}

function mercuryWebhookPause(int $tenantId, bool $paused): void
{
    getDB()->prepare(
        'UPDATE mercury_webhook_endpoints
            SET status = :s
          WHERE tenant_id = :t'
    )->execute([
        's' => $paused ? 'paused' : 'active',
        't' => $tenantId,
    ]);
}

/**
 * Verify a Mercury-Signature header.
 *
 * Per docs:
 *   Header format: "t=<unix>,v1=<hex_hmac_sha256>"
 *   Signed payload: "<t>.<raw_body>"
 *   HMAC key: the endpoint's secretKey (used as raw bytes)
 *
 * Returns ['ok' => bool, 'error' => ?string, 'timestamp' => ?int].
 * `error` is one of: 'header_missing','header_format','timestamp_skew',
 * 'signature_mismatch'.
 */
function mercuryWebhookVerifySignature(
    string $header,
    string $rawBody,
    string $secret,
    int $toleranceSec = MERCURY_WEBHOOK_REPLAY_TOLERANCE_SEC
): array {
    if ($header === '') {
        return ['ok' => false, 'error' => 'header_missing', 'timestamp' => null];
    }
    $ts = null; $sig = null;
    foreach (explode(',', $header) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $eq = strpos($part, '=');
        if ($eq === false) continue;
        $k = substr($part, 0, $eq);
        $v = substr($part, $eq + 1);
        if ($k === 't')  $ts  = $v;
        if ($k === 'v1') $sig = $v;
    }
    if ($ts === null || $sig === null || !ctype_digit((string) $ts) || $sig === '') {
        return ['ok' => false, 'error' => 'header_format', 'timestamp' => null];
    }
    $tsInt = (int) $ts;
    if ($toleranceSec > 0) {
        $skew = abs(time() - $tsInt);
        if ($skew > $toleranceSec) {
            return ['ok' => false, 'error' => 'timestamp_skew', 'timestamp' => $tsInt];
        }
    }
    $signedPayload = $ts . '.' . $rawBody;
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    // Constant-time compare.
    if (!hash_equals($expected, strtolower($sig))) {
        return ['ok' => false, 'error' => 'signature_mismatch', 'timestamp' => $tsInt];
    }
    return ['ok' => true, 'error' => null, 'timestamp' => $tsInt];
}

/**
 * Persist a webhook event. Idempotent on event_id — duplicate
 * deliveries hit ON DUPLICATE KEY and update only the bookkeeping
 * columns (received_at stays from the first hit so we can spot
 * Mercury retries).
 *
 * Returns true when this was a fresh row (caller should process
 * side-effects), false when it was a duplicate.
 */
function mercuryWebhookRecordEvent(
    int $tenantId,
    array $event,
    bool $verified,
    ?string $verifyError,
    string $rawBody
): bool {
    $eventId = trim((string) ($event['id'] ?? ''));
    if ($eventId === '') {
        // No event id → impossible to dedupe. Synthesize one so we can
        // still keep a row for audit, but never apply side-effects.
        $eventId = 'no-id-' . bin2hex(random_bytes(8));
    }
    $resourceType = (string) ($event['resourceType'] ?? '');
    $resourceId   = (string) ($event['resourceId']   ?? '');
    $opType       = (string) ($event['operationType'] ?? '');
    $eventType    = $resourceType !== '' && $opType !== ''
        ? "{$resourceType}.{$opType}" : 'unknown';

    $occurredAt = null;
    $occRaw = (string) ($event['occurredAt'] ?? '');
    if ($occRaw !== '') {
        try {
            $dt = new \DateTimeImmutable($occRaw);
            $occurredAt = $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            $occurredAt = null;
        }
    }

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO mercury_webhook_events
            (event_id, tenant_id, resource_type, resource_id,
             event_type, operation_type, occurred_at,
             verified, verify_error, payload_json)
         VALUES (:eid, :t, :rt, :rid, :et, :op, :occ,
                 :v, :ve, :pj)
         ON DUPLICATE KEY UPDATE
            verified     = VALUES(verified),
            verify_error = VALUES(verify_error)'
    );
    $stmt->execute([
        'eid' => $eventId,
        't'   => $tenantId,
        'rt'  => $resourceType,
        'rid' => $resourceId !== '' ? $resourceId : null,
        'et'  => $eventType,
        'op'  => $opType !== '' ? $opType : null,
        'occ' => $occurredAt,
        'v'   => $verified ? 1 : 0,
        've'  => $verifyError,
        'pj'  => substr($rawBody, 0, 65535 * 16), // MEDIUMTEXT cap
    ]);
    // MySQL returns rowCount=1 for INSERT, 2 for UPDATE on ON DUPLICATE.
    return $stmt->rowCount() === 1;
}

/**
 * Apply side-effects of a verified event.
 *
 * The only event class we currently act on is `transaction.updated`
 * with a status change. We look up the payment_instruction whose
 * funding_mercury_txn_id OR payout_mercury_txn_id matches the
 * resourceId. If found and the instruction is in a state that
 * mpAdvance() can move forward, we call it. This collapses the
 * standard poll-then-advance loop to a single push-then-advance.
 *
 * Returns ['outcome' => 'advanced'|'no_match'|'skipped_no_status'|'error',
 *          'payment_instruction_id' => ?int,
 *          'before_state' => ?string, 'after_state' => ?string,
 *          'error' => ?string].
 */
function mercuryWebhookProcessEvent(int $tenantId, string $eventId, array $event): array
{
    $resourceType = (string) ($event['resourceType'] ?? '');
    $opType       = (string) ($event['operationType'] ?? '');
    $resourceId   = (string) ($event['resourceId'] ?? '');
    $mergePatch   = is_array($event['mergePatch'] ?? null) ? $event['mergePatch'] : [];

    if ($resourceType !== 'transaction' || $opType !== 'update' || $resourceId === '') {
        $outcome = 'skipped_no_status';
        mercuryWebhookFinalize($tenantId, $eventId, $outcome, null, null);
        return ['outcome' => $outcome, 'payment_instruction_id' => null,
                'before_state' => null, 'after_state' => null, 'error' => null];
    }
    // We only react when the status field flipped — balance-only updates
    // are noise for the payment state machine.
    if (!array_key_exists('status', $mergePatch)) {
        $outcome = 'skipped_no_status';
        mercuryWebhookFinalize($tenantId, $eventId, $outcome, null, null);
        return ['outcome' => $outcome, 'payment_instruction_id' => null,
                'before_state' => null, 'after_state' => null, 'error' => null];
    }

    require_once __DIR__ . '/mercury_payments.php';

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, state FROM payment_instructions
          WHERE tenant_id = :t
            AND (funding_mercury_txn_id = :rid_f OR payout_mercury_txn_id = :rid_p)
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'rid_f' => $resourceId, 'rid_p' => $resourceId]);
    $pi = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$pi) {
        $outcome = 'no_match';
        mercuryWebhookFinalize($tenantId, $eventId, $outcome, null, null);
        return ['outcome' => $outcome, 'payment_instruction_id' => null,
                'before_state' => null, 'after_state' => null, 'error' => null];
    }

    $piId = (int) $pi['id'];
    $before = (string) $pi['state'];
    try {
        $after = mpAdvance($tenantId, $piId);
        $outcome = 'advanced';
        mercuryWebhookFinalize($tenantId, $eventId, $outcome, $piId, null);
        return ['outcome' => $outcome, 'payment_instruction_id' => $piId,
                'before_state' => $before, 'after_state' => $after, 'error' => null];
    } catch (\Throwable $e) {
        $outcome = 'error';
        mercuryWebhookFinalize($tenantId, $eventId, $outcome, $piId, substr($e->getMessage(), 0, 240));
        return ['outcome' => $outcome, 'payment_instruction_id' => $piId,
                'before_state' => $before, 'after_state' => $before,
                'error' => substr($e->getMessage(), 0, 240)];
    }
}

/**
 * Stamp processed_at + outcome on the event row, and roll the latest
 * timestamp / last_error onto the endpoint table so the health UI can
 * surface "received an event N seconds ago".
 */
function mercuryWebhookFinalize(int $tenantId, string $eventId, string $outcome, ?int $piId, ?string $error): void
{
    try {
        getDB()->prepare(
            'UPDATE mercury_webhook_events
                SET processed_at = NOW(),
                    processing_outcome = :o,
                    processing_error   = :e,
                    payment_instruction_id = :pi
              WHERE tenant_id = :t AND event_id = :eid'
        )->execute([
            'o' => $outcome,
            'e' => $error,
            'pi'=> $piId,
            't' => $tenantId,
            'eid' => $eventId,
        ]);
        getDB()->prepare(
            'UPDATE mercury_webhook_endpoints
                SET last_event_at = NOW(),
                    last_error    = :err
              WHERE tenant_id = :t'
        )->execute([
            'err' => $outcome === 'error' ? $error : null,
            't'   => $tenantId,
        ]);
    } catch (\Throwable $e) {
        // Bookkeeping errors must never block the 200 response —
        // Mercury will retry the whole event and the next pass picks it up.
        error_log('[mercuryWebhookFinalize] ' . $e->getMessage());
    }
}

function mercuryWebhookRecentEvents(int $tenantId, int $limit = 50): array
{
    $limit = max(1, min(500, $limit));
    try {
        $stmt = getDB()->prepare(
            "SELECT event_id, resource_type, resource_id, event_type, operation_type,
                    occurred_at, received_at, verified, verify_error,
                    processed_at, processing_outcome, processing_error,
                    payment_instruction_id
               FROM mercury_webhook_events
              WHERE tenant_id = :t
              ORDER BY received_at DESC
              LIMIT {$limit}"
        );
        $stmt->execute(['t' => $tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['verified'] = (int) $r['verified'] === 1;
            if ($r['payment_instruction_id'] !== null) {
                $r['payment_instruction_id'] = (int) $r['payment_instruction_id'];
            }
        }
        unset($r);
        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}
