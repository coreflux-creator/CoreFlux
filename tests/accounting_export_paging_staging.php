<?php
/** Guarded hosted accounting export completeness check; synthetic rows are removed. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';
require_once __DIR__ . '/../core/export_service.php';
require_once __DIR__ . '/../core/export_paging.php';

if (!defined('COREFLUX_STAGING') || !COREFLUX_STAGING) {
    throw new RuntimeException('Refusing a non-staging environment.');
}

function pagingDownload(string $path, string $cookie): array
{
    $curl = curl_init(QA_BASE_URL . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 90,
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

function pagingCsv(string $body): array
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $body);
    rewind($stream);
    $headers = fgetcsv($stream, 0, ',', '"', '');
    if (!is_array($headers)) throw new RuntimeException('Export had no CSV header.');
    $rows = [];
    while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        if (count($values) !== count($headers)) throw new RuntimeException('Export had a malformed CSV row.');
        $rows[] = array_combine($headers, $values);
    }
    fclose($stream);
    return $rows;
}

$entity = qaOne($pdo, 'SELECT id FROM accounting_entities
    WHERE tenant_id = :t AND code = "SIM-LIFECYCLE-QA" AND active = 1', ['t' => QA_TENANT]);
if (!$entity) throw new RuntimeException('Synthetic lifecycle entity is unavailable.');
$entityId = (int) $entity['id'];
$bank = qaOne($pdo, 'SELECT id FROM accounting_bank_accounts
    WHERE tenant_id = :t AND entity_id = :e AND id = 9', ['t' => QA_TENANT, 'e' => $entityId]);
if (!$bank) throw new RuntimeException('Synthetic lifecycle bank account is unavailable.');
$bankId = (int) $bank['id'];
$reference = 'QA-EXPORT-' . bin2hex(random_bytes(12));
$actor = null;
$cookie = null;
$templateId = null;
$insertedIds = [];
$insertCommitted = false;

try {
    $actor = qaEnsureActor($pdo, 'paging-reader');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-paged-export-');
    if ($cookie === false) throw new RuntimeException('Could not create test session.');
    qaLogin($actor, $cookie);

    $templateId = exportTemplateCreate(QA_TENANT, [
        'dataset' => 'accounting_bank_statement_lines',
        'name' => 'Synthetic paging QA',
        'column_mappings' => [
            ['output_header' => 'Statement ID', 'source_field' => 'bank_statement_line_id'],
            ['output_header' => 'FITID', 'source_field' => 'fitid'],
            ['output_header' => 'Entity', 'source_field' => 'entity_id'],
        ],
    ], (int) $actor['id'], 'tenant_admin');

    $insert = $pdo->prepare('INSERT INTO accounting_bank_statement_lines
        (tenant_id, bank_account_id, posted_date, description, amount, bank_reference, fitid, match_status)
        VALUES (:t, :b, "2026-10-08", "Synthetic export paging QA", 0.01, :r, :f, "unmatched")');
    $pdo->beginTransaction();
    try {
        for ($i = 1; $i <= 1001; $i++) {
            $insert->execute(['t' => QA_TENANT, 'b' => $bankId, 'r' => $reference,
                'f' => $reference . '-' . $i]);
            $insertedIds[] = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
        $insertCommitted = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $base = '/modules/accounting/api/export.php?type=bank_statements&bank_account_id='
        . $bankId . '&entity_id=' . $entityId . '&from=2026-10-08&to=2026-10-08';
    [$status, $body] = pagingDownload($base, $cookie);
    qaExpect($status === 200, 'raw bank statement download succeeds: ' . substr($body, 0, 120));
    $raw = array_values(array_filter(pagingCsv($body),
        static fn (array $row): bool => str_starts_with((string) ($row['fitid'] ?? ''), $reference . '-')));
    $rawIds = array_map('intval', array_column($raw, 'id'));
    qaExpect(count($rawIds) === 1001 && count(array_unique($rawIds)) === 1001
        && $rawIds === array_reverse($insertedIds)
        && count(array_filter($raw, static fn (array $row): bool => (int) $row['entity_id'] !== $entityId)) === 0,
        'raw CSV includes every synthetic line once across the 1,000-row boundary');

    [$status, $body] = pagingDownload($base . '&template_id=' . $templateId, $cookie);
    qaExpect($status === 200, 'mapped bank statement download succeeds: ' . substr($body, 0, 120));
    $mapped = array_values(array_filter(pagingCsv($body),
        static fn (array $row): bool => str_starts_with((string) ($row['FITID'] ?? ''), $reference . '-')));
    qaExpect(count($mapped) === 1001
        && array_map('intval', array_column($mapped, 'Statement ID')) === $rawIds
        && count(array_filter($mapped, static fn (array $row): bool => (int) $row['Entity'] !== $entityId)) === 0,
        'mapped CSV has the same complete bank-line IDs and entity as raw CSV');

    $datasets = [
        'accounting_chart_of_accounts' => ['account_id', []],
        'accounting_journal_entries' => ['journal_entry_id', ['entity_id' => $entityId]],
        'accounting_gl_detail' => ['line_id', ['entity_id' => $entityId]],
        'accounting_periods' => ['period_id', ['entity_id' => $entityId]],
        'accounting_bank_statement_lines' => ['bank_statement_line_id',
            ['entity_id' => $entityId, 'bank_account_id' => $bankId]],
    ];
    foreach ($datasets as $dataset => [$key, $filters]) {
        $expected = exportDatasetFetchRows(QA_TENANT, $dataset,
            array_merge($filters, ['limit' => 10000]));
        $paged = iterator_to_array(exportPagedRows(
            static fn (int $size, int $offset): array => exportDatasetFetchRows(QA_TENANT,
                $dataset, array_merge($filters, ['limit' => $size, 'offset' => $offset])), 2), false);
        qaExpect(count($expected) >= 2 && array_column($paged, $key) === array_column($expected, $key),
            "{$dataset} pages reproduce the stable source order");
    }

    [$status, $body] = pagingDownload('/modules/accounting/api/export.php?type=audit_log', $cookie);
    qaExpect($status === 200 && count(pagingCsv($body)) > 0,
        'accounting audit log still downloads through its paged route');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    try {
        if ($insertCommitted && $insertedIds) {
            $read = $pdo->prepare('SELECT id FROM accounting_bank_statement_lines
                WHERE tenant_id = :t AND bank_account_id = :b AND bank_reference = :r ORDER BY id');
            $read->execute(['t' => QA_TENANT, 'b' => $bankId, 'r' => $reference]);
            $found = array_map('intval', $read->fetchAll(PDO::FETCH_COLUMN));
            if ($found !== $insertedIds) {
                throw new RuntimeException('Synthetic bank-line ownership changed; manual review required before cleanup.');
            }
            foreach (array_chunk($insertedIds, 200) as $ids) {
                $delete = $pdo->prepare('DELETE FROM accounting_bank_statement_lines
                    WHERE tenant_id = :t AND bank_account_id = :b AND bank_reference = :r
                      AND id IN (' . implode(',', array_map('intval', $ids)) . ')');
                $delete->execute(['t' => QA_TENANT, 'b' => $bankId, 'r' => $reference]);
                if ($delete->rowCount() !== count($ids)) {
                    throw new RuntimeException('Synthetic bank-line cleanup was incomplete.');
                }
            }
            qaExpect(true, 'all 1,001 synthetic bank lines were removed');
        }
    } finally {
        try {
            if ($templateId !== null && $actor) {
                exportTemplateDelete($templateId, (int) $actor['id'], QA_TENANT, 'tenant_admin');
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
}
