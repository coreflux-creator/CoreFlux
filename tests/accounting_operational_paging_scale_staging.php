<?php
/** Guarded, temporary large-ledger pagination check on the isolated staging tenant. */
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

function scaleGet(string $path, string $cookie): array
{
    $curl = curl_init(QA_BASE_URL . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_HTTPHEADER => ['X-CoreFlux-Tenant-Id: ' . QA_TENANT],
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false) throw new RuntimeException("GET {$path}: {$error}");
    if ($status !== 200) throw new RuntimeException("GET {$path}: HTTP {$status} " . substr($body, 0, 300));
    return [(string) $body, (int) $status];
}

function scaleCsv(string $body): array
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $body);
    rewind($stream);
    $headers = fgetcsv($stream, 0, ',', '"', '');
    if (!is_array($headers)) throw new RuntimeException('Missing CSV header.');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    $rows = [];
    while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        if (count($values) !== count($headers)) throw new RuntimeException('Malformed CSV row.');
        $rows[] = array_combine($headers, $values);
    }
    fclose($stream);
    return $rows;
}

function scalePages(string $params, string $cookie): array
{
    $all = [];
    $first = null;
    $started = microtime(true);
    for ($page = 1; $page <= 100; $page++) {
        [$body] = scaleGet('/modules/accounting/api/standard_reports.php?'
            . $params . '&page_size=200&page=' . $page, $cookie);
        $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if ($first === null) $first = $result;
        if ((int) ($result['page'] ?? 0) !== $page
            || (int) ($result['page_size'] ?? 0) !== 200
            || (int) ($result['count'] ?? -1) !== (int) $first['count']
            || !is_array($result['rows'] ?? null) || count($result['rows']) > 200
            || (bool) ($result['has_more'] ?? false) !== ($page * 200 < (int) $first['count'])) {
            throw new RuntimeException('Large report pagination metadata changed between pages.');
        }
        $all = array_merge($all, $result['rows']);
        if (!$result['has_more']) {
            if (count($all) !== (int) $first['count']) {
                throw new RuntimeException('Large report pages omitted or duplicated lines.');
            }
            return [$first, $all, $page, round((microtime(true) - $started) * 1000)];
        }
    }
    throw new RuntimeException('Large report exceeded 100 guarded pages.');
}

$entity = qaOne($pdo, 'SELECT id FROM accounting_entities
    WHERE tenant_id = :t AND code = :c AND active = 1',
    ['t' => QA_TENANT, 'c' => QA_ENTITY_CODE]);
if (!$entity) throw new RuntimeException('Synthetic lifecycle entity is unavailable.');
$entityId = (int) $entity['id'];
$postingDate = date('Y-m-d');
$period = qaOne($pdo, 'SELECT id FROM accounting_periods
    WHERE tenant_id = :t AND entity_id = :e AND start_date <= :d1 AND end_date >= :d2
      AND status IN ("open", "reopened") LIMIT 1',
    ['t' => QA_TENANT, 'e' => $entityId, 'd1' => $postingDate, 'd2' => $postingDate]);
if (!$period) throw new RuntimeException('No open synthetic accounting period for today.');
$accounts = $pdo->prepare('SELECT code, id FROM accounting_accounts
    WHERE tenant_id = :t AND code IN ("1097", "3000") AND active = 1 AND is_postable = 1');
$accounts->execute(['t' => QA_TENANT]);
$accountIds = array_map('intval', array_column($accounts->fetchAll(PDO::FETCH_ASSOC), 'id', 'code'));
if (!isset($accountIds['1097'], $accountIds['3000'])) {
    throw new RuntimeException('Synthetic test accounts are unavailable.');
}

$baselineGl = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entry_lines l
    JOIN accounting_journal_entries je ON je.id = l.je_id AND je.tenant_id = l.tenant_id
    WHERE je.tenant_id = :t AND je.entity_id = :e AND je.posting_date = :d
      AND je.status IN ("posted", "reversed")',
    ['t' => QA_TENANT, 'e' => $entityId, 'd' => $postingDate])['n'];
$baselineActivity = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entry_lines l
    JOIN accounting_journal_entries je ON je.id = l.je_id AND je.tenant_id = l.tenant_id
    WHERE je.tenant_id = :t AND je.entity_id = :e AND je.posting_date = :d
      AND je.status IN ("posted", "reversed") AND l.account_id = :a',
    ['t' => QA_TENANT, 'e' => $entityId, 'd' => $postingDate, 'a' => $accountIds['1097']])['n'];

$marker = 'QA-PAGING-' . bin2hex(random_bytes(10));
$actor = null;
$cookie = null;
$journalId = null;
$insertCommitted = false;
try {
    $actor = qaEnsureActor($pdo, 'large-report-reader');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-large-report-');
    if ($cookie === false) throw new RuntimeException('Could not create a test session.');
    qaLogin($actor, $cookie);

    $pdo->beginTransaction();
    try {
        $header = $pdo->prepare('INSERT INTO accounting_journal_entries
            (tenant_id, entity_id, period_id, je_number, posting_date, source_module,
             status, currency, total_debit, total_credit, memo, posted_at,
             posted_by_user_id, created_by_user_id)
            VALUES (:t, :e, :p, :n, :d, "manual", "posted", "USD", 20.01, 20.01,
                "Synthetic staging pagination fixture", NOW(), :u1, :u2)');
        $header->execute(['t' => QA_TENANT, 'e' => $entityId, 'p' => (int) $period['id'],
            'n' => $marker, 'd' => $postingDate, 'u1' => (int) $actor['id'],
            'u2' => (int) $actor['id']]);
        $journalId = (int) $pdo->lastInsertId();
        $line = $pdo->prepare('INSERT INTO accounting_journal_entry_lines
            (tenant_id, je_id, line_no, account_id, debit, credit, memo)
            VALUES (:t, :j, :n, :a, :d, :c, "Synthetic staging pagination fixture")');
        for ($i = 1; $i <= 2001; $i++) {
            $line->execute(['t' => QA_TENANT, 'j' => $journalId, 'n' => $i,
                'a' => $accountIds['1097'], 'd' => '0.01', 'c' => '0.00']);
        }
        $line->execute(['t' => QA_TENANT, 'j' => $journalId, 'n' => 2002,
            'a' => $accountIds['3000'], 'd' => '0.00', 'c' => '20.01']);
        $pdo->commit();
        $insertCommitted = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    qaExpect($journalId > 0, 'balanced 2,002-line synthetic journal is temporary');

    $filters = '&entity_id=' . $entityId . '&from=' . $postingDate . '&to=' . $postingDate;
    $glParams = 'type=gl_detail' . $filters;
    [$gl, $glRows, $glPages, $glMs] = scalePages($glParams, $cookie);
    [$glBody] = scaleGet('/modules/accounting/api/export.php?' . $glParams, $cookie);
    $glCsv = scaleCsv($glBody);
    $glMatches = count($glRows) === count($glCsv);
    if ($glMatches) {
        foreach ($glRows as $index => $row) {
            $csv = $glCsv[$index];
            if ($row['je_number'] !== $csv['je_number']
                || $row['account_code'] !== $csv['account_code']
                || abs((float) $row['debit'] - (float) $csv['debit']) >= 0.01
                || abs((float) $row['credit'] - (float) $csv['credit']) >= 0.01) {
                $glMatches = false;
                break;
            }
        }
    }
    qaExpect((int) $gl['count'] === $baselineGl + 2002
        && count(array_filter($glRows, static fn ($row) => $row['je_number'] === $marker)) === 2002
        && $glMatches && count($glCsv) === (int) $gl['count']
        && abs((float) $gl['total_debit']
            - array_sum(array_map('floatval', array_column($glCsv, 'debit')))) < 0.01
        && abs((float) $gl['total_credit']
            - array_sum(array_map('floatval', array_column($glCsv, 'credit')))) < 0.01,
        "all 2,002 GL fixture lines match complete CSV across {$glPages} pages ({$glMs} ms)");

    $activityParams = 'type=account_activity&code=1097' . $filters;
    [$activity, $activityRows, $activityPages, $activityMs] = scalePages($activityParams, $cookie);
    [$activityBody] = scaleGet('/modules/accounting/api/export.php?' . $activityParams, $cookie);
    $activityCsv = scaleCsv($activityBody);
    $activityMatches = count($activityRows) === count($activityCsv);
    if ($activityMatches) {
        foreach ($activityRows as $index => $row) {
            $csv = $activityCsv[$index];
            if ($row['je_number'] !== $csv['je_number']
                || abs((float) $row['running_balance'] - (float) $csv['running_balance']) >= 0.01) {
                $activityMatches = false;
                break;
            }
        }
    }
    qaExpect((int) $activity['count'] === $baselineActivity + 2001
        && count(array_filter($activityRows, static fn ($row) => $row['je_number'] === $marker)) === 2001
        && $activityMatches && count($activityCsv) === (int) $activity['count']
        && abs((float) $activity['ending_balance']
            - (float) (end($activityCsv)['running_balance'] ?? 0)) < 0.01,
        "all 2,001 account lines and balances match CSV across {$activityPages} pages ({$activityMs} ms)");
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    try {
        if ($insertCommitted && $journalId !== null) {
            $pdo->beginTransaction();
            try {
                $owned = qaOne($pdo, 'SELECT id FROM accounting_journal_entries
                    WHERE id = :j AND tenant_id = :t AND entity_id = :e AND je_number = :n
                      AND status = "posted" AND total_debit = 20.01 AND total_credit = 20.01',
                    ['j' => $journalId, 't' => QA_TENANT, 'e' => $entityId, 'n' => $marker]);
                $lines = qaOne($pdo, 'SELECT COUNT(*) AS n,
                        SUM(CASE WHEN account_id = :a AND debit = 0.01 AND credit = 0 THEN 1 ELSE 0 END) AS debits,
                        SUM(CASE WHEN account_id = :b AND debit = 0 AND credit = 20.01 THEN 1 ELSE 0 END) AS credits
                    FROM accounting_journal_entry_lines WHERE tenant_id = :t AND je_id = :j',
                    ['a' => $accountIds['1097'], 'b' => $accountIds['3000'],
                        't' => QA_TENANT, 'j' => $journalId]);
                if (!$owned || (int) $lines['n'] !== 2002 || (int) $lines['debits'] !== 2001
                    || (int) $lines['credits'] !== 1) {
                    throw new RuntimeException('Synthetic journal changed; manual cleanup review required.');
                }
                $deleteLines = $pdo->prepare('DELETE FROM accounting_journal_entry_lines
                    WHERE tenant_id = :t AND je_id = :j');
                $deleteLines->execute(['t' => QA_TENANT, 'j' => $journalId]);
                $deleteHeader = $pdo->prepare('DELETE FROM accounting_journal_entries
                    WHERE id = :j AND tenant_id = :t AND entity_id = :e AND je_number = :n');
                $deleteHeader->execute(['j' => $journalId, 't' => QA_TENANT,
                    'e' => $entityId, 'n' => $marker]);
                if ($deleteLines->rowCount() !== 2002 || $deleteHeader->rowCount() !== 1) {
                    throw new RuntimeException('Synthetic journal cleanup was incomplete.');
                }
                $pdo->commit();
                qaExpect(true, 'exact synthetic journal header and 2,002 lines were removed');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
    } finally {
        if ($actor && isset($actor['id'])) {
            $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
                WHERE id = :id AND tenant_id = :t')
                ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
        }
        if (is_string($cookie) && is_file($cookie)) unlink($cookie);
    }
}
