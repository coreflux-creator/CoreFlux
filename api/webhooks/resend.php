<?php
/**
 * Resend — POST /api/webhooks/resend.php
 *
 * Receives Resend's transactional email webhook events:
 *   email.sent, email.delivered, email.bounced, email.complained,
 *   email.delivery_delayed, email.opened, email.clicked
 *
 * Authentication:
 *   Resend uses Svix-style signing. Headers sent with every payload:
 *     svix-id        unique event id
 *     svix-timestamp epoch seconds
 *     svix-signature space-separated list of "v1,<base64-hmac-sha256>"
 *
 *   The HMAC input is "${svix_id}.${svix_timestamp}.${raw_body}" hashed
 *   with the (base64-decoded) shared secret. Operators set the secret
 *   via define('RESEND_WEBHOOK_SECRET', 'whsec_…') in config.local.php
 *   or the RESEND_WEBHOOK_SECRET env var.
 *
 *   ALL events are persisted to mail_webhook_events regardless of
 *   signature verification so we can debug a misconfigured secret
 *   without losing history. Only verified events apply side-effects
 *   (mail_outbox status flip, auto-suppression on bounce/complaint).
 *
 * ALWAYS returns 200 so Resend doesn't retry-storm us. Errors land
 * inside the response body for debugging.
 *
 * IMPORTANT: skips api_require_auth — Resend is the caller, not a
 * logged-in user.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/mail/suppressions.php';

if (api_method() !== 'POST') {
    // Resend tests with GET sometimes — return 200 so they can probe.
    api_ok(['ok' => true, 'note' => 'POST expected; returning 200 for liveness probe.']);
}

$rawBody = (string) file_get_contents('php://input');
$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$svixId        = (string) ($headers['svix-id']        ?? '');
$svixTimestamp = (string) ($headers['svix-timestamp'] ?? '');
$svixSignature = (string) ($headers['svix-signature'] ?? '');

// Load secret (env > define). Never log the secret value itself.
$secret = (string) getenv('RESEND_WEBHOOK_SECRET');
if ($secret === '' && defined('RESEND_WEBHOOK_SECRET')) {
    $secret = (string) constant('RESEND_WEBHOOK_SECRET');
}

$verified = false;
$verifyError = null;
if ($secret !== '' && $svixId !== '' && $svixTimestamp !== '' && $svixSignature !== '') {
    try {
        $verified = _resend_verify_svix_signature($secret, $svixId, $svixTimestamp, $svixSignature, $rawBody);
        if (!$verified) $verifyError = 'signature_mismatch';
    } catch (\Throwable $e) {
        $verifyError = 'verify_threw:' . substr($e->getMessage(), 0, 100);
    }
} else {
    $verifyError = $secret === '' ? 'no_secret_configured' : 'missing_svix_headers';
}

$payload   = json_decode($rawBody, true) ?: [];
$eventType = (string) ($payload['type'] ?? '');
$data      = is_array($payload['data'] ?? null) ? $payload['data'] : [];
$messageId = (string) ($data['email_id'] ?? '');

// Pull canonical recipient — Resend payloads have data.to as a string OR
// an array depending on the original send.
$recipient = '';
$toRaw     = $data['to'] ?? null;
if (is_string($toRaw))      $recipient = $toRaw;
elseif (is_array($toRaw))   $recipient = (string) ($toRaw[0] ?? '');

// Resolve the (tenant_id, mail_outbox_id) tuple for downstream side-
// effects + analytics.
$outboxId = null;
$tenantId = null;
$pdo      = getDB();
if ($pdo && $messageId !== '') {
    try {
        // tenant-leak-allow: provider_message_id is globally unique across tenants (issued by Resend); used here to discover which tenant the message belongs to
        $st = $pdo->prepare(
            'SELECT id, tenant_id FROM mail_outbox
              WHERE provider_message_id = :m
              ORDER BY id DESC LIMIT 1'
        );
        $st->execute(['m' => $messageId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $outboxId = (int) $row['id'];
            $tenantId = (int) $row['tenant_id'];
        }
    } catch (\Throwable $e) { /* outbox table may not exist; tolerate */ }
}

// Persist raw event regardless of verification state.
$eventId = null;
if ($pdo) {
    try {
        $st = $pdo->prepare(
            'INSERT INTO mail_webhook_events
                (provider, event_type, message_id, tenant_id, mail_outbox_id,
                 recipient_email, payload_json, signature_verified)
             VALUES (:p, :e, :m, :t, :o, :r, :pj, :sv)'
        );
        $st->execute([
            'p'  => 'resend',
            'e'  => $eventType ?: 'unknown',
            'm'  => $messageId !== '' ? $messageId : null,
            't'  => $tenantId,
            'o'  => $outboxId,
            'r'  => $recipient !== '' ? $recipient : null,
            'pj' => $rawBody,
            'sv' => $verified ? 1 : 0,
        ]);
        $eventId = (int) $pdo->lastInsertId();
    } catch (\Throwable $e) {
        // mail_webhook_events table missing — return ok=true with a
        // hint so the operator notices migration drift in logs.
        api_ok(['ok' => true, 'persisted' => false, 'note' => 'mail_webhook_events table absent — run migration 083.']);
    }
}

// Side-effects ONLY for verified events.
$sideEffects = ['outbox_updated' => false, 'suppressed' => false];
if ($verified && $pdo) {
    // Map provider event types to mail_outbox.status. We only ever
    // mutate forward (never overwrite a 'bounced' back to 'sent').
    $targetStatus = null;
    switch ($eventType) {
        case 'email.bounced':       $targetStatus = 'bounced';   break;
        case 'email.complained':    $targetStatus = 'complaint'; break;
        case 'email.delivered':
        case 'email.sent':
            // Only set 'sent' if the row is still 'queued' — avoids
            // overwriting a subsequent bounce that lands earlier.
            $targetStatus = 'sent';
            break;
    }
    if ($targetStatus !== null && $outboxId !== null) {
        try {
            if ($targetStatus === 'sent') {
                // tenant-leak-allow: outboxId already resolved to this tenant via provider_message_id lookup above; update is by primary key + queued-status guard
                $st = $pdo->prepare(
                    "UPDATE mail_outbox
                        SET status = 'sent', sent_at = COALESCE(sent_at, UTC_TIMESTAMP())
                      WHERE id = :id AND status = 'queued' AND tenant_id = :t"
                );
                $st->execute(['id' => $outboxId, 't' => $tenantId]);
            } else {
                // tenant-leak-allow: outboxId already resolved to this tenant via provider_message_id lookup above; update is by primary key + status guard
                $st = $pdo->prepare(
                    "UPDATE mail_outbox
                        SET status = :new_status, error = :err
                      WHERE id = :id
                        AND tenant_id = :t
                        AND status NOT IN (:guard_status)"
                );
                $st->execute([
                    'id'           => $outboxId,
                    't'            => $tenantId,
                    'new_status'   => $targetStatus,
                    'err'          => substr('webhook:' . $eventType . ':' . json_encode($data['bounce'] ?? $data['reason'] ?? null), 0, 1000),
                    'guard_status' => $targetStatus,
                ]);
            }
            $sideEffects['outbox_updated'] = $st->rowCount() > 0;
        } catch (\Throwable $e) { /* tolerate */ }
    }

    // Auto-suppress on hard bounce or complaint.
    if (in_array($eventType, ['email.bounced', 'email.complained'], true)
        && $tenantId !== null && $recipient !== '') {
        $bounceKind = (string) ($data['bounce']['type'] ?? $data['bounce_type'] ?? '');
        // Resend marks soft bounces as type='Transient' — skip those.
        $isHard = $bounceKind === '' || stripos($bounceKind, 'transient') === false;
        if ($eventType === 'email.complained' || $isHard) {
            $suppressId = cf_mail_suppress(
                $tenantId,
                $recipient,
                $eventType === 'email.complained' ? 'complaint' : 'bounce',
                [
                    'source'                 => 'resend',
                    'last_webhook_event_id'  => $eventId,
                    'notes'                  => 'Auto-suppressed by Resend webhook (' . $eventType . ')',
                ]
            );
            $sideEffects['suppressed'] = $suppressId !== null;
        }
    }
}

api_ok([
    'ok'                  => true,
    'event_id'            => $eventId,
    'event_type'          => $eventType,
    'message_id'          => $messageId !== '' ? $messageId : null,
    'tenant_id'           => $tenantId,
    'mail_outbox_id'      => $outboxId,
    'signature_verified'  => $verified,
    'verify_error'        => $verifyError,
    'side_effects'        => $sideEffects,
]);

/* ---------------------------------------------------------------- helpers */

/**
 * Verify Svix-style webhook signature.
 *
 *   signed_payload = "${svix_id}.${svix_timestamp}.${raw_body}"
 *   secret_bytes   = base64_decode( strip_prefix(secret, 'whsec_') )
 *   expected       = base64( hmac_sha256(secret_bytes, signed_payload) )
 *
 * The signature header is space-separated entries like
 * "v1,base64sig v1,othersig" — any match wins. Constant-time compare.
 */
function _resend_verify_svix_signature(
    string $secret, string $svixId, string $svixTimestamp, string $signatureHeader, string $rawBody
): bool {
    // Reject impossibly old timestamps to prevent replay (5 min window).
    $ts = (int) $svixTimestamp;
    if ($ts <= 0 || abs(time() - $ts) > 300) {
        return false;
    }
    $secretStripped = $secret;
    if (str_starts_with($secret, 'whsec_')) {
        $secretStripped = substr($secret, strlen('whsec_'));
    }
    $secretBytes = base64_decode($secretStripped, true);
    if ($secretBytes === false || $secretBytes === '') {
        return false;
    }
    $signedPayload = $svixId . '.' . $svixTimestamp . '.' . $rawBody;
    $expected      = base64_encode(hash_hmac('sha256', $signedPayload, $secretBytes, true));

    foreach (preg_split('/\s+/', trim($signatureHeader)) as $entry) {
        if (!str_contains($entry, ',')) continue;
        [$version, $candidate] = explode(',', $entry, 2);
        if ($version !== 'v1') continue;
        if (hash_equals($expected, trim($candidate))) {
            return true;
        }
    }
    return false;
}
