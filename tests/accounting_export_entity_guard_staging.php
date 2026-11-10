<?php
/** Guarded hosted checks for explicit accounting CSV legal-entity selection. */
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

function exportGuardGet(string $path, string $cookie): array
{
    $curl = curl_init(QA_BASE_URL . $path);
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
    if ($body === false) throw new RuntimeException("GET {$path}: {$error}");
    return [$status, (string) $body];
}

function exportGuardRows(string $body): array
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $body);
    rewind($stream);
    $headers = fgetcsv($stream);
    if (!is_array($headers)) throw new RuntimeException('CSV export has no header.');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    $rows = [];
    while (($row = fgetcsv($stream)) !== false) {
        if (count($row) === count($headers)) $rows[] = array_combine($headers, $row);
    }
    fclose($stream);
    return $rows;
}

$entities = $pdo->query('SELECT id, code FROM accounting_entities WHERE tenant_id = '
    . QA_TENANT . ' AND code IN ("SIM-LIFECYCLE-QA", "SIM-CUTOVER-QA") ORDER BY code')
    ->fetchAll(PDO::FETCH_ASSOC);
if (count($entities) !== 2) throw new RuntimeException('Synthetic staging entities are missing.');
$entityIds = array_column($entities, 'id', 'code');
$bankId = (int) qaOne($pdo, 'SELECT id FROM accounting_bank_accounts
    WHERE tenant_id = :t AND entity_id = :e ORDER BY id DESC LIMIT 1',
    ['t' => QA_TENANT, 'e' => $entityIds['SIM-LIFECYCLE-QA']])['id'];
if ($bankId <= 0) throw new RuntimeException('Synthetic lifecycle bank account is missing.');

$actor = null;
$cookie = null;
try {
    $actor = qaEnsureActor($pdo, 'export-entity-reader');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-export-guard-');
    if ($cookie === false) throw new RuntimeException('Could not create test session.');
    qaLogin($actor, $cookie);

    $paths = [
        'tb' => 'type=tb&as_of=2026-12-31',
        'je' => 'type=je',
        'je_lines' => 'type=je_lines',
        'periods' => 'type=periods',
        'bank_statements' => 'type=bank_statements&bank_account_id=' . $bankId,
        'gl_detail' => 'type=gl_detail',
        'unposted_jes' => 'type=unposted_jes',
        'approval_queue' => 'type=approval_queue',
        'account_activity' => 'type=account_activity&code=4000',
    ];
    foreach (['invalid', 'all', '', '7', '999999999'] as $invalid) {
        foreach ($paths as $type => $query) {
            [$status] = exportGuardGet('/modules/accounting/api/export.php?'
                . $query . '&entity_id=' . $invalid, $cookie);
            qaExpect($status === 422, "{$type} refuses entity_id=" . ($invalid ?: '(empty)'));
        }
    }
    foreach (['gl_detail', 'unposted_jes', 'approval_queue', 'account_activity'] as $type) {
        [$status] = exportGuardGet('/modules/accounting/api/export.php?' . $paths[$type], $cookie);
        qaExpect($status === 422, "{$type} requires an entity selection");
    }

    foreach ($entities as $entity) {
        $id = (int) $entity['id'];
        foreach (['je', 'je_lines', 'periods', 'gl_detail', 'account_activity'] as $type) {
            [$status, $body] = exportGuardGet('/modules/accounting/api/export.php?'
                . $paths[$type] . '&entity_id=' . $id, $cookie);
            $rows = exportGuardRows($body);
            qaExpect($status === 200 && count(array_filter($rows,
                static fn ($row) => (int) ($row['entity_id'] ?? 0) !== $id)) === 0
                && ($type !== 'je' || count($rows) > 0),
                "{$entity['code']} {$type} export contains only its own rows");
        }
        [$status, $body] = exportGuardGet('/modules/accounting/api/export.php?'
            . $paths['tb'] . '&entity_id=' . $id, $cookie);
        $rows = exportGuardRows($body);
        $debits = array_sum(array_map('floatval', array_column($rows, 'debit')));
        $credits = array_sum(array_map('floatval', array_column($rows, 'credit')));
        qaExpect($status === 200 && count($rows) > 0 && abs($debits - $credits) < 0.01,
            "{$entity['code']} trial balance export balances");
    }

    foreach (['coa', 'audit_log'] as $type) {
        [$status] = exportGuardGet('/modules/accounting/api/export.php?type=' . $type
            . '&entity_id=' . $entityIds['SIM-LIFECYCLE-QA'], $cookie);
        qaExpect($status === 422, "{$type} refuses a misleading entity filter");
        [$status] = exportGuardGet('/modules/accounting/api/export.php?type=' . $type, $cookie);
        qaExpect($status === 200, "{$type} remains available workspace-wide");
    }

    [$status, $body] = exportGuardGet('/modules/accounting/api/export.php?'
        . $paths['bank_statements'] . '&entity_id=' . $entityIds['SIM-LIFECYCLE-QA'], $cookie);
    $rows = exportGuardRows($body);
    qaExpect($status === 200 && count($rows) > 0
        && count(array_filter($rows, static fn ($row) =>
            (int) ($row['entity_id'] ?? 0) !== (int) $entityIds['SIM-LIFECYCLE-QA'])) === 0,
        'bank statement export labels and scopes the owning entity');

    [$status, $body] = exportGuardGet('/modules/accounting/api/export.php?'
        . $paths['bank_statements'] . '&entity_id=' . $entityIds['SIM-CUTOVER-QA'], $cookie);
    qaExpect($status === 200 && exportGuardRows($body) === [],
        'another entity cannot download the lifecycle bank lines');
} finally {
    if ($actor && isset($actor['id'])) {
        $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
            WHERE id = :id AND tenant_id = :t')
            ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
    }
    if (is_string($cookie) && is_file($cookie)) unlink($cookie);
}
