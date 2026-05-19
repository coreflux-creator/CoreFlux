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

    case 'POST': {
        // Sandbox shortcut: persist a connection from manually-pasted tokens
        // (Gusto's Demo Partner Managed Companies page hands out access +
        // refresh tokens already issued for our app — no OAuth dance needed).
        // This path requires payroll.run.disburse so a non-admin can't drop
        // arbitrary tokens into the connection table.
        rbac_legacy_require($ctx['user'], 'payroll.run.disburse');
        $body = api_json_body();
        api_require_fields($body, ['company_uuid', 'access_token', 'refresh_token']);

        if (gustoEnv() !== 'sandbox') {
            api_error('Manual token paste is only allowed in sandbox env. Use the OAuth flow for production.', 409);
        }

        try {
            $id = gustoSaveConnection(
                (int) $ctx['tenant_id'],
                (int) ($ctx['user']['id'] ?? 0),
                [
                    'access_token'  => trim((string) $body['access_token']),
                    'refresh_token' => trim((string) $body['refresh_token']),
                    'token_type'    => 'bearer',
                    'expires_in'    => (int) ($body['expires_in'] ?? 7200),
                    'scope'         => (string) ($body['scope'] ?? gustoDefaultScopes()),
                ],
                trim((string) $body['company_uuid']),
                isset($body['company_name']) ? trim((string) $body['company_name']) : null
            );
            gustoAudit('payroll.gusto.connected_manual', [
                'connection_id' => $id, 'env' => 'sandbox',
                'company_uuid'  => trim((string) $body['company_uuid']),
            ], $id);
            api_ok(['ok' => true, 'connection_id' => $id], 201);
        } catch (\Throwable $e) {
            api_error('Manual connect failed: ' . $e->getMessage(), 422);
        }
    }

    case 'DELETE': {
        rbac_legacy_require($ctx['user'], 'payroll.run.disburse');
        $conn = gustoActiveConnection((int) $ctx['tenant_id']);
        if (!$conn) api_error('No active Gusto connection', 404);
        scopedUpdate('tenant_gusto_connections', (int) $conn['id'], ['status' => 'revoked']);
        gustoAudit('payroll.gusto.disconnected', ['connection_id' => (int) $conn['id']], (int) $conn['id']);
        api_ok(['ok' => true]);
    }
}

api_error('Method not allowed', 405);
