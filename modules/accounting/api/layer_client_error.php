<?php
/**
 * POST /api/accounting/layer-client-error
 *
 * Capture LayerFi embedded-component errors (and lightweight lifecycle
 * events) in the CoreFlux integration audit log. Requires accounting.view.
 *
 * SECURITY: only a short, masked message is stored. Never persist tokens,
 * bank credentials, or full account numbers here.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/layer/layer_audit.php';

if (!layer_enabled()) api_error('Not found', 404);

$ctx  = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'POST') api_error('Method not allowed', 405);

rbac_legacy_require($user, 'accounting.view');

$body    = api_json_body();
$type    = mb_substr((string) ($body['type']  ?? 'unknown'), 0, 64);
$scope   = mb_substr((string) ($body['scope'] ?? 'unknown'), 0, 64);
$payload = $body['payload'] ?? [];
$message = is_array($payload) ? (string) ($payload['message'] ?? '') : (string) $payload;

// Map known lifecycle events to their dedicated audit actions; everything
// else is recorded as an embedded-component error.
$eventMap = [
    'transaction_categorized' => ['layer.transaction.categorized', 'success'],
    'transactions_fetched'    => ['layer.transactions.fetched', 'success'],
];
[$action, $status] = $eventMap[$type] ?? ['layer.embedded_component.error', 'error'];

layer_audit($action, $status, [
    'tenant_id'     => $ctx['tenant_id'],
    'object_type'   => 'component',
    'object_id'     => $scope,
    'error_message' => $status === 'error' ? $message : null,
    'metadata'      => ['type' => $type, 'scope' => $scope],
]);

api_ok(['ok' => true]);
