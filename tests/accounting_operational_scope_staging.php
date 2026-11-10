<?php
/** Guarded operational-report parity on isolated synthetic staging entities. */
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

function operationalPagedRows(string $params, string $cookie, int $pageSize): array
{
    $first = null;
    $rows = [];
    for ($page = 1; $page <= 1000; $page++) {
        $result = qaRequest('/modules/accounting/api/standard_reports.php?'
            . $params . '&page_size=' . $pageSize . '&page=' . $page,
            'GET', null, $cookie);
        if ($first === null) $first = $result;
        if ((int) $result['page'] !== $page || (int) $result['page_size'] !== $pageSize
            || (int) $result['count'] !== (int) $first['count']
            || count($result['rows']) > $pageSize
            || (bool) $result['has_more'] !== ($page * $pageSize < (int) $result['count'])) {
            throw new RuntimeException('Operational report pagination metadata is inconsistent.');
        }
        $rows = array_merge($rows, $result['rows']);
        if (!$result['has_more']) {
            if (count($rows) !== (int) $first['count']) {
                throw new RuntimeException('Operational report pages omitted or duplicated rows.');
            }
            return [$first, $rows, $page];
        }
    }
    throw new RuntimeException('Operational report exceeded the guarded page limit.');
}

$entities = $pdo->query('SELECT id, code FROM accounting_entities WHERE tenant_id = '
    . QA_TENANT . ' AND code IN ("SIM-LIFECYCLE-QA", "SIM-CUTOVER-QA") ORDER BY code')
    ->fetchAll(PDO::FETCH_ASSOC);
if (count($entities) !== 2) throw new RuntimeException('Synthetic staging entities are missing.');

$actor = null;
$cookie = null;
$draftIds = [];
try {
    $actor = qaEnsureActor($pdo, 'operational-report-reader');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-op-report-');
    if ($cookie === false) throw new RuntimeException('Could not create test session.');
    qaLogin($actor, $cookie);
    $lifecycleId = (int) array_values(array_filter($entities, static fn ($row) =>
        $row['code'] === 'SIM-LIFECYCLE-QA'))[0]['id'];
    for ($i = 1; $i <= 3; $i++) {
        $draft = qaRequest('/modules/accounting/api/journal_entries.php?action=draft', 'POST', [
            'entity_id' => $lifecycleId,
            'posting_date' => date('Y-m-d'),
            'currency' => 'USD',
            'memo' => 'Synthetic operational report scope check ' . $i,
            'source_module' => 'manual',
            'lines' => [
                ['account_code' => '1097', 'debit' => 1, 'credit' => 0],
                ['account_code' => '3000', 'debit' => 0, 'credit' => 1],
            ],
        ], $cookie);
        $draftId = (int) ($draft['je_id'] ?? 0);
        if ($draftId > 0) $draftIds[] = $draftId;
        qaExpect($draftId > 0 && $draft['status'] === 'draft',
            'temporary balanced journal stays unposted');
    }

    foreach ($entities as $entity) {
        $id = (int) $entity['id'];
        $scope = '&entity_id=' . $id;
        $glParams = 'type=gl_detail&from=2026-01-01&to=2026-12-31' . $scope;
        [$gl, $visibleGl, $glPages] = operationalPagedRows($glParams, $cookie, 25);
        $expectedGl = (int) qaOne($pdo, 'SELECT COUNT(*) AS n
            FROM accounting_journal_entry_lines l
            JOIN accounting_journal_entries je ON je.id = l.je_id
            WHERE je.tenant_id = :t AND je.entity_id = :e
              AND je.status IN ("posted", "reversed")
              AND je.posting_date BETWEEN "2026-01-01" AND "2026-12-31"',
            ['t' => QA_TENANT, 'e' => $id])['n'];
        qaExpect((int) $gl['entity_id'] === $id && (int) $gl['count'] === $expectedGl
            && count(array_filter($visibleGl, static fn ($row) =>
                (int) $row['entity_id'] !== $id || (int) $row['account_id'] <= 0)) === 0,
            "{$entity['code']} GL detail has {$expectedGl} owned lines across {$glPages} pages");

        [$glStatus, $glCsv] = operationalGet('/modules/accounting/api/export.php?' . $glParams, $cookie);
        $glRows = operationalCsvRows($glCsv);
        $glOrderMatches = count($visibleGl) === count($glRows);
        if ($glOrderMatches) {
            foreach ($glRows as $index => $row) {
                $visible = $visibleGl[$index];
                if ($row['je_number'] !== $visible['je_number']
                    || $row['posting_date'] !== $visible['posting_date']
                    || $row['account_code'] !== $visible['account_code']
                    || abs((float) $row['debit'] - (float) $visible['debit']) >= 0.01
                    || abs((float) $row['credit'] - (float) $visible['credit']) >= 0.01) {
                    $glOrderMatches = false;
                    break;
                }
            }
        }
        qaExpect($glStatus === 200 && count($glRows) === $expectedGl
            && $glOrderMatches
            && count(array_filter($glRows, static fn ($row) => (int) $row['entity_id'] !== $id)) === 0
            && abs(array_sum(array_map('floatval', array_column($glRows, 'debit')))
                - (float) $gl['total_debit']) < 0.01
            && abs(array_sum(array_map('floatval', array_column($glRows, 'credit')))
                - (float) $gl['total_credit']) < 0.01,
            "{$entity['code']} GL CSV matches all paged rows and full-range totals");

        $expectedDrafts = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entries
            WHERE tenant_id = :t AND entity_id = :e AND status = "draft"',
            ['t' => QA_TENANT, 'e' => $id])['n'];
        if ($id === $lifecycleId) qaExpect($expectedDrafts > 0, 'lifecycle entity has a draft to inspect');
        foreach (['unposted_jes', 'approval_queue'] as $type) {
            $params = 'type=' . $type . $scope;
            [$list, $visibleDrafts, $draftPages] = operationalPagedRows($params, $cookie, 2);
            [$csvStatus, $csv] = operationalGet('/modules/accounting/api/export.php?' . $params,
                $cookie);
            $rows = operationalCsvRows($csv);
            $apiIds = array_map('intval', array_column($visibleDrafts, 'id'));
            $csvIds = array_map('intval', array_column($rows, 'id'));
            sort($apiIds);
            sort($csvIds);
            if ((int) $list['count'] !== $expectedDrafts || count($rows) !== $expectedDrafts
                || $csvStatus !== 200) {
                fwrite(STDERR, json_encode(['entity' => $entity['code'], 'type' => $type,
                    'expected' => $expectedDrafts, 'api' => $list['count'],
                    'csv' => count($rows), 'http' => $csvStatus,
                    'csv_header' => substr($csv, 0, 180)], JSON_THROW_ON_ERROR) . "\n");
            }
            qaExpect((int) $list['entity_id'] === $id && (int) $list['count'] === $expectedDrafts
                && count($rows) === $expectedDrafts && $csvStatus === 200 && $apiIds === $csvIds
                && count(array_filter(array_merge($visibleDrafts, $rows), static fn ($row) =>
                    (int) $row['entity_id'] !== $id)) === 0,
                "{$entity['code']} {$type} pages and CSV match {$expectedDrafts} drafts across {$draftPages} pages");
        }

        $activityParams = 'type=account_activity&code=4000&from=2026-01-01&to=2026-12-31' . $scope;
        [$activityStatus, $activityCsv] = operationalGet(
            '/modules/accounting/api/export.php?' . $activityParams, $cookie);
        $activityRows = operationalCsvRows($activityCsv);
        $activity = null;
        $visibleRows = [];
        $activityMatches = true;
        $pageCount = max(1, (int) ceil(count($activityRows) / 2));
        for ($page = 1; $page <= $pageCount; $page++) {
            $pageResult = qaRequest('/modules/accounting/api/standard_reports.php?'
                . $activityParams . '&page_size=2&page=' . $page, 'GET', null, $cookie);
            if ($activity === null) $activity = $pageResult;
            $activityMatches = $activityMatches && (int) $pageResult['count'] === count($activityRows)
                && (int) $pageResult['page'] === $page
                && (int) $pageResult['page_size'] === 2
                && (bool) $pageResult['has_more'] === ($page < $pageCount);
            $visibleRows = array_merge($visibleRows, $pageResult['rows']);
        }
        $activityMatches = $activityMatches && count($visibleRows) === count($activityRows)
            && abs((float) $activity['total_debit']
                - array_sum(array_map('floatval', array_column($activityRows, 'debit')))) < 0.01
            && abs((float) $activity['total_credit']
                - array_sum(array_map('floatval', array_column($activityRows, 'credit')))) < 0.01
            && abs((float) $activity['ending_balance']
                - (float) (end($activityRows)['running_balance'] ?? 0)) < 0.01;
        if ($activityMatches) {
            foreach ($activityRows as $index => $row) {
                $visible = $visibleRows[$index] ?? null;
                if (!$visible || $row['je_number'] !== $visible['je_number']
                    || $row['posting_date'] !== $visible['posting_date']
                    || abs((float) $row['running_balance'] - (float) $visible['running_balance']) >= 0.01) {
                    $activityMatches = false;
                    break;
                }
            }
        }
        qaExpect((int) $activity['entity_id'] === $id && $activityStatus === 200
            && $activityMatches
            && count(array_filter($activityRows, static fn ($row) =>
                (int) $row['entity_id'] !== $id)) === 0
            && count(array_filter($visibleRows, static fn ($row) =>
                (int) $row['entity_id'] !== $id)) === 0,
            "{$entity['code']} account activity pages match complete CSV and balances");
    }

    $auditParams = 'type=audit_log&event_like=je.';
    [$auditStatus, $auditCsv] = operationalGet(
        '/modules/accounting/api/export.php?' . $auditParams, $cookie);
    $auditCsvRows = operationalCsvRows($auditCsv);
    [$audit, $auditRows, $auditPages] = operationalPagedRows($auditParams, $cookie, 100);
    qaExpect($auditStatus === 200 && count($auditCsvRows) === (int) $audit['count']
        && array_map('intval', array_column($auditRows, 'id'))
            === array_map('intval', array_column($auditCsvRows, 'id')),
        "accounting audit pages match the complete CSV across {$auditPages} pages");

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
    foreach (['account_activity&code=4000&entity_id=' . $lifecycleId,
        'gl_detail&entity_id=' . $lifecycleId,
        'unposted_jes&entity_id=' . $lifecycleId,
        'approval_queue&entity_id=' . $lifecycleId,
        'audit_log'] as $reportParams) {
        foreach (['page=0', 'page=invalid', 'page_size=201'] as $invalidPage) {
            [$status] = operationalGet('/modules/accounting/api/standard_reports.php?'
                . 'type=' . $reportParams . '&' . $invalidPage, $cookie);
            qaExpect($status === 422, "{$reportParams} rejects {$invalidPage}");
        }
    }
} finally {
    $cleanupFailure = null;
    if ($draftIds && is_string($cookie)) {
        foreach ($draftIds as $draftId) {
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
    }
    if ($actor && isset($actor['id'])) {
        $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
            WHERE id = :id AND tenant_id = :t')
            ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
    }
    if (is_string($cookie) && is_file($cookie)) unlink($cookie);
    if ($cleanupFailure) throw new RuntimeException('Draft cleanup failed', 0, $cleanupFailure);
}
