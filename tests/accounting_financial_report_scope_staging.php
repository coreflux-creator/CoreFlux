<?php
/** Guarded hosted checks for financial statement and consolidation entity scope. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

if (!defined('COREFLUX_STAGING') || !COREFLUX_STAGING) {
    throw new RuntimeException('Refusing a non-staging environment.');
}

function financialScopeGet(string $query, string $cookie): array
{
    $curl = curl_init(QA_BASE_URL . '/modules/accounting/api/reports.php?' . $query);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_HTTPHEADER => ['X-CoreFlux-Tenant-Id: ' . QA_TENANT],
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false) throw new RuntimeException("GET {$query}: {$error}");
    return [$status, json_decode((string) $body, true), (string) $body];
}

$entities = $pdo->query('SELECT id, code FROM accounting_entities WHERE tenant_id = '
    . QA_TENANT . ' AND code IN ("SIM-LIFECYCLE-QA", "SIM-CUTOVER-QA") ORDER BY code')
    ->fetchAll(PDO::FETCH_ASSOC);
if (count($entities) !== 2) throw new RuntimeException('Synthetic staging entities are missing.');
$entityIds = array_column($entities, 'id', 'code');
$paths = [
    'income_statement' => 'type=income_statement&from=2026-01-01&to=2026-12-31',
    'balance_sheet' => 'type=balance_sheet&as_of=2026-12-31',
    'trial_balance' => 'type=trial_balance&as_of=2026-12-31',
    'cash_flow_indirect' => 'type=cash_flow_indirect&from=2026-01-01&to=2026-12-31',
];

$actor = null;
$cookie = null;
try {
    $actor = qaEnsureActor($pdo, 'financial-scope-reader');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-financial-scope-');
    if ($cookie === false) throw new RuntimeException('Could not create test session.');
    qaLogin($actor, $cookie);

    foreach (['invalid', 'all', '', '7', '999999999'] as $invalid) {
        foreach ($paths as $type => $query) {
            [$status] = financialScopeGet($query . '&entity_id=' . $invalid, $cookie);
            qaExpect($status === 422, "{$type} refuses entity_id=" . ($invalid ?: '(empty)'));
        }
    }

    $revenues = [];
    foreach ($entities as $entity) {
        $id = (int) $entity['id'];
        foreach ($paths as $type => $query) {
            [$status, $body, $raw] = financialScopeGet($query . '&entity_id=' . $id, $cookie);
            $reportedEntity = $body['period']['entity_id'] ?? $body['entity_id'] ?? null;
            qaExpect($status === 200 && is_array($body) && !isset($body['data_warning'])
                && (int) $reportedEntity === $id,
                "{$entity['code']} {$type} reports only its selected legal entity: " . substr($raw, 0, 80));
            if ($type === 'income_statement') $revenues[$id] = (float) ($body['total_revenue'] ?? 0);
        }
    }
    qaExpect(count(array_unique($revenues)) === 2,
        'two synthetic entity income statements remain distinct');

    $list = implode(',', array_map('intval', array_values($entityIds)));
    foreach (['income_statement', 'balance_sheet', 'trial_balance'] as $type) {
        [$status, $body, $raw] = financialScopeGet($paths[$type] . '&consolidate=1&entity_ids=' . $list, $cookie);
        $reported = $body['period']['entity_ids'] ?? $body['entities'] ?? [];
        qaExpect($status === 200 && is_array($body) && !isset($body['data_warning'])
            && array_map('intval', $reported) === array_map('intval', array_values($entityIds)),
            "{$type} consolidation names only validated entities: " . substr($raw, 0, 80));
    }

    [$status, $body] = financialScopeGet($paths['trial_balance'] . '&consolidate=1&root_entity_id='
        . $entityIds['SIM-LIFECYCLE-QA'], $cookie);
    qaExpect($status === 200 && !isset($body['data_warning'])
        && in_array((int) $entityIds['SIM-LIFECYCLE-QA'], array_map('intval', $body['entities'] ?? []), true),
        'tenant-owned consolidation root resolves');

    foreach ([
        'entity_ids=' . $entityIds['SIM-LIFECYCLE-QA'] . ',7',
        'entity_ids=' . $entityIds['SIM-LIFECYCLE-QA'] . ',all',
        'entity_ids=' . $entityIds['SIM-LIFECYCLE-QA'] . ',',
        'entity_ids=',
        'root_entity_id=7',
        'root_entity_id=',
        'entity_ids=' . $list . '&root_entity_id=' . $entityIds['SIM-LIFECYCLE-QA'],
        'entity_ids=' . $list . '&entity_id=' . $entityIds['SIM-LIFECYCLE-QA'],
    ] as $invalidScope) {
        [$status] = financialScopeGet($paths['trial_balance'] . '&consolidate=1&' . $invalidScope, $cookie);
        qaExpect($status === 422, "consolidation refuses {$invalidScope}");
    }
    [$status] = financialScopeGet($paths['cash_flow_indirect'] . '&consolidate=1&entity_ids=' . $list, $cookie);
    qaExpect($status === 422, 'unsupported cash-flow consolidation fails instead of ignoring scope');
} finally {
    if ($actor && isset($actor['id'])) {
        $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
            WHERE id = :id AND tenant_id = :t')
            ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
    }
    if (is_string($cookie) && is_file($cookie)) unlink($cookie);
}
