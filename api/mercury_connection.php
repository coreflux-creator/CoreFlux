<?php
/**
 * /api/mercury_connection.php — tenant-owned Mercury API token lifecycle.
 *
 *   GET  /api/mercury_connection.php?action=status
 *       → { connected: bool, status, label, api_token_last4, workspace_name, last_probe_at, last_probe_error }
 *
 *   POST /api/mercury_connection.php
 *       body: { api_token, label? }
 *       → probes via /accounts then upserts. Returns workspace_name + accounts_count.
 *
 *   POST /api/mercury_connection.php?action=disconnect
 *       → soft-revoke (status='revoked'). Token row preserved for audit.
 *
 * RBAC: `accounting.bank.manage`. Audit: `mercury.connection.connected` /
 * `mercury.connection.disconnected` / `mercury.connection.probe_failed`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/mercury_service.php';
require_once __DIR__ . '/../core/mercury_audit.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'accounting.bank.manage');

$method = api_method();
$action = (string) ($_GET['action'] ?? '');

function mcAudit(string $event, array $meta, int $tenantId, ?int $userId, array $opts = []): void
{
    try {
        mercuryAuditLogWrite($tenantId, $userId, $event, null, $meta, $opts);
    } catch (\Throwable $e) {
        error_log('[mercury.audit] failed: ' . $e->getMessage());
    }
}

// ----------------------------------------------------------------- GET status
if ($method === 'GET' && ($action === '' || $action === 'status')) {
    $row = mercuryGetConnection($tenantId);
    if (!$row) {
        api_ok(['connected' => false]);
    }
    api_ok([
        'connected'        => $row['status'] === 'active',
        'status'           => $row['status'],
        'label'            => $row['label'],
        'api_token_last4'  => $row['api_token_last4'],
        'workspace_name'   => $row['workspace_name'],
        'last_probe_at'    => $row['last_probe_at'],
        'last_probe_error' => $row['last_probe_error'],
    ]);
}

if ($method !== 'POST') api_error('Method not allowed', 405);

// ----------------------------------------------------------------- POST disconnect
if ($action === 'disconnect') {
    $before = mercuryAuditConnectionRow($tenantId);
    mercuryRevokeConnection($tenantId);
    mcAudit('mercury.connection.disconnected', [], $tenantId, $user['id'] ?? null, [
        'before' => $before,
        'after' => mercuryAuditConnectionRow($tenantId),
    ]);
    api_ok(['ok' => true, 'status' => 'revoked']);
}

// ----------------------------------------------------------------- POST connect (default)
$body  = api_json_body();
$token = trim((string) ($body['api_token'] ?? ''));
$label = isset($body['label']) ? trim((string) $body['label']) : null;
if ($token === '') api_error('api_token required', 422);
if (strlen($token) < 16) api_error('api_token looks invalid (too short)', 422);

$before = mercuryAuditConnectionRow($tenantId);
try {
    $result = mercuryStoreConnection($tenantId, $token, $label, $user['id'] ?? null);
} catch (MercuryApiException $e) {
    mcAudit('mercury.connection.probe_failed', [
        'http_status' => $e->httpStatus, 'error' => $e->getMessage(),
    ], $tenantId, $user['id'] ?? null, [
        'before' => $before,
        'after' => mercuryAuditConnectionRow($tenantId),
    ]);
    api_error('Mercury rejected the token: ' . $e->getMessage(), 422, [
        'http_status' => $e->httpStatus,
    ]);
}

mcAudit('mercury.connection.connected', [
    'workspace_name' => $result['workspace_name'],
    'accounts_count' => $result['accounts_count'],
    'label'          => $label,
], $tenantId, $user['id'] ?? null, [
    'before' => $before,
    'after' => mercuryAuditConnectionRow($tenantId),
]);

api_ok([
    'ok'             => true,
    'workspace_name' => $result['workspace_name'],
    'accounts_count' => $result['accounts_count'],
]);
