<?php
/**
 * Authenticated browser handoff to Intuit OAuth.
 *
 * This is intentionally a top-level navigation rather than an XHR endpoint:
 * the browser presents the existing CoreFlux PHP session first, CoreFlux
 * creates the single-use OAuth state, then the response redirects to Intuit.
 */
declare(strict_types=1);

require_once __DIR__ . '/core/api_bootstrap.php';
require_once __DIR__ . '/core/RBAC.php';
require_once __DIR__ . '/core/qbo/client.php';

if (api_method() !== 'GET') {
    api_error('Method not allowed', 405);
}

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'integrations.qbo.manage');

if (!qboConfigured()) {
    api_error('QuickBooks is not configured on this pod.', 503);
}

try {
    $result = qboBuildAuthorizeUrl($tenantId, $user['id'] ?? null);
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: ' . $result['url'], true, 302);
exit;
