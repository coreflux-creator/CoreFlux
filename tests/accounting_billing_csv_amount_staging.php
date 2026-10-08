<?php
/** Synthetic invoice CSV amount preview/commit acceptance on isolated staging. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}
define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

function qaBillingCsv(array $rows): string
{
    $headers = ['invoice_number', 'client_name', 'client_company_id', 'entity_code',
        'issue_date', 'due_date',
        'currency', 'line_description', 'line_catalog_item_id', 'line_item_type',
        'line_gl_revenue_account_code', 'line_quantity', 'line_unit_price',
        'line_subtotal', 'line_tax_amount', 'line_total'];
    $stream = fopen('php://temp', 'w+');
    if (!$stream) throw new RuntimeException('Could not assemble synthetic invoice CSV');
    fputcsv($stream, $headers);
    foreach ($rows as $row) {
        fputcsv($stream, array_map(static fn(string $field): string => (string) ($row[$field] ?? ''), $headers));
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);
    if ($csv === false) throw new RuntimeException('Could not read synthetic invoice CSV');
    return $csv;
}

$actor = null;
$cookie = null;
try {
    $actor = qaEnsureActor($pdo, 'billing-csv');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-billing-csv-');
    if ($cookie === false) throw new RuntimeException('Could not create synthetic cookie jar');
    qaLogin($actor, $cookie);

    $run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $number = 'SYN-AR-CSV-' . $run;
    $base = [
        'invoice_number' => $number,
        'client_name' => 'Invented CSV Client ' . $run,
        'entity_code' => QA_ENTITY_CODE,
        'issue_date' => '2026-10-08',
        'due_date' => '2026-11-07',
        'currency' => 'USD',
        'line_description' => 'Invented service',
        'line_quantity' => '2',
        'line_unit_price' => '12.50',
        'line_subtotal' => '25.00',
        'line_tax_amount' => '0',
        'line_total' => '25.00',
    ];
    $entity = qaOne($pdo, 'SELECT id FROM accounting_entities
        WHERE tenant_id = :t AND code = :code AND active = 1',
        ['t' => QA_TENANT, 'code' => QA_ENTITY_CODE]);
    if (!$entity) throw new RuntimeException('Synthetic invoice entity is unavailable');
    $entityId = (int) $entity['id'];
    $before = qaBalances($pdo, $entityId);

    $mismatched = qaBillingCsv([$base, array_replace($base, [
        'line_description' => 'Wrong discount math', 'line_quantity' => '1',
        'line_unit_price' => '-2', 'line_subtotal' => '-3', 'line_total' => '-3',
    ])]);
    $preview = qaRequest('/modules/billing/api/csv_import.php?action=dry_run',
        'POST', ['csv' => $mismatched], $cookie);
    qaExpect(($preview['groups'] ?? 0) === 1 && ($preview['error_count'] ?? 0) === 1
        && isset($preview['errors'][3]) && !isset($preview['errors'][2]),
        'invoice preview identifies the mismatched discount line');
    $rejected = qaRequest('/modules/billing/api/csv_import.php?action=commit',
        'POST', ['csv' => $mismatched], $cookie);
    $skipped = qaRequest('/modules/billing/api/csv_import.php?action=commit&skip_invalid=1',
        'POST', ['csv' => $mismatched], $cookie);
    $absent = qaOne($pdo, 'SELECT id FROM billing_invoices
        WHERE tenant_id = :t AND invoice_number = :number',
        ['t' => QA_TENANT, 'number' => $number]);
    qaExpect(($rejected['imported_count'] ?? -1) === 0
        && ($skipped['imported_count'] ?? -1) === 0 && !$absent,
        'normal and skip-invalid commits refuse the whole invoice');

    $discount = array_replace($base, ['line_description' => 'Untaxed discount',
        'line_quantity' => '1', 'line_unit_price' => '-2',
        'line_subtotal' => '-2', 'line_total' => '-2']);
    $discountOnly = qaBillingCsv([array_replace($discount, [
        'invoice_number' => $number . '-NEG',
    ])]);
    $negativePreview = qaRequest('/modules/billing/api/csv_import.php?action=dry_run',
        'POST', ['csv' => $discountOnly], $cookie);
    qaExpect(($negativePreview['error_count'] ?? 0) === 1
        && str_contains(implode(' ', $negativePreview['errors'][2] ?? []), 'Invoice total must be positive'),
        'preview rejects an invoice whose net total is negative');
    $taxedDiscount = qaBillingCsv([$base, array_replace($discount, [
        'line_tax_amount' => '1', 'line_total' => '-1',
    ])]);
    $taxPreview = qaRequest('/modules/billing/api/csv_import.php?action=dry_run',
        'POST', ['csv' => $taxedDiscount], $cookie);
    qaExpect(($taxPreview['error_count'] ?? 0) === 1
        && str_contains(implode(' ', $taxPreview['errors'][3] ?? []), 'discounts cannot carry tax'),
        'preview rejects tax on a negative discount line');
    $wrongType = qaBillingCsv([$base, array_replace($discount, ['line_item_type' => 'labor'])]);
    $wrongTypePreview = qaRequest('/modules/billing/api/csv_import.php?action=dry_run',
        'POST', ['csv' => $wrongType], $cookie);
    qaExpect(($wrongTypePreview['error_count'] ?? 0) === 1
        && str_contains(implode(' ', $wrongTypePreview['errors'][3] ?? []),
            'negative invoice line must use the discount item type'),
        'preview refuses to misclassify a negative line as labor');
    $badCatalog = qaBillingCsv([array_replace($base, ['line_catalog_item_id' => '999999999'])]);
    $catalogPreview = qaRequest('/modules/billing/api/csv_import.php?action=dry_run',
        'POST', ['csv' => $badCatalog], $cookie);
    qaExpect(($catalogPreview['error_count'] ?? 0) === 1
        && str_contains(implode(' ', $catalogPreview['errors'][2] ?? []),
            'catalog item is not active in this workspace'),
        'preview refuses an unavailable catalog item');
    $badAccount = qaBillingCsv([array_replace($base,
        ['line_gl_revenue_account_code' => 'NO-SUCH-REVENUE'])]);
    $accountPreview = qaRequest('/modules/billing/api/csv_import.php?action=dry_run',
        'POST', ['csv' => $badAccount], $cookie);
    qaExpect(($accountPreview['error_count'] ?? 0) === 1
        && str_contains(implode(' ', $accountPreview['errors'][2] ?? []),
            'revenue account is not active and postable'),
        'preview refuses an unavailable revenue account');
    $badClient = qaBillingCsv([array_replace($base, ['client_company_id' => '999999999'])]);
    $clientPreview = qaRequest('/modules/billing/api/csv_import.php?action=dry_run',
        'POST', ['csv' => $badClient], $cookie);
    qaExpect(($clientPreview['error_count'] ?? 0) === 1
        && str_contains(implode(' ', $clientPreview['errors'][2] ?? []),
            'Client company is not available in this workspace'),
        'preview refuses an unavailable client company');
    $subcentCsv = qaBillingCsv([array_replace($base, [
        'line_tax_amount' => '0.001', 'line_total' => '25.00',
    ])]);
    $subcentPreview = qaRequest('/modules/billing/api/csv_import.php?action=dry_run',
        'POST', ['csv' => $subcentCsv], $cookie);
    qaExpect(($subcentPreview['error_count'] ?? 0) === 1
        && str_contains(implode(' ', $subcentPreview['errors'][2] ?? []),
            'line tax amount must use at most two decimals'),
        'preview refuses a tax amount that would silently round away');

    $good = qaBillingCsv([$base, $discount]);
    $goodPreview = qaRequest('/modules/billing/api/csv_import.php?action=dry_run',
        'POST', ['csv' => $good], $cookie);
    $committed = qaRequest('/modules/billing/api/csv_import.php?action=commit',
        'POST', ['csv' => $good], $cookie);
    $invoiceId = (int) ($committed['ids'][$number] ?? 0);
    $invoice = qaOne($pdo, 'SELECT id, status, entity_id, total, journal_entry_id
        FROM billing_invoices WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $invoiceId]);
    $lines = qaOne($pdo, 'SELECT COUNT(*) AS n, SUM(subtotal) AS subtotal,
            SUM(quantity * unit_price) AS extended
        FROM billing_invoice_lines WHERE invoice_id = :id', ['id' => $invoiceId]);
    qaExpect(($goodPreview['error_count'] ?? -1) === 0
        && ($committed['imported_count'] ?? 0) === 1 && $invoiceId > 0
        && ($invoice['status'] ?? '') === 'draft'
        && abs((float) ($invoice['total'] ?? 0) - 23.0) < 0.005
        && $invoice['journal_entry_id'] === null
        && (int) ($lines['n'] ?? 0) === 2
        && abs((float) ($lines['subtotal'] ?? 0) - 23.0) < 0.005
        && abs((float) ($lines['extended'] ?? 0) - 23.0) < 0.005
        && qaBalances($pdo, $entityId) === $before,
        'valid service and discount CSV creates one consistent unposted draft');

    qaRequest('/modules/billing/api/invoices.php?action=void&id=' . $invoiceId,
        'POST', ['reason' => 'Synthetic invoice CSV acceptance cleanup'], $cookie);
    $void = qaOne($pdo, 'SELECT status FROM billing_invoices
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $invoiceId]);
    qaExpect(($void['status'] ?? '') === 'void' && qaBalances($pdo, $entityId) === $before,
        'invented invoice is voided without moving the GL');
    echo json_encode(['run' => $run, 'void_invoice_id' => $invoiceId], JSON_PRETTY_PRINT), "\n";
} finally {
    if (is_string($cookie) && file_exists($cookie)) unlink($cookie);
    if ($actor && isset($actor['id'])) {
        $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
            WHERE id = :id AND tenant_id = :t')
            ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
    }
}
