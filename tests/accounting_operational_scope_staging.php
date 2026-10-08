<?php
/** Read-only operational-report parity on isolated synthetic staging entities. */
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

function operationalGet(string $path, string $cookie): array
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

function operationalCsvRows(string $body): array
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

$actor = null;
$cookie = null;
$draftId = null;
try {
    $actor = qaEnsureActor($pdo, 'operational-report-reader');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-op-report-');
    if ($cookie === false) throw new RuntimeException('Could not create test session.');
    qaLogin($actor, $cookie);
    $lifecycleId = (int) array_values(array_filter($entities, static fn ($row) =>
        $row['code'] === 'SIM-LIFECYCLE-QA'))[0]['id'];
    $draft = qaRequest('/modules/accounting/api/journal_entries.php?action=draft', 'POST', [
        'entity_id' => $lifecycleId,
        'posting_date' => date('Y-m-d'),
        'currency' => 'USD',
        'memo' => 'Synthetic operational report scope check',
        'source_module' => 'manual',
        'lines' => [
            ['account_code' => '1097', 'debit' => 1, 'credit' => 0],
            ['account_code' => '3000', 'debit' => 0, 'credit' => 1],
        ],
    ], $cookie);
    $draftId = (int) ($draft['je_id'] ?? 0);
    qaExpect($draftId > 0 && $draft['status'] === 'draft',
        'temporary balanced journal stays unposted');

    foreach ($entities as $entity) {
        $id = (int) $entity['id'];
        $scope = '&entity_id=' . $id;
        $glParams = 'type=gl_detail&from=2026-01-01&to=2026-12-31' . $scope;
        $gl = qaRequest('/modules/accounting/api/standard_reports.php?' . $glParams, 'GET', null, $cookie);
        $expectedGl = (int) qaOne($pdo, 'SELECT COUNT(*) AS n
            FROM accounting_journal_entry_lines l
            JOIN accounting_journal_entries je ON je.id = l.je_id
            WHERE je.tenant_id = :t AND je.entity_id = :e
              AND je.status IN ("posted", "reversed")
              AND je.posting_date BETWEEN "2026-01-01" AND "2026-12-31"',
            ['t' => QA_TENANT, 'e' => $id])['n'];
        qaExpect((int) $gl['entity_id'] === $id && (int) $gl['count'] === $expectedGl
            && count(array_filter($gl['rows'], static fn ($row) =>
                (int) $row['entity_id'] !== $id || (int) $row['account_id'] <= 0)) === 0,
            "{$entity['code']} GL detail has only owned, linked account lines");

        [$glStatus, $glCsv] = operationalGet('/modules/accounting/api/export.php?' . $glParams, $cookie);
        $glRows = operationalCsvRows($glCsv);
        qaExpect($glStatus === 200 && count($glRows) === $expectedGl
            && count(array_filter($glRows, static fn ($row) => (int) $row['entity_id'] !== $id)) === 0
            && abs(array_sum(array_map('floatval', array_column($glRows, 'debit')))
                - (float) $gl['total_debit']) < 0.01
            && abs(array_sum(array_map('floatval', array_column($glRows, 'credit')))
                - (float) $gl['total_credit']) < 0.01,
            "{$entity['code']} GL CSV matches on-screen totals and rows");

        $expectedDrafts = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entries
            WHERE tenant_id = :t AND entity_id = :e AND status = "draft"',
            ['t' => QA_TENANT, 'e' => $id])['n'];
        if ($id === $lifecycleId) qaExpect($expectedDrafts > 0, 'lifecycle entity has a draft to inspect');
        foreach (['unposted_jes', 'approval_queue'] as $type) {
            $params = 'type=' . $type . $scope;
            $list = qaRequest('/modules/accounting/api/standard_reports.php?' . $params,
                'GET', null, $cookie);
            [$csvStatus, $csv] = operationalGet('/modules/accounting/api/export.php?' . $params,
                $cookie);
            $rows = operationalCsvRows($csv);
            if ((int) $list['count'] !== $expectedDrafts || count($rows) !== $expectedDrafts
                || $csvStatus !== 200) {
                fwrite(STDERR, json_encode(['entity' => $entity['code'], 'type' => $type,
                    'expected' => $expectedDrafts, 'api' => $list['count'],
                    'csv' => count($rows), 'http' => $csvStatus,
                    'csv_header' => substr($csv, 0, 180)], JSON_THROW_ON_ERROR) . "\n");
            }
            qaExpect((int) $list['entity_id'] === $id && (int) $list['count'] === $expectedDrafts
                && count($rows) === $expectedDrafts && $csvStatus === 200
                && count(array_filter(array_merge($list['rows'], $rows), static fn ($row) =>
                    (int) $row['entity_id'] !== $id)) === 0,
                "{$entity['code']} {$type} API and CSV match its drafts");
        }

        $activityParams = 'type=account_activity&code=4000&from=2026-01-01&to=2026-12-31' . $scope;
        $activity = qaRequest('/modules/accounting/api/standard_reports.php?' . $activityParams,
            'GET', null, $cookie);
        [$activityStatus, $activityCsv] = operationalGet(
            '/modules/accounting/api/export.php?' . $activityParams, $cookie);
        $activityRows = operationalCsvRows($activityCsv);
        qaExpect((int) $activity['entity_id'] === $id && $activityStatus === 200
            && (int) $activity['count'] === count($activityRows)
            && count(array_filter($activityRows, static fn ($row) =>
                (int) $row['entity_id'] !== $id)) === 0
            && count(array_filter($activity['rows'], static fn ($row) =>
                (int) $row['entity_id'] !== $id)) === 0,
            "{$entity['code']} account activity API and CSV share one entity");
    }

    foreach (['invalid', '999999999'] as $invalid) {
        foreach (['standard_reports.php', 'export.php'] as $endpoint) {
            [$status] = operationalGet('/modules/accounting/api/' . $endpoint
                . '?type=gl_detail&entity_id=' . $invalid, $cookie);
            qaExpect($status === 422, "{$endpoint} rejects {$invalid} entity");
        }
    }
    [$invalidApprovalStatus] = operationalGet('/modules/accounting/api/export.php'
        . '?type=unposted_jes&approval_state=approved', $cookie);
    qaExpect($invalidApprovalStatus === 422, 'journal export rejects nonexistent approval-state filter');
} finally {
    $cleanupFailure = null;
    if ($draftId && is_string($cookie)) {
        try {
            qaRequest('/modules/accounting/api/journal_entries.php?action=delete&id=' . $draftId,
                'POST', ['reason' => 'Remove temporary synthetic report-check draft'], $cookie);
            $cleared = qaOne($pdo, 'SELECT status FROM accounting_journal_entries
                WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $draftId]);
            qaExpect($cleared && $cleared['status'] === 'void',
                'temporary journal was voided through the accounting API');
        } catch (\Throwable $cleanupError) {
            $cleanupFailure = $cleanupError;
        }
    }
    if ($actor && isset($actor['id'])) {
        $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
            WHERE id = :id AND tenant_id = :t')
            ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
    }
    if (is_string($cookie) && is_file($cookie)) unlink($cookie);
    if ($cleanupFailure) throw new RuntimeException('Draft cleanup failed', 0, $cleanupFailure);
}
