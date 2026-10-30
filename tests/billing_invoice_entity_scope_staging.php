<?php
/** Hosted invoice entity-scope acceptance; reads synthetic staging records only. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';
require_once __DIR__ . '/../core/export_datasets.php';

if (!defined('COREFLUX_STAGING') || !COREFLUX_STAGING) {
    throw new RuntimeException('Refusing a non-staging environment.');
}

function invoiceScopeGet(string $path, string $cookie): array
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

function invoiceScopeCsvIds(string $body): array
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $body);
    rewind($stream);
    $headers = fgetcsv($stream);
    if (!is_array($headers)) throw new RuntimeException('CSV export has no header.');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    $column = array_search('Invoice ID', $headers, true);
    if ($column === false) throw new RuntimeException('CSV export has no Invoice ID column.');
    $ids = [];
    while (($row = fgetcsv($stream)) !== false) {
        if (isset($row[$column]) && ctype_digit((string) $row[$column])) $ids[] = (int) $row[$column];
    }
    fclose($stream);
    return array_values(array_unique($ids));
}

$entityRows = $pdo->query('SELECT id, code FROM accounting_entities WHERE tenant_id = '
    . QA_TENANT . ' AND code IN ("SIM-LIFECYCLE-QA", "SIM-CUTOVER-QA") ORDER BY code')
    ->fetchAll(PDO::FETCH_ASSOC);
if (count($entityRows) !== 2) throw new RuntimeException('Synthetic staging entities are missing.');

$actor = null;
$cookie = null;
$sawSyntheticInvoice = false;
try {
    $actor = qaEnsureActor($pdo, 'invoice-scope-reader');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-inv-scope-');
    if ($cookie === false) throw new RuntimeException('Could not create test session.');
    qaLogin($actor, $cookie);

    $all = qaRequest('/modules/billing/api/invoices.php?per_page=200&entity_id=all', 'GET', null, $cookie);
    $expectedAll = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM billing_invoices WHERE tenant_id = :t',
        ['t' => QA_TENANT])['n'];
    qaExpect((int) $all['total'] === $expectedAll && array_key_exists('entity_id', $all)
        && $all['entity_id'] === null, 'All entities includes every invoice and reports unscoped mode');

    foreach ($entityRows as $entity) {
        $entityId = (int) $entity['id'];
        $expected = $pdo->prepare('SELECT id FROM billing_invoices
            WHERE tenant_id = :t AND entity_id = :e ORDER BY id');
        $expected->execute(['t' => QA_TENANT, 'e' => $entityId]);
        $expectedIds = array_map('intval', $expected->fetchAll(PDO::FETCH_COLUMN));
        $sawSyntheticInvoice = $sawSyntheticInvoice || count($expectedIds) > 0;

        $list = qaRequest('/modules/billing/api/invoices.php?per_page=200&entity_id=' . $entityId,
            'GET', null, $cookie);
        $listIds = array_map('intval', array_column($list['rows'], 'id'));
        sort($listIds);
        qaExpect((int) $list['entity_id'] === $entityId
            && (int) $list['total'] === count($expectedIds)
            && $listIds === $expectedIds
            && count(array_filter($list['rows'], static fn ($row) =>
                (int) $row['entity_id'] !== $entityId || $row['entity_code'] !== $entity['code'])) === 0,
            "{$entity['code']} invoice list is scoped and labeled correctly");

        [$status, $csv] = invoiceScopeGet('/modules/billing/api/csv_export.php?entity_id=' . $entityId,
            $cookie);
        $csvIds = invoiceScopeCsvIds($csv);
        sort($csvIds);
        qaExpect($status === 200 && $csvIds === $expectedIds,
            "{$entity['code']} raw CSV contains exactly its invoices");

        $templatedIds = array_map('intval', array_column(
            exportDatasetFetchBillingInvoices(QA_TENANT, ['entity_id' => $entityId]), 'invoice_id'));
        sort($templatedIds);
        qaExpect($templatedIds === $expectedIds,
            "{$entity['code']} template dataset fetch is scoped");
    }
    qaExpect($sawSyntheticInvoice, 'at least one synthetic legal entity has invoices');

    $searchEntity = (int) qaOne($pdo, 'SELECT id FROM accounting_entities
        WHERE tenant_id = :t AND code = "SIM-LIFECYCLE-QA"', ['t' => QA_TENANT])['id'];
    $searchInvoice = qaOne($pdo, 'SELECT id, invoice_number, client_name FROM billing_invoices
        WHERE tenant_id = :t AND entity_id = :e ORDER BY id DESC LIMIT 1',
        ['t' => QA_TENANT, 'e' => $searchEntity]);
    if (!$searchInvoice) throw new RuntimeException('Synthetic search invoice is missing.');
    foreach (['invoice number' => $searchInvoice['invoice_number'],
        'client' => $searchInvoice['client_name']] as $label => $term) {
        $params = 'entity_id=' . $searchEntity . '&q=' . rawurlencode((string) $term);
        $found = qaRequest('/modules/billing/api/invoices.php?' . $params, 'GET', null, $cookie);
        [$csvStatus, $csv] = invoiceScopeGet('/modules/billing/api/csv_export.php?' . $params,
            $cookie);
        $templateIds = array_map('intval', array_column(exportDatasetFetchBillingInvoices(
            QA_TENANT, ['entity_id' => $searchEntity, 'q' => $term]), 'invoice_id'));
        qaExpect((int) $found['total'] === 1
            && (int) $found['rows'][0]['id'] === (int) $searchInvoice['id']
            && $csvStatus === 200
            && invoiceScopeCsvIds($csv) === [(int) $searchInvoice['id']]
            && $templateIds === [(int) $searchInvoice['id']],
            "{$label} search matches list, raw CSV and template dataset");
    }

    foreach (['invalid' => 422, '999999999' => 404] as $scope => $expectedStatus) {
        foreach (['/modules/billing/api/invoices.php', '/modules/billing/api/csv_export.php'] as $path) {
            [$status] = invoiceScopeGet($path . '?entity_id=' . $scope, $cookie);
            qaExpect($status === $expectedStatus, "{$path} rejects {$scope} scope");
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
