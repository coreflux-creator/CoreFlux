<?php
/**
 * Gusto — GET /api/gusto_oauth_callback.php
 *
 * Receives `?code=<>&state=<>` from Gusto after user grants access.
 * Validates state (single-use, session-bound), exchanges code for tokens,
 * persists encrypted tokens + company_uuid to tenant_gusto_connections,
 * then redirects the user back to the Payroll Settings page.
 *
 * THIS FILE INTENTIONALLY does NOT use api_require_auth's tenant gate
 * because OAuth callbacks run in the user's session but with no app-level
 * tenant context. Auth is still required (must be logged in).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/gusto_service.php';

if (api_method() !== 'GET') api_error('Method not allowed', 405);
if (!isAuthenticated()) {
    // Bounce through login then come back here.
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}

$err   = (string) ($_GET['error'] ?? '');
$desc  = (string) ($_GET['error_description'] ?? '');
$code  = (string) ($_GET['code']  ?? '');
$state = (string) ($_GET['state'] ?? '');
$pendingOAuth = is_array($_SESSION['gusto_oauth'] ?? null) ? $_SESSION['gusto_oauth'] : [];
$pendingAuditOpts = array_filter([
    'tenant_id' => (int) ($pendingOAuth['tenant_id'] ?? ($_SESSION['tenant_id'] ?? 0)),
    'actor_user_id' => (int) ($pendingOAuth['user_id'] ?? ($_SESSION['user']['id'] ?? 0)),
], static fn($value): bool => (int) $value > 0);

if ($err !== '') {
    gustoAudit('payroll.gusto.connect_denied', ['error' => $err, 'description' => $desc], null, $pendingAuditOpts);
    _gustoCallbackBounce(false, 'denied', $err . ($desc ? (': ' . $desc) : ''));
}
if ($code === '' || $state === '') {
    _gustoCallbackBounce(false, 'missing_params', 'Missing code or state in callback');
}

try {
    $saved = gustoConsumeOAuthState($state);
} catch (GustoAuthException $e) {
    gustoAudit('payroll.gusto.connect_state_invalid', ['error' => $e->getMessage()], null, $pendingAuditOpts);
    _gustoCallbackBounce(false, 'state_invalid', $e->getMessage());
}

$tenantId = (int) ($saved['tenant_id'] ?? 0);
$userId   = (int) ($saved['user_id']   ?? 0);
if ($tenantId <= 0) _gustoCallbackBounce(false, 'state_invalid', 'No tenant in saved state');
$_SESSION['tenant_id'] = $tenantId;   // restore tenant scope for the persistence step
$auditOpts = array_filter([
    'tenant_id' => $tenantId,
    'actor_user_id' => $userId,
], static fn($value): bool => (int) $value > 0);

try {
    $token = gustoExchangeCodeForToken($code);
} catch (GustoApiException $e) {
    gustoAudit('payroll.gusto.connect_exchange_failed', [
        'error' => $e->getMessage(), 'http' => $e->httpCode,
    ], null, $auditOpts);
    _gustoCallbackBounce(false, 'exchange_failed', $e->getMessage());
}

try {
    $connectionId = gustoSaveConnection($tenantId, $userId, $token);
} catch (\Throwable $e) {
    gustoAudit('payroll.gusto.connect_persist_failed', ['error' => $e->getMessage()], null, $auditOpts);
    _gustoCallbackBounce(false, 'persist_failed', $e->getMessage());
}

gustoAudit('payroll.gusto.connected', [
    'connection_id' => $connectionId, 'env' => gustoEnv(),
    'scopes' => $token['scope'] ?? gustoDefaultScopes(),
], $connectionId, array_merge($auditOpts, [
    'after' => gustoConnectionAuditRow($tenantId, $connectionId),
]));

_gustoCallbackBounce(true, 'connected', null, $connectionId);

// ---------------------------------------------------------------- helpers
function _gustoCallbackBounce(bool $ok, string $reason, ?string $detail = null, ?int $connectionId = null): void
{
    $params = ['gusto' => $ok ? 'ok' : 'err', 'reason' => $reason];
    if ($detail)        $params['detail']        = substr($detail, 0, 240);
    if ($connectionId)  $params['connection_id'] = (string) $connectionId;
    $url = '/spa.php#/modules/payroll/settings?' . http_build_query($params);
    header('Location: ' . $url, true, 302);
    exit;
}
