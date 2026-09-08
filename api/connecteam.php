<?php
/** Connecteam connection, capability probe, and dry-run reconciliation API. */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/connecteam/client.php';
require_once __DIR__ . '/../core/connecteam/reconcile.php';

$method = api_method();
$action = (string) (api_query('action') ?? '');
if ($action === '') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    if (preg_match('#/connecteam/([a-z_-]+)\.php$#i', $path, $match)) {
        $action = strtolower($match[1]);
    }
}
$action = str_replace('-', '_', strtolower($action));

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$userId = isset($user['id']) ? (int) $user['id'] : null;

function connecteamDecodeJsonColumn($value): ?array
{
    if (is_array($value)) return $value;
    if (!is_string($value) || $value === '') return null;
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}
switch ($action) {
    case 'status': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.connecteam.view');
        try {
            $row = connecteamConnection($tenantId);
            $auditStmt = getDB()->prepare(
                'SELECT id, action, ok, items_inspected, detail, occurred_at
                   FROM connecteam_sync_audit
                  WHERE tenant_id = :t
               ORDER BY occurred_at DESC
                  LIMIT 20'
            );
            $auditStmt->execute(['t' => $tenantId]);
            $audit = $auditStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($audit as &$event) {
                $event['id'] = (int) $event['id'];
                $event['ok'] = (bool) (int) $event['ok'];
                $event['items_inspected'] = (int) $event['items_inspected'];
                $event['detail'] = connecteamDecodeJsonColumn($event['detail'] ?? null);
            }
            unset($event);
            api_ok([
                'configured' => true,
                'connected' => (bool) ($row && $row['status'] !== 'revoked'),
                'status' => $row['status'] ?? null,
                'api_key_last4' => $row['api_key_last4'] ?? null,
                'region' => $row['region'] ?? 'us',
                'account' => [
                    'id' => $row['account_id'] ?? null,
                    'name' => $row['account_name'] ?? null,
                ],
                'last_probe_at' => $row['last_probe_at'] ?? null,
                'last_probe_error' => $row['last_probe_error'] ?? null,
                'probe' => connecteamDecodeJsonColumn($row['capability_snapshot'] ?? null),
                'inventory' => connecteamDecodeJsonColumn($row['inventory_snapshot'] ?? null),
                'audit' => $audit,
            ]);
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'connecteam_connections')) {
                api_ok([
                    'configured' => false,
                    'connected' => false,
                    'status' => null,
                    'migration_required' => true,
                    'message' => 'Connecteam setup is pending database migration 133.',
                    'audit' => [],
                ]);
            }
            api_error('Could not load Connecteam status: ' . $e->getMessage(), 500);
        }
    }

    case 'connect': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.connecteam.manage');
        $body = api_json_body();
        try {
            $probe = connecteamSaveApiKey(
                $tenantId,
                (string) ($body['api_key'] ?? ''),
                (string) ($body['region'] ?? 'us'),
                $userId
            );
        } catch (\InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        } catch (ConnecteamApiException $e) {
            api_error($e->getMessage(), $e->httpStatus === 401 ? 401 : 502);
        } catch (\Throwable $e) {
            api_error('Connecteam connection failed: ' . $e->getMessage(), 502);
        }
        api_ok(['ok' => true, 'probe' => $probe]);
    }

    case 'probe': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.connecteam.manage');
        try {
            api_ok(['ok' => true, 'probe' => connecteamProbe($tenantId, $userId)]);
        } catch (ConnecteamApiException $e) {
            api_error($e->getMessage(), $e->httpStatus === 401 ? 401 : 502);
        } catch (\Throwable $e) {
            api_error('Connecteam probe failed: ' . $e->getMessage(), 502);
        }
    }

    case 'preview': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.connecteam.manage');
        try {
            api_ok(['ok' => true, 'preview' => connecteamDryRunPreview($tenantId, $userId)]);
        } catch (ConnecteamApiException $e) {
            $code = in_array($e->httpStatus, [401, 403], true) ? $e->httpStatus : 502;
            api_error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            api_error('Connecteam preview failed: ' . $e->getMessage(), 502);
        }
    }

    case 'disconnect': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.connecteam.manage');
        connecteamDisconnect($tenantId, $userId);
        api_ok(['ok' => true]);
    }

    default:
        api_error('Unknown Connecteam action', 404);
}
