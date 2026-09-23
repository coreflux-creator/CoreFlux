<?php
/** Signed Pure//Pay webhook receiver. */

declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/purepay_webhooks.php';

if (api_method() !== 'POST') api_error('POST expected', 405);
$tenantId = (int) ($_GET['t'] ?? 0);
if ($tenantId <= 0) api_error('Invalid tenant', 404);
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
if ($secret === '') api_error('Webhook not configured', 503);
$check = purepayWebhookVerify($secret, $timestamp, $raw, $signature);
if (!$check['ok']) api_error('Invalid webhook signature', 401);
$payload = json_decode($raw, true);
if (!is_array($payload)) api_error('Invalid webhook JSON', 400);
$eventId = (string) ($payload['id'] ?? '');
if ($eventId === '' || $eventIdHeader === '' || !hash_equals($eventId, $eventIdHeader)) {
    api_error('Webhook event ID mismatch', 400);
}
$eventType = (string) ($payload['type'] ?? '');
try {
    $fresh = purepayWebhookRecord($tenantId, $eventId, $eventType ?: null, true, null, $check['timestamp'], $payload, $raw);
} catch (\Throwable $e) {
    api_error('Webhook persistence failed', 503);
}
$rollup = null;
if ($fresh) {
    try {
        $rollup = in_array($eventType, ['payment.settled', 'payment.failed'], true)
            ? purepayWebhookProcess($tenantId, $eventId, $eventType, $payload)
            : ['outcome' => 'ignored', 'reason' => 'event_type'];
        purepayWebhookMarkProcessed($tenantId, $eventId);
    } catch (\Throwable $e) {
        purepayWebhookMarkProcessed($tenantId, $eventId, $e->getMessage());
        api_error('Webhook processing failed', 503);
    }
}
api_ok([
    'ok'=>true,'persisted'=>true,'fresh'=>$fresh,'verified'=>true,
    'event_id'=>$eventId,'rollup'=>$rollup,
]);
