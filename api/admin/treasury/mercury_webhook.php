<?php
/**
 * /api/admin/treasury/mercury_webhook.php — Mercury webhook config + health.
 *
 *   GET    → { endpoint: ?{...}, recent_events: [...], delivery_url: '…' }
 *   POST   body: { signing_secret, url?, mercury_endpoint_id? }
 *            → { endpoint: {...} }
 *   PATCH  body: { paused: true|false } — flip status without rotating secret
 *
 * RBAC: accounting.bank.manage (matches the rest of the treasury admin
 * surface — only operators who can wire Mercury connections up should
 * touch webhook configs).
 *
 * The signing secret is stored AES-256-GCM encrypted; only the last 4
 * characters are ever returned to the UI. Rotation is a single POST —
 * Mercury keeps signing with the value you just pasted.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/mercury_webhooks.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

rbac_legacy_require($user, 'accounting.bank.manage');

$method = api_method();

// Build the canonical delivery URL the operator should paste into
// Mercury's webhook config UI. Built from REACT_APP_BACKEND_URL when
// available so dev pods and production both render the right URL.
$base = trim((string) (getenv('APP_PUBLIC_URL') ?: (defined('APP_URL') ? constant('APP_URL') : '')));
if ($base === '') $base = 'https://www.corefluxapp.com';
$deliveryUrl = rtrim($base, '/') . '/api/webhooks/mercury.php?t=' . $tid;

if ($method === 'GET') {
    api_ok([
        'endpoint'      => mercuryWebhookGet($tid),
        'recent_events' => mercuryWebhookRecentEvents($tid, 50),
        'delivery_url'  => $deliveryUrl,
    ]);
}

if ($method === 'POST') {
    $body = api_json_body();
    $secret      = (string) ($body['signing_secret']      ?? '');
    $url         = (string) ($body['url']                 ?? '');
    $endpointId  = (string) ($body['mercury_endpoint_id'] ?? '');
    try {
        $row = mercuryWebhookSaveSecret(
            $tid,
            $secret,
            $url !== ''        ? $url        : null,
            $endpointId !== '' ? $endpointId : null,
            (int) ($user['id'] ?? 0) ?: null
        );
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (\Throwable $e) {
        api_error('save failed: ' . $e->getMessage(), 500);
    }
    api_ok(['endpoint' => $row, 'delivery_url' => $deliveryUrl]);
}

if ($method === 'PATCH') {
    $body = api_json_body();
    if (!array_key_exists('paused', $body)) {
        api_error('paused boolean required', 422);
    }
    try {
        mercuryWebhookPause($tid, (bool) $body['paused']);
    } catch (\Throwable $e) {
        api_error('update failed: ' . $e->getMessage(), 500);
    }
    api_ok(['endpoint' => mercuryWebhookGet($tid)]);
}

api_error('method not allowed', 405);
