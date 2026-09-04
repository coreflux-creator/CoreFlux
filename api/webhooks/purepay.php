<?php
/** Signed Pure//Pay webhook receiver. Always acknowledges with HTTP 200. */

declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/purepay_webhooks.php';

if (api_method() !== 'POST') api_ok(['ok'=>true,'note'=>'POST expected']);
$tenantId = (int) ($_GET['t'] ?? 0);
if ($tenantId <= 0) api_ok(['ok'=>true,'persisted'=>false,'note'=>'invalid tenant']);
$raw = (string) file_get_contents('php://input');
$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$headers += [
    'x-webhook-id' => (string) ($_SERVER['HTTP_X_WEBHOOK_ID'] ?? ''),
    'x-webhook-timestamp' => (string) ($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? ''),
    'x-webhook-signature' => (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''),
];
$eventIdHeader = trim((string) ($headers['x-webhook-id'] ?? ''));
$timestamp = trim((string) ($headers['x-webhook-timestamp'] ?? ''));
$signature = trim((string) ($headers['x-webhook-signature'] ?? ''));
$conn = purepayGetConnection($tenantId);
$secret = (string) ($conn['webhook_secret'] ?? '');
$verified = false; $verifyError = null; $verifyTs = null;
if ($secret === '') {
    $verifyError = 'secret_missing';
} else {
    $check = purepayWebhookVerify($secret, $timestamp, $raw, $signature);
    $verified = (bool) $check['ok'];
    $verifyError = $check['error'];
    $verifyTs = $check['timestamp'];
}
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = null;
$eventId = $eventIdHeader !== '' ? $eventIdHeader : (string) ($payload['id'] ?? ('malformed-' . bin2hex(random_bytes(6))));
$eventType = is_array($payload) ? (string) ($payload['type'] ?? '') : '';
try {
    $fresh = purepayWebhookRecord($tenantId, $eventId, $eventType ?: null, $verified, $verifyError, $verifyTs, $payload, $raw);
} catch (\Throwable $e) {
    api_ok(['ok'=>true,'persisted'=>false,'verified'=>$verified,'error'=>'persistence_failed']);
}
$rollup = null;
if ($verified && $fresh && is_array($payload)) {
    try {
        $rollup = purepayWebhookProcess($tenantId, $eventId, $eventType, $payload);
        purepayWebhookMarkProcessed($tenantId, $eventId);
    } catch (\Throwable $e) {
        purepayWebhookMarkProcessed($tenantId, $eventId, $e->getMessage());
        $rollup = ['outcome'=>'error','error'=>substr($e->getMessage(),0,240)];
    }
}
api_ok([
    'ok'=>true,'persisted'=>true,'fresh'=>$fresh,'verified'=>$verified,
    'verify_error'=>$verifyError,'event_id'=>$eventId,'rollup'=>$rollup,
]);
