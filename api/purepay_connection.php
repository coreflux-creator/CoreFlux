<?php
/**
 * Tenant-owned Pure//Pay API key and webhook setup.
 * GET ?action=status; POST connect|probe|webhook_secret|disconnect.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/purepay_service.php';
require_once __DIR__ . '/../core/purepay_webhooks.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'accounting.bank.manage');
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$base = trim((string) (getenv('APP_PUBLIC_URL') ?: (defined('APP_URL') ? constant('APP_URL') : '')));
if ($base === '') $base = 'https://www.corefluxapp.com';
$deliveryUrl = rtrim($base, '/') . '/api/webhooks/purepay.php?t=' . $tenantId;

function ppAudit(string $event, array $meta, int $tenantId, ?int $userId): void
{
    try {
        getDB()->prepare(
            'INSERT INTO audit_log (tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at)
             VALUES (:t,:u,:e,NULL,:m,:ip,NOW())'
        )->execute(['t'=>$tenantId,'u'=>$userId,'e'=>$event,'m'=>json_encode($meta),'ip'=>$_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (\Throwable $_) {}
}

if ($method === 'GET' && ($action === '' || $action === 'status')) {
    $row = purepayGetConnection($tenantId);
    api_ok([
        'configured' => purepayServiceConfigured(),
        'connected' => $row && ($row['status'] ?? '') === 'active',
        'status' => $row['status'] ?? null,
        'label' => $row['label'] ?? null,
        'api_key_last4' => $row['api_key_last4'] ?? null,
        'wallet_balance_cents' => isset($row['wallet_balance_cents']) ? (int) $row['wallet_balance_cents'] : null,
        'last_probe_at' => $row['last_probe_at'] ?? null,
        'last_probe_error' => $row['last_probe_error'] ?? null,
        'webhook_configured' => !empty($row['webhook_secret']),
        'webhook_secret_last4' => $row['webhook_secret_last4'] ?? null,
        'delivery_url' => $deliveryUrl,
        'recent_events' => purepayWebhookRecent($tenantId, 30),
    ]);
}
if ($method !== 'POST') api_error('Method not allowed', 405);

if ($action === 'disconnect') {
    purepayRevokeConnection($tenantId);
    ppAudit('purepay.connection.disconnected', [], $tenantId, $user['id'] ?? null);
    api_ok(['ok'=>true,'status'=>'revoked']);
}
if ($action === 'probe') {
    try { $result = purepayProbeConnection($tenantId); }
    catch (PurePayApiException $e) { api_error($e->getMessage(), 422, ['http_status'=>$e->httpStatus]); }
    ppAudit('purepay.connection.probed', [], $tenantId, $user['id'] ?? null);
    api_ok(['ok'=>true] + $result);
}
if ($action === 'webhook_secret') {
    $body = api_json_body();
    try { purepaySaveWebhookSecret($tenantId, (string) ($body['webhook_secret'] ?? '')); }
    catch (\InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
    catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    ppAudit('purepay.webhook.configured', [], $tenantId, $user['id'] ?? null);
    api_ok(['ok'=>true,'webhook_configured'=>true]);
}
if ($action !== '' && $action !== 'connect') api_error('Unknown Pure//Pay connection action', 404);

$body = api_json_body();
$apiKey = trim((string) ($body['api_key'] ?? ''));
$label = isset($body['label']) ? trim((string) $body['label']) : null;
if ($apiKey === '') api_error('api_key required', 422);
if (strlen($apiKey) < 12) api_error('api_key looks invalid (too short)', 422);
try { $result = purepayStoreConnection($tenantId, $apiKey, $label, $user['id'] ?? null); }
catch (PurePayApiException $e) {
    ppAudit('purepay.connection.probe_failed', ['http_status'=>$e->httpStatus,'error'=>$e->getMessage()], $tenantId, $user['id'] ?? null);
    api_error('Pure//Pay rejected the key: ' . $e->getMessage(), 422, ['http_status'=>$e->httpStatus]);
}
ppAudit('purepay.connection.connected', ['label'=>$label], $tenantId, $user['id'] ?? null);
api_ok(['ok'=>true] + $result);
