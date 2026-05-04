<?php
/**
 * Payroll — Gusto connection status + disconnect.
 *
 *   GET     → connection summary for the current tenant (no tokens leaked)
 *   DELETE  → revoke the active connection (soft: status='revoked')
 *
 * Used by PayrollSettings.jsx to decide whether to show "Connect Gusto" or
 * "Connected to Gusto · disconnect".
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/gusto_service.php';

$ctx = api_require_auth();

switch (api_method()) {
    case 'GET': {
        $configured = gustoConfigured();
        $conn = $configured ? gustoActiveConnection((int) $ctx['tenant_id']) : null;

        api_ok([
            'configured'    => $configured,
            'env'           => gustoEnv(),
            'connect_url'   => $configured ? '/api/gusto_oauth_start.php' : null,
            'connection'    => $conn ? [
                'id'                       => (int) $conn['id'],
                'company_uuid'             => $conn['company_uuid'],
                'company_name'             => $conn['company_name'],
                'env'                      => $conn['env'],
                'status'                   => $conn['status'],
                'scopes'                   => $conn['scopes'],
                'connected_at'             => $conn['connected_at'],
                'access_token_expires_at'  => $conn['access_token_expires_at'],
                'last_refreshed_at'        => $conn['last_refreshed_at'],
                'last_used_at'             => $conn['last_used_at'],
                'last_error'               => $conn['last_error'],
            ] : null,
        ]);
    }

    case 'DELETE': {
        RBAC::requirePermission($ctx['user'], 'payroll.run.disburse');
        $conn = gustoActiveConnection((int) $ctx['tenant_id']);
        if (!$conn) api_error('No active Gusto connection', 404);
        scopedUpdate('tenant_gusto_connections', (int) $conn['id'], ['status' => 'revoked']);
        gustoAudit('payroll.gusto.disconnected', ['connection_id' => (int) $conn['id']], (int) $conn['id']);
        api_ok(['ok' => true]);
    }
}

api_error('Method not allowed', 405);
