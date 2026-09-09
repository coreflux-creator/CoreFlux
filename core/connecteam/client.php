<?php
/**
 * Connecteam read-only integration client.
 *
 * API keys are tenant supplied and encrypted with CoreFlux's field
 * encryption. This foundation probes capabilities and keeps compact
 * inventory summaries; it does not mutate Connecteam workforce data.
 */
declare(strict_types=1);

require_once __DIR__ . '/../encryption.php';
require_once __DIR__ . '/../db.php';

const CONNECTEAM_API_US = 'https://api.connecteam.com';
const CONNECTEAM_API_AU = 'https://api-au.connecteam.com';

final class ConnecteamApiException extends \RuntimeException
{
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus = 0)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

function connecteamBaseUrl(string $region): string
{
    return strtolower($region) === 'au' ? CONNECTEAM_API_AU : CONNECTEAM_API_US;
}

function connecteamConnection(int $tenantId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT id, tenant_id, api_key_ct, api_key_last4, region,
                account_id, account_name, status, capability_snapshot,
                inventory_snapshot, last_probe_at, last_probe_error,
                connected_by_user_id, created_at, updated_at
           FROM connecteam_connections
          WHERE tenant_id = :t
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function connecteamApiKey(int $tenantId): string
{
    $row = connecteamConnection($tenantId);
    if (!$row || $row['status'] === 'revoked') {
        throw new \RuntimeException('Connecteam is not connected for this tenant');
    }
    $key = decryptField((string) $row['api_key_ct']);
    if (!is_string($key) || $key === '') {
        throw new \RuntimeException('Connecteam API key could not be decrypted');
    }
    return $key;
}

/**
 * Capabilities are explicit business contracts rather than generic payload
 * maps. This prevents a Connecteam job from being mistaken for a placement.
 */
function connecteamCapabilityDefinitions(): array
{
    return [
        'users' => [
            'label' => 'Users', 'group' => 'People',
            'path' => '/users/v1/users?limit=500&userStatus=all',
            'collections' => ['users'], 'direction' => 'CoreFlux to Connecteam; lifecycle back',
            'destination' => 'People identities',
        ],
        'user_custom_fields' => [
            'label' => 'User custom fields', 'group' => 'People',
            'path' => '/users/v1/custom-fields?limit=500',
            'collections' => ['customFields'], 'direction' => 'Connecteam to CoreFlux',
            'destination' => 'Identity keys and worker attributes',
        ],
        'jobs' => [
            'label' => 'Jobs and sub-jobs', 'group' => 'Work',
            'path' => '/jobs/v1/jobs?limit=500&includeDeleted=false',
            'collections' => ['jobs'], 'direction' => 'Connecteam context; CoreFlux routes',
            'destination' => 'Work categories used by time routing',
        ],
        'schedulers' => [
            'label' => 'Schedules and shifts', 'group' => 'Work',
            'path' => '/scheduler/v1/schedulers',
            'collections' => ['schedulers'], 'direction' => 'Both directions',
            'destination' => 'Planned placement time',
        ],
        'time_clocks' => [
            'label' => 'Time clocks and timesheets', 'group' => 'Time and payroll',
            'path' => '/time-clock/v1/time-clocks',
            'collections' => ['timeClocks'], 'direction' => 'Connecteam to CoreFlux',
            'destination' => 'Actual time and approval state',
        ],
        'time_off' => [
            'label' => 'Time off', 'group' => 'Time and payroll',
            'path' => '/time-off/v1/policy-types',
            'collections' => ['policyTypes'], 'direction' => 'Both directions',
            'destination' => 'Leave and nonbillable payroll time',
        ],
        'pay_rules' => [
            'label' => 'Pay-rule policies', 'group' => 'Time and payroll',
            'path' => '/company-policies/v1/pay-rule-policies',
            'collections' => ['payRulesPolicies', 'payRulePolicies'],
            'direction' => 'Connecteam to CoreFlux',
            'destination' => 'Regular, overtime, and premium categories',
        ],
        'working_hours' => [
            'label' => 'Working-hours policies', 'group' => 'Time and payroll',
            'path' => '/company-policies/v1/working-hours-policies',
            'collections' => ['workingHoursPolicies'], 'direction' => 'Both directions',
            'destination' => 'Expected employee availability',
        ],
        'scheduling_rules' => [
            'label' => 'Scheduling rules', 'group' => 'Time and payroll',
            'path' => '/company-policies/v1/scheduling-rule-policies',
            'collections' => ['schedulingRulePolicies'], 'direction' => 'Both directions',
            'destination' => 'Hours, shifts, and rest constraints',
        ],
        'forms' => [
            'label' => 'Forms', 'group' => 'Operations',
            'path' => '/forms/v1/forms?limit=300',
            'collections' => ['forms'], 'direction' => 'Connecteam to CoreFlux',
            'destination' => 'Expenses, mileage, compliance, and documents',
        ],
        'quick_tasks' => [
            'label' => 'Quick tasks', 'group' => 'Operations',
            'path' => '/tasks/v1/taskboards',
            'collections' => ['taskBoards'], 'direction' => 'Both directions',
            'destination' => 'Onboarding and compliance work',
        ],
        'webhooks' => [
            'label' => 'Webhooks', 'group' => 'Operations',
            'path' => '/settings/v1/webhooks',
            'collections' => ['webhooks'], 'direction' => 'Connecteam to CoreFlux',
            'destination' => 'Realtime event delivery',
        ],
    ];
}

/** @return array{status:int,body:mixed,headers:array<string,string>} */
function connecteamRawRequest(string $method, string $url, ?string $rawBody, array $headers): array
{
    if (isset($GLOBALS['__connecteam_transport']) && is_callable($GLOBALS['__connecteam_transport'])) {
        return ($GLOBALS['__connecteam_transport'])($method, $url, $headers, $rawBody);
    }

    $responseHeaders = [];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        },
    ];
    if ($rawBody !== null) $opts[CURLOPT_POSTFIELDS] = $rawBody;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        throw new ConnecteamApiException('Connecteam network error: ' . $error, 0);
    }
    $decoded = ($raw === '' || $raw === false) ? null : json_decode((string) $raw, true);
    return ['status' => $status, 'body' => $decoded ?? $raw, 'headers' => $responseHeaders];
}

/** @return array{status:int,body:mixed,headers:array<string,string>} */
function connecteamRequestUsingKey(
    string $apiKey,
    string $region,
    string $method,
    string $path,
    ?array $body = null
): array {
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-API-KEY: ' . $apiKey,
    ];
    $rawBody = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);
    $response = connecteamRawRequest($method, connecteamBaseUrl($region) . $path, $rawBody, $headers);
    if ($response['status'] === 429) {
        usleep(1100 * 1000);
        $response = connecteamRawRequest($method, connecteamBaseUrl($region) . $path, $rawBody, $headers);
    }
    return $response;
}

function connecteamErrorMessage(array $response): string
{
    $body = $response['body'] ?? null;
    if (is_array($body)) {
        $message = $body['detail'] ?? $body['error'] ?? ($body['details']['errorMessage'] ?? null);
        if (is_string($message) && $message !== '') return $message;
        return substr((string) json_encode($body, JSON_UNESCAPED_SLASHES), 0, 400);
    }
    return substr(trim((string) $body), 0, 400);
}

function connecteamCall(int $tenantId, string $method, string $path, ?array $body = null): array
{
    $row = connecteamConnection($tenantId);
    if (!$row) throw new \RuntimeException('Connecteam is not connected');
    $response = connecteamRequestUsingKey(connecteamApiKey($tenantId), (string) $row['region'], $method, $path, $body);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new ConnecteamApiException(
            'Connecteam returned HTTP ' . $response['status'] . ': ' . connecteamErrorMessage($response),
            (int) $response['status']
        );
    }
    return is_array($response['body']) ? $response['body'] : ['_raw' => $response['body']];
}

function connecteamPayloadData(array $body): array
{
    return is_array($body['data'] ?? null) ? $body['data'] : $body;
}

function connecteamCollection(array $body, array $keys): array
{
    $data = connecteamPayloadData($body);
    foreach ($keys as $key) {
        if (is_array($data[$key] ?? null)) return array_values($data[$key]);
    }
    return [];
}

function connecteamSampleItem(string $capability, array $item): array
{
    switch ($capability) {
        case 'users':
            return [
                'id' => $item['userId'] ?? $item['id'] ?? null,
                'name' => trim((string) ($item['fullName'] ?? (($item['firstName'] ?? '') . ' ' . ($item['lastName'] ?? '')))),
                'email' => $item['email'] ?? $item['emailAddress'] ?? null,
                'status' => $item['userStatus'] ?? ((bool) ($item['isArchived'] ?? false) ? 'archived' : 'active'),
            ];
        case 'jobs':
            return [
                'id' => $item['jobId'] ?? $item['id'] ?? null,
                'name' => $item['title'] ?? $item['name'] ?? null,
                'code' => $item['code'] ?? null,
            ];
        case 'schedulers':
            return ['id' => $item['schedulerId'] ?? $item['id'] ?? null, 'name' => $item['name'] ?? null];
        case 'time_clocks':
            return ['id' => $item['id'] ?? $item['timeClockId'] ?? null, 'name' => $item['name'] ?? null];
        case 'forms':
            return ['id' => $item['formId'] ?? $item['id'] ?? null, 'name' => $item['formName'] ?? $item['name'] ?? null];
        case 'quick_tasks':
            return ['id' => $item['id'] ?? null, 'name' => $item['name'] ?? null];
        default:
            return [
                'id' => $item['id'] ?? $item['policyId'] ?? null,
                'name' => $item['name'] ?? $item['title'] ?? null,
            ];
    }
}

function connecteamCapabilityInventory(string $key, array $definition, array $body): array
{
    $items = connecteamCollection($body, $definition['collections']);
    $paging = is_array($body['paging'] ?? null)
        ? $body['paging']
        : (is_array(($body['data']['paging'] ?? null)) ? $body['data']['paging'] : []);
    $total = isset($paging['total']) && is_numeric($paging['total']) ? (int) $paging['total'] : null;
    $hasMore = isset($paging['offset']) && is_numeric($paging['offset']) && (int) $paging['offset'] >= count($items) && count($items) > 0;
    return [
        'visible_count' => count($items),
        'reported_total' => $total,
        'has_more' => $hasMore,
        'sample' => array_map(
            static fn(array $item): array => connecteamSampleItem($key, $item),
            array_slice(array_values(array_filter($items, 'is_array')), 0, 5)
        ),
    ];
}

function connecteamAccountSummary(array $body): array
{
    $data = connecteamPayloadData($body);
    $company = is_array($data['company'] ?? null) ? $data['company'] : [];
    return [
        'id' => (string) ($company['id'] ?? $data['companyId'] ?? $data['id'] ?? ''),
        'name' => (string) ($company['name'] ?? $data['companyName'] ?? $data['name'] ?? ''),
    ];
}

/**
 * Probe every feature separately. A 403 is a useful result (valid key, hub or
 * permission unavailable), not a failed connection.
 */
function connecteamProbeWithKey(string $apiKey, string $region): array
{
    $who = connecteamRequestUsingKey($apiKey, $region, 'GET', '/me');
    if ($who['status'] < 200 || $who['status'] >= 300) {
        throw new ConnecteamApiException(
            'Connecteam rejected the API key: ' . connecteamErrorMessage($who),
            (int) $who['status']
        );
    }

    $capabilities = [];
    $inventory = [];
    $available = 0;
    $restricted = 0;
    $errors = 0;

    foreach (connecteamCapabilityDefinitions() as $key => $definition) {
        try {
            $response = connecteamRequestUsingKey($apiKey, $region, 'GET', $definition['path']);
            $status = (int) $response['status'];
            $state = 'error';
            $detail = null;
            if ($status >= 200 && $status < 300) {
                $state = 'available';
                $available++;
                $inventory[$key] = connecteamCapabilityInventory($key, $definition, (array) $response['body']);
            } elseif ($status === 401) {
                $state = 'not_authorized';
                $restricted++;
                $detail = connecteamErrorMessage($response);
            } elseif ($status === 403 || $status === 404) {
                $state = 'not_in_plan';
                $restricted++;
                $detail = connecteamErrorMessage($response);
            } elseif ($status === 429) {
                $state = 'rate_limited';
                $errors++;
                $detail = 'Connecteam rate limit reached; retry the probe later.';
            } else {
                $errors++;
                $detail = connecteamErrorMessage($response);
            }
        } catch (\Throwable $e) {
            $status = 0;
            $state = 'error';
            $detail = $e->getMessage();
            $errors++;
        }
        $capabilities[$key] = [
            'key' => $key,
            'label' => $definition['label'],
            'group' => $definition['group'],
            'state' => $state,
            'http_status' => $status,
            'direction' => $definition['direction'],
            'destination' => $definition['destination'],
            'detail' => $detail,
        ];
    }

    return [
        'read_only' => true,
        'probed_at' => gmdate('c'),
        'account' => connecteamAccountSummary((array) $who['body']),
        'summary' => [
            'available' => $available,
            'restricted' => $restricted,
            'errors' => $errors,
            'total' => count($capabilities),
        ],
        'capabilities' => $capabilities,
        'inventory' => $inventory,
    ];
}

function connecteamSaveApiKey(int $tenantId, string $apiKey, string $region, ?int $userId): array
{
    $apiKey = trim($apiKey);
    $region = strtolower(trim($region));
    if (strlen($apiKey) < 12) throw new \InvalidArgumentException('API key looks incomplete');
    if (!in_array($region, ['us', 'au'], true)) throw new \InvalidArgumentException('Region must be US or Australia');

    // Validate before persistence so a typo never replaces a working key.
    $probe = connecteamProbeWithKey($apiKey, $region);
    $account = $probe['account'];
    $capJson = json_encode($probe, JSON_UNESCAPED_SLASHES);
    $inventoryJson = json_encode($probe['inventory'], JSON_UNESCAPED_SLASHES);
    $pdo = getDB();
    $existing = connecteamConnection($tenantId);
    $params = [
        't' => $tenantId,
        'key' => encryptField($apiKey),
        'last4' => substr($apiKey, -4),
        'region' => $region,
        'account_id' => $account['id'] !== '' ? $account['id'] : null,
        'account_name' => $account['name'] !== '' ? $account['name'] : null,
        'capabilities' => $capJson,
        'inventory' => $inventoryJson,
        'uid' => $userId,
    ];
    if ($existing) {
        $params['id'] = (int) $existing['id'];
        // tenant-leak-allow: id was loaded through tenant-scoped lookup above.
        $pdo->prepare(
            'UPDATE connecteam_connections
                SET api_key_ct = :key, api_key_last4 = :last4, region = :region,
                    account_id = :account_id, account_name = :account_name,
                    status = "active", capability_snapshot = :capabilities,
                    inventory_snapshot = :inventory, last_probe_at = NOW(),
                    last_probe_error = NULL, connected_by_user_id = :uid
              WHERE id = :id AND tenant_id = :t'
        )->execute($params);
    } else {
        $pdo->prepare(
            'INSERT INTO connecteam_connections
                (tenant_id, api_key_ct, api_key_last4, region, account_id,
                 account_name, status, capability_snapshot, inventory_snapshot,
                 last_probe_at, connected_by_user_id)
             VALUES
                (:t, :key, :last4, :region, :account_id, :account_name,
                 "active", :capabilities, :inventory, NOW(), :uid)'
        )->execute($params);
    }
    connecteamAudit($tenantId, 'connect', [
        'actor_user_id' => $userId,
        'items_inspected' => $probe['summary']['total'],
        'detail' => ['region' => $region, 'summary' => $probe['summary'], 'account' => $account],
    ]);
    return $probe;
}

function connecteamProbe(int $tenantId, ?int $userId): array
{
    $row = connecteamConnection($tenantId);
    if (!$row || $row['status'] === 'revoked') throw new \RuntimeException('Connecteam is not connected');
    try {
        $probe = connecteamProbeWithKey(connecteamApiKey($tenantId), (string) $row['region']);
        getDB()->prepare(
            'UPDATE connecteam_connections
                SET status = "active", account_id = :aid, account_name = :aname,
                    capability_snapshot = :caps, inventory_snapshot = :inventory,
                    last_probe_at = NOW(), last_probe_error = NULL
              WHERE tenant_id = :t'
        )->execute([
            'aid' => $probe['account']['id'] ?: null,
            'aname' => $probe['account']['name'] ?: null,
            'caps' => json_encode($probe, JSON_UNESCAPED_SLASHES),
            'inventory' => json_encode($probe['inventory'], JSON_UNESCAPED_SLASHES),
            't' => $tenantId,
        ]);
        connecteamAudit($tenantId, 'probe', [
            'actor_user_id' => $userId,
            'items_inspected' => $probe['summary']['total'],
            'detail' => ['summary' => $probe['summary']],
        ]);
        return $probe;
    } catch (\Throwable $e) {
        getDB()->prepare(
            'UPDATE connecteam_connections
                SET status = "error", last_probe_at = NOW(), last_probe_error = :e
              WHERE tenant_id = :t'
        )->execute(['e' => substr($e->getMessage(), 0, 500), 't' => $tenantId]);
        connecteamAudit($tenantId, 'probe', [
            'ok' => false, 'actor_user_id' => $userId,
            'detail' => ['error' => substr($e->getMessage(), 0, 500)],
        ]);
        throw $e;
    }
}

function connecteamDisconnect(int $tenantId, ?int $userId): void
{
    getDB()->prepare(
        'UPDATE connecteam_connections
            SET status = "revoked", api_key_ct = "", capability_snapshot = NULL,
                inventory_snapshot = NULL
          WHERE tenant_id = :t'
    )->execute(['t' => $tenantId]);
    connecteamAudit($tenantId, 'disconnect', ['actor_user_id' => $userId]);
}

function connecteamAudit(int $tenantId, string $action, array $options = []): void
{
    try {
        getDB()->prepare(
            'INSERT INTO connecteam_sync_audit
                (tenant_id, action, ok, items_inspected, detail, actor_user_id)
             VALUES (:t, :action, :ok, :items, :detail, :uid)'
        )->execute([
            't' => $tenantId,
            'action' => substr($action, 0, 60),
            'ok' => array_key_exists('ok', $options) ? ((bool) $options['ok'] ? 1 : 0) : 1,
            'items' => max(0, (int) ($options['items_inspected'] ?? 0)),
            'detail' => isset($options['detail']) ? json_encode($options['detail'], JSON_UNESCAPED_SLASHES) : null,
            'uid' => $options['actor_user_id'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log('[connecteam.audit] ' . $e->getMessage());
    }
}
