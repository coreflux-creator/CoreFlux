<?php
/** Tenant-scoped Quanta connection, explicit placement routes, and reviewed time import. */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/quanta/sync.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$actorId = (int) ($user['id'] ?? 0);
$method = api_method();
$action = (string) (api_query('action') ?? 'status');

function quantaApiProbe(string $key): void
{
    foreach (['/workers', '/worksites', '/time-entries'] as $path) {
        $result = quantaGet($key, $path, ['limit' => 1]);
        if (!isset($result['items']) || !is_array($result['items'])) {
            throw new QuantaApiException("Quanta {$path} did not return a list");
        }
    }
}

function quantaApiDate(string $date, string $label): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException("{$label} must be YYYY-MM-DD");
    return $date;
}

function quantaApiWindow(array $body): array
{
    $since = quantaApiDate((string) ($body['changed_since'] ?? date('Y-m-d', strtotime('-30 days'))), 'Changed since');
    $status = (string) ($body['source_status'] ?? 'approved');
    if (!in_array($status, ['approved', 'submitted,approved'], true)) {
        throw new InvalidArgumentException('Choose approved only or submitted and approved');
    }
    return [$since, $status];
}

function quantaApiPlacements(int $tenantId): array
{
    $placementTenant = effectiveTenantIdForModule('placements', $tenantId) ?? $tenantId;
    $peopleTenant = effectiveTenantIdForModule('people', $tenantId) ?? $tenantId;
    $stmt = getDB()->prepare(
        'SELECT p.id, p.person_id, p.title, p.end_client_name, p.status, p.start_date,
                COALESCE(p.actual_end_date, p.end_date) AS end_date,
                pe.email_primary AS person_email,
                CONCAT_WS(" ", pe.first_name, pe.last_name) AS person_name
           FROM placements p
           JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = :pet AND pe.deleted_at IS NULL
          WHERE p.tenant_id = :pt AND p.deleted_at IS NULL AND p.status != "cancelled"
       ORDER BY FIELD(p.status, "active", "draft", "ended"), person_name, p.id'
    );
    $stmt->execute(['pt' => $placementTenant, 'pet' => $peopleTenant]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

try {
    if ($method === 'GET' && $action === 'status') {
        rbac_legacy_require($user, 'integrations.quanta.view');
        $connection = quantaConnection($tenantId);
        $tenant = getDB()->prepare('SELECT name FROM tenants WHERE id = :id LIMIT 1');
        $tenant->execute(['id' => $tenantId]);
        api_ok([
            'configured' => true,
            'connected' => $connection && $connection['status'] === 'active',
            'status' => $connection['status'] ?? null,
            'api_key_last4' => $connection['api_key_last4'] ?? null,
            'last_probe_at' => $connection['last_probe_at'] ?? null,
            'last_probe_error' => $connection['last_probe_error'] ?? null,
            'last_import_at' => $connection['last_import_at'] ?? null,
            'workspace' => ['id' => $tenantId, 'name' => $tenant->fetchColumn() ?: "Workspace {$tenantId}"],
            'route_count' => count(quantaRoutes($tenantId)),
        ]);
    }

    if ($method === 'GET' && $action === 'catalog') {
        rbac_legacy_require($user, 'integrations.quanta.view');
        $catalog = quantaCatalog(quantaApiKey($tenantId));
        api_ok($catalog + ['placements' => quantaApiPlacements($tenantId), 'routes' => quantaRoutes($tenantId)]);
    }

    if ($method !== 'POST') api_error('Method not allowed', 405);
    rbac_legacy_require($user, 'integrations.quanta.manage');
    $body = api_json_body();

    if ($action === 'connect') {
        $key = trim((string) ($body['api_key'] ?? ''));
        if (strlen($key) < 12 || strlen($key) > 1500) throw new InvalidArgumentException('Enter a valid Quanta API key');
        if ((int) ($body['confirm_tenant_id'] ?? 0) !== $tenantId) {
            throw new InvalidArgumentException('Confirm the CoreFlux workspace before connecting Quanta');
        }
        $existing = quantaConnection($tenantId);
        if ($existing && empty($body['confirm_same_quanta_workspace'])) {
            throw new InvalidArgumentException('Confirm this key belongs to the same Quanta workspace as the previous connection');
        }
        quantaApiProbe($key);
        $ciphertext = encryptField($key);
        $params = ['t' => $tenantId, 'k' => $ciphertext, 'l' => substr($key, -4), 'u' => $actorId];
        getDB()->prepare(
            'INSERT INTO quanta_connections
                (tenant_id, api_key_ct, api_key_last4, status, last_probe_at, last_probe_error, connected_by_user_id)
             VALUES (:t,:k,:l,"active",NOW(),NULL,:u)
             ON DUPLICATE KEY UPDATE api_key_ct = VALUES(api_key_ct), api_key_last4 = VALUES(api_key_last4),
                 status = "active", last_probe_at = NOW(), last_probe_error = NULL,
                 connected_by_user_id = VALUES(connected_by_user_id)'
        )->execute($params);
        platformAuditLogWrite($tenantId, $actorId, 'quanta.connection.connected', null, [], ['source' => 'quanta']);
        api_ok(['connected' => true, 'api_key_last4' => substr($key, -4)]);
    }

    if ($action === 'disconnect') {
        getDB()->prepare('UPDATE quanta_connections SET api_key_ct = NULL, status = "revoked" WHERE tenant_id = :t')
            ->execute(['t' => $tenantId]);
        platformAuditLogWrite($tenantId, $actorId, 'quanta.connection.disconnected', null, [], ['source' => 'quanta']);
        api_ok(['connected' => false]);
    }

    if ($action === 'probe') {
        $key = quantaApiKey($tenantId);
        try {
            quantaApiProbe($key);
            getDB()->prepare('UPDATE quanta_connections SET last_probe_at = NOW(), last_probe_error = NULL WHERE tenant_id = :t')
                ->execute(['t' => $tenantId]);
        } catch (Throwable $e) {
            getDB()->prepare('UPDATE quanta_connections SET last_probe_at = NOW(), last_probe_error = :e WHERE tenant_id = :t')
                ->execute(['e' => substr($e->getMessage(), 0, 500), 't' => $tenantId]);
            throw $e;
        }
        api_ok(['ok' => true]);
    }

    if ($action === 'save_routes') {
        $routes = $body['routes'] ?? null;
        if (!is_array($routes) || !array_is_list($routes) || !$routes || count($routes) > 100) {
            throw new InvalidArgumentException('Provide 1 to 100 placement routes');
        }
        $catalog = quantaCatalog(quantaApiKey($tenantId));
        $workers = array_fill_keys(array_map('strval', array_column($catalog['workers'], 'id')), true);
        $sites = array_fill_keys(array_map('strval', array_column($catalog['worksites'], 'id')), true);
        $placementTenant = effectiveTenantIdForModule('placements', $tenantId) ?? $tenantId;
        $peopleTenant = effectiveTenantIdForModule('people', $tenantId) ?? $tenantId;
        $pdo = getDB();
        $owns = cf_tx_begin($pdo);
        try {
            foreach ($routes as $route) {
                if (!is_array($route)) throw new InvalidArgumentException('Invalid route');
                $worker = trim((string) ($route['worker_id'] ?? ''));
                $site = trim((string) ($route['worksite_id'] ?? ''));
                $dimensions = quantaDimensions($route['dimension_values'] ?? null);
                $dimensionKey = quantaDimensionKey($dimensions);
                $placementId = (int) ($route['placement_id'] ?? 0);
                $from = quantaApiDate((string) ($route['effective_from'] ?? ''), 'Route start');
                $to = trim((string) ($route['effective_to'] ?? ''));
                if ($to !== '') quantaApiDate($to, 'Route end');
                if ($to !== '' && $to < $from) throw new InvalidArgumentException('Route end is before start');
                if ($worker === '' || strlen($worker) > 128 || !isset($workers[$worker])) throw new InvalidArgumentException('Choose a worker from Quanta');
                if (strlen($site) > 128 || ($site !== '' && !isset($sites[$site]))) throw new InvalidArgumentException('Choose a worksite from Quanta');
                $placement = $pdo->prepare(
                    'SELECT p.id FROM placements p JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = :pet
                     WHERE p.id = :id AND p.tenant_id = :pt AND p.deleted_at IS NULL AND pe.deleted_at IS NULL
                       AND p.status != "cancelled" LIMIT 1'
                );
                $placement->execute(['id' => $placementId, 'pt' => $placementTenant, 'pet' => $peopleTenant]);
                if (!$placement->fetchColumn()) throw new InvalidArgumentException("Placement PL-{$placementId} is not available");
                $routeId = (int) ($route['id'] ?? 0);
                if ($routeId > 0) {
                    $owned = $pdo->prepare('SELECT id FROM quanta_time_routes WHERE tenant_id = :t AND id = :id FOR UPDATE');
                    $owned->execute(['t' => $tenantId, 'id' => $routeId]);
                    if (!$owned->fetchColumn()) throw new InvalidArgumentException('Route is not in this workspace');
                }
                $overlap = $pdo->prepare(
                    'SELECT id FROM quanta_time_routes WHERE tenant_id = :t AND worker_id = :w AND worksite_id = :s
                        AND dimension_key = :dk
                        AND id != :id AND effective_from <= :new_to
                        AND COALESCE(effective_to,"9999-12-31") >= :new_from LIMIT 1 FOR UPDATE'
                );
                $overlap->execute([
                    't' => $tenantId, 'w' => $worker, 's' => $site, 'dk' => $dimensionKey, 'id' => $routeId,
                    'new_to' => $to ?: '9999-12-31', 'new_from' => $from,
                ]);
                if ($overlap->fetchColumn()) throw new InvalidArgumentException('Worker/worksite route dates overlap');
                $params = ['t' => $tenantId, 'w' => $worker, 's' => $site, 'dk' => $dimensionKey,
                    'dv' => json_encode($dimensions, JSON_THROW_ON_ERROR), 'p' => $placementId, 'f' => $from, 'e' => $to ?: null];
                if ($routeId > 0) {
                    $pdo->prepare(
                        'UPDATE quanta_time_routes SET worker_id=:w, worksite_id=:s, dimension_key=:dk,
                         dimension_values_json=:dv, placement_id=:p,
                         effective_from=:f, effective_to=:e WHERE tenant_id=:t AND id=:id'
                    )->execute($params + ['id' => $routeId]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO quanta_time_routes
                            (tenant_id,worker_id,worksite_id,dimension_key,dimension_values_json,
                             placement_id,effective_from,effective_to,created_by_user_id)
                         VALUES (:t,:w,:s,:dk,:dv,:p,:f,:e,:u)'
                    )->execute($params + ['u' => $actorId]);
                }
            }
            cf_tx_commit($pdo, $owns);
        } catch (Throwable $e) {
            cf_tx_rollback($pdo, $owns);
            throw $e;
        }
        platformAuditLogWrite($tenantId, $actorId, 'quanta.routes.saved', null, ['count' => count($routes)], ['source' => 'quanta']);
        api_ok(['routes' => quantaRoutes($tenantId)]);
    }

    if ($action === 'delete_route') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) throw new InvalidArgumentException('Route ID required');
        getDB()->prepare('DELETE FROM quanta_time_routes WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => $tenantId, 'id' => $id]);
        platformAuditLogWrite($tenantId, $actorId, 'quanta.route.deleted', $id, [], ['source' => 'quanta']);
        api_ok(['routes' => quantaRoutes($tenantId)]);
    }

    if ($action === 'preview' || $action === 'import') {
        [$since, $sourceStatus] = quantaApiWindow($body);
        $key = quantaApiKey($tenantId);
        $sites = quantaWorksitesById(quantaListAll($key, '/worksites', [], 2000));
        $rawEntries = quantaEntries($key, $since, $sourceStatus);
        if ($action === 'preview') {
            $preview = quantaPreviewRows($tenantId, $rawEntries, $sites, $sourceStatus);
            $offset = max(0, (int) ($body['offset'] ?? 0));
            $preview['rows'] = array_slice($preview['rows'], $offset, 200);
            $preview['offset'] = $offset;
            $preview['page_size'] = 200;
            api_ok($preview);
        }
        $ids = $body['entry_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids)) throw new InvalidArgumentException('Select Quanta entries from preview');
        $result = quantaImportSelected($tenantId, $actorId, $rawEntries, $sites, $sourceStatus, $ids);
        platformAuditLogWrite($tenantId, $actorId, 'quanta.time.imported', null, [
            'selected' => count($ids), 'inserted' => $result['inserted'], 'updated' => $result['updated'],
        ], ['source' => 'quanta']);
        api_ok($result);
    }
    api_error('Unknown Quanta action', 404);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 422);
} catch (QuantaApiException $e) {
    $code = in_array($e->httpStatus, [401, 403], true) ? $e->httpStatus : 502;
    api_error($e->getMessage(), $code);
} catch (PDOException $e) {
    error_log('Quanta database error: ' . $e->getMessage());
    api_error('Quanta database request failed', 500);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 409);
} catch (Throwable $e) {
    error_log('Quanta integration: ' . $e->getMessage());
    api_error('Quanta integration could not complete the request', 500);
}
