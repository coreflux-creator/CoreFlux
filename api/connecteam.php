<?php
/** Connecteam connection, capability probe, and dry-run reconciliation API. */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/connecteam/client.php';
require_once __DIR__ . '/../core/connecteam/reconcile.php';
require_once __DIR__ . '/../core/integrations/entity_mappings.php';
require_once __DIR__ . '/../modules/people/lib/audit.php';
require_once __DIR__ . '/../modules/people/lib/people.php';

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

function connecteamApiPerson(int $tenantId, int $personId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT id, first_name, last_name, email_primary, phone_primary, classification, status
           FROM people
          WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $personId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['id'] = (int) $row['id'];
    $row['name'] = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
    return $row;
}

function connecteamApiPeopleSearch(int $tenantId, string $query): array
{
    $query = trim($query);
    $personId = 0;
    if (preg_match('/^(?:P[- ]?)?(\d+)$/i', $query, $match)) $personId = (int) $match[1];
    $params = ['t' => $tenantId];
    $where = ['p.tenant_id = :t', 'p.deleted_at IS NULL'];
    if ($query !== '') {
        $like = '%' . $query . '%';
        $where[] = '(p.id = :person_id OR p.first_name LIKE :q1 OR p.last_name LIKE :q2 '
            . 'OR CONCAT(p.first_name, " ", p.last_name) LIKE :q3 '
            . 'OR p.email_primary LIKE :q4 OR p.email_secondary LIKE :q5 '
            . 'OR p.phone_primary LIKE :q6 OR p.phone_secondary LIKE :q7)';
        $params += [
            'person_id' => $personId,
            'q1' => $like,
            'q2' => $like,
            'q3' => $like,
            'q4' => $like,
            'q5' => $like,
            'q6' => $like,
            'q7' => $like,
        ];
    }
    $stmt = getDB()->prepare(
        'SELECT p.id, p.first_name, p.last_name,
                p.email_primary, p.email_secondary, p.phone_primary, p.phone_secondary,
                p.classification, p.status
           FROM people p
          WHERE ' . implode(' AND ', $where) . '
       ORDER BY (p.status = "active") DESC, p.last_name, p.first_name, p.id
          LIMIT 25'
    );
    $stmt->execute($params);
    $people = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (!$people) return [];

    $mappingParams = ['t' => $tenantId];
    $mappingPlaceholders = [];
    foreach ($people as $index => $person) {
        $key = 'person_' . $index;
        $mappingPlaceholders[] = ':' . $key;
        $mappingParams[$key] = (int) $person['id'];
    }
    $mapStmt = getDB()->prepare(
        'SELECT internal_entity_id, source_system, external_id
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND internal_entity_type = "person"
            AND sync_status <> "deleted_in_source"
            AND internal_entity_id IN (' . implode(',', $mappingPlaceholders) . ')
       ORDER BY source_system'
    );
    $mapStmt->execute($mappingParams);
    $identities = [];
    foreach (($mapStmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $mapping) {
        $identities[(int) $mapping['internal_entity_id']][] = [
            'source' => (string) $mapping['source_system'],
            'external_id' => (string) $mapping['external_id'],
        ];
    }
    foreach ($people as &$person) {
        $person['id'] = (int) $person['id'];
        $person['name'] = trim((string) $person['first_name'] . ' ' . (string) $person['last_name']);
        $person['identities'] = $identities[$person['id']] ?? [];
    }
    unset($person);
    return $people;
}

function connecteamApiSourceUser(int $tenantId, string $sourceUserId): array
{
    $sourceUserId = trim($sourceUserId);
    if ($sourceUserId === '') api_error('source_user_id required', 422);
    $sourceUser = connecteamFindSourceUser($tenantId, $sourceUserId);
    if (!$sourceUser) api_error('That Connecteam user is no longer available in this account', 404);
    return $sourceUser;
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

    case 'people_search': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.connecteam.manage');
        rbac_legacy_require($user, 'people.view');
        api_ok(['people' => connecteamApiPeopleSearch($tenantId, (string) (api_query('q') ?? ''))]);
    }

    case 'link_person': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.connecteam.manage');
        rbac_legacy_require($user, 'people.view');
        $body = api_json_body();
        $sourceUserId = trim((string) ($body['source_user_id'] ?? ''));
        $personId = (int) ($body['person_id'] ?? 0);
        if ($personId <= 0) api_error('person_id required', 422);
        $person = connecteamApiPerson($tenantId, $personId);
        if (!$person) api_error('CoreFlux person not found', 404);
        $sourceUser = connecteamApiSourceUser($tenantId, $sourceUserId);

        $sourceConflict = mappingFindInternal($tenantId, 'connecteam', 'person', $sourceUserId);
        if ($sourceConflict && (int) $sourceConflict['internal_entity_id'] !== $personId) {
            api_error('This Connecteam user is already linked to another CoreFlux person. Unlink it first.', 409, [
                'conflict_person_id' => (int) $sourceConflict['internal_entity_id'],
            ]);
        }
        $personConflict = mappingFindExternal($tenantId, 'connecteam', 'person', $personId);
        if ($personConflict && (string) $personConflict['external_id'] !== $sourceUserId) {
            api_error('This CoreFlux person already has a different Connecteam identity. Unlink it first.', 409, [
                'conflict_source_user_id' => (string) $personConflict['external_id'],
            ]);
        }

        $mapping = mappingUpsert(
            $tenantId, 'connecteam', 'person', $sourceUserId, $personId,
            $sourceUser, 'pull', $userId
        );
        connecteamAudit($tenantId, 'person_linked', [
            'actor_user_id' => $userId,
            'items_inspected' => 1,
            'detail' => ['source_user_id' => $sourceUserId, 'person_id' => $personId],
        ]);
        api_ok([
            'ok' => true,
            'person' => $person,
            'identity' => [
                'mapping_id' => (int) $mapping['id'],
                'source' => 'connecteam',
                'external_id' => $sourceUserId,
                'person_id' => $personId,
            ],
        ]);
    }

    case 'unlink_person': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.connecteam.manage');
        $body = api_json_body();
        $sourceUserId = trim((string) ($body['source_user_id'] ?? ''));
        if ($sourceUserId === '') api_error('source_user_id required', 422);
        $mapping = mappingFindInternal($tenantId, 'connecteam', 'person', $sourceUserId);
        if (!$mapping) api_error('Connecteam identity link not found', 404);
        mappingDelete($tenantId, 'connecteam', 'person', $sourceUserId);
        connecteamAudit($tenantId, 'person_unlinked', [
            'actor_user_id' => $userId,
            'items_inspected' => 1,
            'detail' => [
                'source_user_id' => $sourceUserId,
                'person_id' => (int) $mapping['internal_entity_id'],
            ],
        ]);
        api_ok(['ok' => true]);
    }

    case 'create_person': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.connecteam.manage');
        rbac_legacy_require($user, 'people.manage');
        $body = api_json_body();
        $sourceUserId = trim((string) ($body['source_user_id'] ?? ''));
        $sourceUser = connecteamApiSourceUser($tenantId, $sourceUserId);
        $firstName = trim((string) ($body['first_name'] ?? connecteamUserFirstName($sourceUser)));
        $lastName = trim((string) ($body['last_name'] ?? connecteamUserLastName($sourceUser)));
        $email = connecteamNormalizeEmail((string) ($body['email_primary'] ?? connecteamUserEmail($sourceUser)));
        $phone = trim((string) ($body['phone_primary'] ?? connecteamUserPhone($sourceUser)));
        $classification = strtolower(trim((string) ($body['classification'] ?? '')));
        $allowed = ['w2', '1099', 'c2c', 'temp', 'perm', 'candidate', 'alumni'];
        if ($firstName === '' || $lastName === '') api_error('First and last name are required', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('A valid email is required', 422);
        if (!in_array($classification, $allowed, true)) api_error('Choose a valid worker classification', 422);
        if (mappingFindInternal($tenantId, 'connecteam', 'person', $sourceUserId)) {
            api_error('This Connecteam user is already linked', 409);
        }
        $duplicate = getDB()->prepare(
            'SELECT id FROM people
              WHERE tenant_id = :t
                AND (LOWER(email_primary) = LOWER(:email_primary)
                     OR LOWER(email_secondary) = LOWER(:email_secondary))
                AND deleted_at IS NULL
              LIMIT 1'
        );
        $duplicate->execute([
            't' => $tenantId,
            'email_primary' => $email,
            'email_secondary' => $email,
        ]);
        $duplicateId = (int) ($duplicate->fetchColumn() ?: 0);
        if ($duplicateId > 0) {
            api_error('A CoreFlux person already uses this email. Link that existing person instead.', 409, [
                'conflict_person_id' => $duplicateId,
            ]);
        }

        $pdo = getDB();
        try {
            $pdo->beginTransaction();
            $personId = scopedInsert('people', [
                'tenant_id' => $tenantId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email_primary' => $email,
                'phone_primary' => $phone !== '' ? $phone : null,
                'classification' => $classification,
                'status' => 'active',
                'work_auth_status' => 'unknown',
                'source' => 'connecteam',
                'created_by_user_id' => $userId,
            ]);
            mappingUpsert(
                $tenantId, 'connecteam', 'person', $sourceUserId, $personId,
                $sourceUser, 'pull', $userId
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \PDOException && (string) $e->getCode() === '23000') {
                api_error('This person or Connecteam identity was linked by another request. Refresh and try again.', 409);
            }
            api_error('Could not create the CoreFlux person: ' . $e->getMessage(), 500);
        }
        peopleAudit('people.created', [
            'id' => $personId,
            'classification' => $classification,
            'source' => 'connecteam',
        ], $personId);
        if ($classification === 'w2') {
            require_once __DIR__ . '/../modules/people/lib/employees.php';
            try { peopleEnsureEmployeesFromW2(); } catch (\Throwable $_) { /* People dashboard can retry the bridge. */ }
        }
        connecteamAudit($tenantId, 'person_created_and_linked', [
            'actor_user_id' => $userId,
            'items_inspected' => 1,
            'detail' => ['source_user_id' => $sourceUserId, 'person_id' => $personId],
        ]);
        api_ok([
            'ok' => true,
            'person' => connecteamApiPerson($tenantId, $personId),
            'identity' => ['source' => 'connecteam', 'external_id' => $sourceUserId],
        ], 201);
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
