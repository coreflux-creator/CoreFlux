<?php
/**
 * Gusto — GET /api/gusto_oauth_start.php
 *
 * Initiates OAuth: stores per-request state in session and 302-redirects
 * the user's browser to Gusto's authorization page. Authenticated only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/gusto_service.php';

$ctx = api_require_auth();
RBAC::requirePermission($ctx['user'], 'payroll.run.disburse');

if (!gustoConfigured()) {
    api_error('Gusto integration not configured on this host. Set GUSTO_CLIENT_ID, GUSTO_CLIENT_SECRET, and GUSTO_REDIRECT_URI in core/config.local.php.', 503);
}

try {
    $url = gustoAuthorizationUrl((int) $ctx['tenant_id'], (int) ($ctx['user']['id'] ?? 0));
} catch (\Throwable $e) {
    api_error('Failed to build Gusto authorization URL: ' . $e->getMessage(), 500);
}

gustoAudit('payroll.gusto.connect_initiated', ['env' => gustoEnv()]);

// Browser-friendly redirect; clients that prefer JSON can pass ?format=json.
if (($_GET['format'] ?? '') === 'json') api_ok(['authorize_url' => $url]);
header('Location: ' . $url, true, 302);
exit;
