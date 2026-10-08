<?php
/** Synthetic CSV discount invoice through independent review and canonical GL posting. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}
define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

function qaDiscountInvoiceCsv(string $number, int $catalogItemId): string
{
    $headers = ['invoice_number', 'client_name', 'entity_code', 'issue_date', 'due_date',
        'currency', 'line_description', 'line_catalog_item_id', 'line_item_type',
        'line_gl_revenue_account_code', 'line_quantity', 'line_unit_price',
        'line_subtotal', 'line_tax_amount', 'line_total'];
    $base = [
        'invoice_number' => $number,
        'client_name' => 'Invented Discount Client',
        'entity_code' => QA_ENTITY_CODE,
        'issue_date' => '2026-10-08',
        'due_date' => '2026-11-07',
        'currency' => 'USD',
    ];
    $rows = [
        $base + ['line_description' => 'Invented service',
            'line_catalog_item_id' => (string) $catalogItemId, 'line_quantity' => '2',
            'line_unit_price' => '12.50', 'line_subtotal' => '25.00',
            'line_tax_amount' => '0', 'line_total' => '25.00'],
        ['invoice_number' => $number, 'line_description' => 'Untaxed discount',
            'line_quantity' => '1', 'line_unit_price' => '-2.00',
            'line_subtotal' => '-2.00', 'line_tax_amount' => '0', 'line_total' => '-2.00'],
    ];
    $stream = fopen('php://temp', 'w+');
    if (!$stream) throw new RuntimeException('Could not assemble synthetic CSV');
    try {
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(
                static fn(string $field): string => (string) ($row[$field] ?? ''), $headers
            ));
        }
        rewind($stream);
        return (string) stream_get_contents($stream);
    } finally {
        fclose($stream);
    }
}

function qaDiscountExportRows(string $number, string $cookie): array
{
    $curl = curl_init(QA_BASE_URL . '/modules/billing/api/csv_export.php?q=' . rawurlencode($number));
    if ($curl === false) throw new RuntimeException('Could not start invoice CSV export');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_HTTPHEADER => ['Accept: text/csv', 'X-CoreFlux-Tenant-Id: ' . QA_TENANT],
    ]);
    $csv = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($csv) || $status !== 200) {
        throw new RuntimeException("Invoice CSV export returned HTTP {$status}");
    }
    $stream = fopen('php://temp', 'w+');
    if (!$stream) throw new RuntimeException('Could not parse invoice CSV export');
    try {
        fwrite($stream, $csv);
        rewind($stream);
        $headers = fgetcsv($stream);
        if (!$headers || !in_array('Line item type', $headers, true)) {
            throw new RuntimeException('Invoice CSV export omitted line item type');
        }
        $rows = [];
        while (($values = fgetcsv($stream)) !== false) {
            if (count($values) !== count($headers)) throw new RuntimeException('Malformed invoice CSV export');
            $rows[] = array_combine($headers, $values);
        }
        return $rows;
    } finally {
        fclose($stream);
    }
}

$maker = null;
$reviewer = null;
$cookies = [];
try {
    $maker = qaEnsureActor($pdo, 'billing-csv-post-maker');
    $reviewer = qaEnsureActor($pdo, 'billing-csv-post-reviewer');
    $makerCookie = tempnam(sys_get_temp_dir(), 'cf-csv-post-maker-');
    $reviewerCookie = tempnam(sys_get_temp_dir(), 'cf-csv-post-reviewer-');
    $cookies = array_values(array_filter([$makerCookie, $reviewerCookie], 'is_string'));
    if ($makerCookie === false || $reviewerCookie === false) {
        throw new RuntimeException('Could not create synthetic sessions');
    }
    qaLogin($maker, $makerCookie);
    qaLogin($reviewer, $reviewerCookie);

    $entity = qaOne($pdo, 'SELECT id FROM accounting_entities
        WHERE tenant_id = :t AND code = :code AND active = 1',
        ['t' => QA_TENANT, 'code' => QA_ENTITY_CODE]);
    if (!$entity) throw new RuntimeException('Synthetic invoice entity is unavailable');
    $entityId = (int) $entity['id'];
    $before = qaBalances($pdo, $entityId);
    $run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $number = 'SYN-AR-DISCOUNT-' . $run;
    $createdItem = qaRequest('/modules/billing/api/items.php', 'POST', [
        'code' => 'SYN-SERVICE-' . $run,
        'name' => 'Invented CSV Service',
        'item_type' => 'fixed_fee',
        'default_unit' => 'each',
        'default_unit_price' => 12.5,
        'gl_revenue_account_code' => '4000',
        'taxable' => false,
    ], $makerCookie);
    $catalogItemId = (int) ($createdItem['id'] ?? 0);
    qaExpect($catalogItemId > 0, 'synthetic service catalog item was created');

    $import = qaRequest('/modules/billing/api/csv_import.php?action=commit', 'POST',
        ['csv' => qaDiscountInvoiceCsv($number, $catalogItemId)], $makerCookie);
    $invoiceId = (int) ($import['ids'][$number] ?? 0);
    $draft = qaOne($pdo, 'SELECT status, entity_id, created_by_user_id, subtotal,
            tax_total, total, journal_entry_id FROM billing_invoices
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $invoiceId]);
    qaExpect(($import['imported_count'] ?? 0) === 1 && $invoiceId > 0
        && ($draft['status'] ?? '') === 'draft'
        && (int) ($draft['entity_id'] ?? 0) === $entityId
        && (int) ($draft['created_by_user_id'] ?? 0) === (int) $maker['id']
        && abs((float) ($draft['total'] ?? 0) - 23.0) < 0.005
        && $draft['journal_entry_id'] === null
        && qaBalances($pdo, $entityId) === $before,
        'CSV created one $23 draft without GL movement');
    $types = $pdo->prepare('SELECT line_no, item_type, catalog_item_id, gl_revenue_account_code
        FROM billing_invoice_lines
        WHERE invoice_id = :id ORDER BY line_no');
    $types->execute(['id' => $invoiceId]);
    $stored = array_column($types->fetchAll(PDO::FETCH_ASSOC), null, 'line_no');
    qaExpect(($stored[1]['item_type'] ?? null) === 'fixed_fee'
        && (int) ($stored[1]['catalog_item_id'] ?? 0) === $catalogItemId
        && ($stored[1]['gl_revenue_account_code'] ?? null) === '4000'
        && ($stored[2]['item_type'] ?? null) === 'discount',
        'CSV retains catalog, revenue account and discount classification');
    $exported = qaDiscountExportRows($number, $makerCookie);
    qaExpect(count($exported) === 2
        && ($exported[0]['Line item type'] ?? null) === 'fixed_fee'
        && (int) ($exported[0]['Line catalog item ID'] ?? 0) === $catalogItemId
        && ($exported[0]['Line revenue account'] ?? null) === '4000'
        && ($exported[1]['Line item type'] ?? null) === 'discount',
        'ordinary invoice CSV export preserves catalog, account and type');

    qaRequest('/modules/billing/api/invoices.php?action=request_approval&id=' . $invoiceId,
        'POST', [], $makerCookie);
    qaRequest('/modules/billing/api/approval_assignment.php', 'POST', [
        'invoice_id' => $invoiceId, 'reviewer_user_ids' => [(int) $reviewer['id']],
    ], $makerCookie);
    $approval = qaRequest('/modules/billing/api/invoices.php?action=approve&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    qaExpect(!empty($approval['approved']), 'independent reviewer approved the CSV invoice');

    $posted = qaRequest('/modules/billing/api/invoices.php?action=post&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    $replay = qaRequest('/modules/billing/api/invoices.php?action=post&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    $journalId = (int) ($posted['journal_entry_id'] ?? 0);
    $journal = qaOne($pdo, 'SELECT status, entity_id FROM accounting_journal_entries
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $journalId]);
    $source = qaOne($pdo, 'SELECT journal_entry_id, amount_due FROM billing_invoices
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $invoiceId]);
    $after = qaBalances($pdo, $entityId);
    qaExpect($journalId > 0 && ($journal['status'] ?? '') === 'posted'
        && (int) ($journal['entity_id'] ?? 0) === $entityId
        && (int) ($source['journal_entry_id'] ?? 0) === $journalId
        && !empty($replay['idempotent_replay'])
        && qaDelta($before, $after, '1100', 23.0)
        && qaDelta($before, $after, '4000', -23.0)
        && abs((float) ($source['amount_due'] ?? 0) - 23.0) < 0.005,
        'discount invoice posted once as $23 AR and $23 net revenue');

    $lines = qaOne($pdo, 'SELECT COUNT(*) AS n, SUM(debit) AS dr, SUM(credit) AS cr
        FROM accounting_journal_entry_lines WHERE tenant_id = :t AND je_id = :id',
        ['t' => QA_TENANT, 'id' => $journalId]);
    qaExpect((int) ($lines['n'] ?? 0) >= 2
        && abs((float) ($lines['dr'] ?? 0) - 23.0) < 0.005
        && abs((float) ($lines['cr'] ?? 0) - 23.0) < 0.005,
        'canonical journal is balanced at the net invoice amount');

    qaRequest('/modules/billing/api/items.php?id=' . $catalogItemId,
        'PATCH', ['active' => false], $makerCookie);
    $inactive = qaOne($pdo, 'SELECT active FROM billing_items
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $catalogItemId]);
    qaExpect((int) ($inactive['active'] ?? 1) === 0,
        'synthetic catalog item is inactive after the posted-invoice test');

    echo json_encode(['run' => $run, 'invoice_id' => $invoiceId,
        'journal_entry_id' => $journalId, 'inactive_catalog_item_id' => $catalogItemId],
        JSON_PRETTY_PRINT), "\n";
} finally {
    foreach ([$maker, $reviewer] as $actor) {
        if ($actor && isset($actor['id'])) {
            $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
                WHERE id = :id AND tenant_id = :t')
                ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
        }
    }
    foreach ($cookies as $cookie) {
        if (is_file($cookie)) unlink($cookie);
    }
}
