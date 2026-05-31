<?php
/**
 * Mercury webhook receiver — POST /api/webhooks/mercury.php?t=<tenant_id>
 *
 * Mercury authenticates via the `Mercury-Signature` header:
 *   "t=<unix>,v1=<hex_hmac_sha256>"
 * with HMAC input "<t>.<raw_body>" and the endpoint's secretKey as the
 * HMAC key. See https://docs.mercury.com/reference/webhooks.
 *
 * The tenant id arrives via the URL (?t=N). The signature must verify
 * against THAT tenant's stored secret — an attacker can't forge cross
 * tenant because they'd need the right secret per tenant id.
 *
 * Persists every event regardless of verification so misconfigured
 * secrets can be diagnosed; only verified events apply side-effects
 * (mpAdvance() on the matching payment_instruction).
 *
 * ALWAYS responds 200 quickly so Mercury doesn't retry-storm us. Per
 * Mercury docs, non-2xx triggers up to 10 retries over ~1 day.
 *
 * Skips api_require_auth — Mercury is the caller, not a logged-in user.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/mercury_webhooks.php';

if (api_method() !== 'POST') {
    api_ok(['ok' => true, 'note' => 'POST expected; returning 200 for liveness probe.']);
}

$tenantId = (int) ($_GET['t'] ?? 0);
if ($tenantId <= 0) {
    // Don't 4xx — return 200 with a debug note so Mercury can hit our
    // health probe without retry storming, but mark as ignored.
    api_ok(['ok' => true, 'persisted' => false, 'note' => 'missing or invalid ?t=<tenant_id>']);
}

$rawBody = (string) file_get_contents('php://input');
$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
// Mercury-Signature header per https://docs.mercury.com/reference/webhooks.
$sigHeader = (string) ($headers['mercury-signature'] ?? '');

// Verify against the tenant's stored secret. A missing/paused endpoint
// returns null; we still persist the event for forensics but never
// apply side-effects.
$secret = mercuryWebhookGetSecret($tenantId);
$verified = false;
$verifyError = null;
$verifyTs = null;

if ($secret === null) {
    $verifyError = 'no_active_endpoint';
} elseif ($sigHeader === '') {
    $verifyError = 'header_missing';
} else {
    $res = mercuryWebhookVerifySignature($sigHeader, $rawBody, $secret);
    $verified    = (bool) $res['ok'];
    $verifyError = $res['error'];
    $verifyTs    = $res['timestamp'];
}

// Decode body without re-serialising. Even an unverified malformed
// payload gets recorded so operators can debug.
$event = null;
$parseError = null;
try {
    $decoded = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    if (is_array($decoded)) {
        $event = $decoded;
    } else {
        $parseError = 'not_object';
    }
} catch (\Throwable $e) {
    $parseError = 'json_decode_failed';
}

if ($event === null) {
    // Persist a stub row so we can see Mercury hit us with garbage.
    $event = ['id' => 'malformed-' . bin2hex(random_bytes(6))];
    if ($verifyError === null) $verifyError = $parseError ?? 'malformed_payload';
}

$isFresh = mercuryWebhookRecordEvent(
    $tenantId, $event, $verified, $verifyError, $rawBody
);

$eventId = (string) ($event['id'] ?? '');
$rollup  = null;
if ($verified && $isFresh && $eventId !== '') {
    // Only fresh + verified events trigger mpAdvance. Duplicates from
    // Mercury's at-least-once delivery hit the row but don't re-fire
    // side-effects (the original event already ran).
    try {
        $rollup = mercuryWebhookProcessEvent($tenantId, $eventId, $event);
    } catch (\Throwable $e) {
        // Best-effort: surface in response but still 200.
        $rollup = ['outcome' => 'error', 'error' => substr($e->getMessage(), 0, 240)];
    }
}

api_ok([
    'ok'         => true,
    'persisted'  => $event !== null,
    'is_fresh'   => $isFresh,
    'verified'   => $verified,
    'verify_error' => $verifyError,
    'timestamp'  => $verifyTs,
    'event_id'   => $eventId !== '' ? $eventId : null,
    'rollup'     => $rollup,
]);
