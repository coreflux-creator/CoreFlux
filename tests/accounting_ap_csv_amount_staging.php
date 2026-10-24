<?php
/** Synthetic AP CSV preview/commit guard on the isolated staging app only. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}
define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

function qaApCsv(array $rows): string
{
    $headers = ['bill_number', 'vendor_name', 'entity_code', 'bill_date', 'due_date',
        'currency', 'line_description', 'line_quantity', 'line_unit_price',
        'line_subtotal', 'line_tax_amount', 'line_total'];
    $stream = fopen('php://temp', 'w+');
    if (!$stream) throw new RuntimeException('Could not assemble synthetic CSV');
    fputcsv($stream, $headers);
    foreach ($rows as $row) {
        fputcsv($stream, array_map(static fn(string $field): string => (string) ($row[$field] ?? ''), $headers));
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);
    if ($csv === false) throw new RuntimeException('Could not read synthetic CSV');
    return $csv;
}

$actor = null;
$cookie = null;
try {
    $actor = qaEnsureActor($pdo, 'ap-csv');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-ap-csv-');
    if ($cookie === false) throw new RuntimeException('Could not create synthetic cookie jar');
    qaLogin($actor, $cookie);

    $run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $number = 'SYN-AP-CSV-' . $run;
    $base = [
        'bill_number' => $number,
        'vendor_name' => 'Invented AP CSV Vendor ' . $run,
        'entity_code' => QA_ENTITY_CODE,
        'bill_date' => '2026-10-08',
        'due_date' => '2026-11-07',
        'currency' => 'USD',
        'line_description' => 'Invented service',
        'line_quantity' => '1',
        'line_unit_price' => '12.50',
        'line_subtotal' => '12.50',
        'line_tax_amount' => '0',
        'line_total' => '12.50',
    ];
    $bad = qaApCsv([$base, array_replace($base, [
        'line_description' => 'Invalid zero line',
        'line_unit_price' => '0', 'line_subtotal' => '0', 'line_total' => '0',
    ])]);
    $before = qaBalances($pdo, (int) qaOne($pdo, 'SELECT id FROM accounting_entities
        WHERE tenant_id = :t AND code = :code',
        ['t' => QA_TENANT, 'code' => QA_ENTITY_CODE])['id']);
    $preview = qaRequest('/modules/ap/api/bills_csv_import.php?action=dry_run',
        'POST', ['csv' => $bad], $cookie);
    qaExpect(($preview['groups'] ?? 0) === 1 && ($preview['error_count'] ?? 0) === 1
        && isset($preview['errors'][3]) && !isset($preview['errors'][2]),
        'AP CSV preview identifies the invalid continuation line within its bill');
    $rejected = qaRequest('/modules/ap/api/bills_csv_import.php?action=commit',
        'POST', ['csv' => $bad], $cookie);
    $skipped = qaRequest('/modules/ap/api/bills_csv_import.php?action=commit&skip_invalid=1',
        'POST', ['csv' => $bad], $cookie);
    $absent = qaOne($pdo, 'SELECT id FROM ap_bills WHERE tenant_id = :t AND bill_number = :number',
        ['t' => QA_TENANT, 'number' => $number]);
    qaExpect(($rejected['imported_count'] ?? -1) === 0
        && ($skipped['imported_count'] ?? -1) === 0 && !$absent,
        'normal and skip-invalid commits refuse the whole bill without partial rows');

    $mismatch = qaApCsv([array_replace($base, [
        'line_subtotal' => '13.50', 'line_total' => '13.50',
    ])]);
    $mismatchPreview = qaRequest('/modules/ap/api/bills_csv_import.php?action=dry_run',
        'POST', ['csv' => $mismatch], $cookie);
    qaExpect(($mismatchPreview['error_count'] ?? 0) === 1
        && str_contains(implode(' ', $mismatchPreview['errors'][2] ?? []), 'subtotal must equal'),
        'preview refuses an amount that disagrees with quantity times price');

    $excessPrecision = qaApCsv([array_replace($base, ['line_unit_price' => '12.50001'])]);
    $precisionPreview = qaRequest('/modules/ap/api/bills_csv_import.php?action=dry_run',
        'POST', ['csv' => $excessPrecision], $cookie);
    qaExpect(($precisionPreview['error_count'] ?? 0) === 1
        && str_contains(implode(' ', $precisionPreview['errors'][2] ?? []), 'four decimals'),
        'preview refuses a price that cannot be stored without rounding');

    $good = qaApCsv([$base]);
    $goodPreview = qaRequest('/modules/ap/api/bills_csv_import.php?action=dry_run',
        'POST', ['csv' => $good], $cookie);
    $committed = qaRequest('/modules/ap/api/bills_csv_import.php?action=commit',
        'POST', ['csv' => $good], $cookie);
    $billId = (int) ($committed['ids'][$number] ?? 0);
    $bill = qaOne($pdo, 'SELECT id, status, entity_id, total, journal_entry_id FROM ap_bills
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $billId]);
    $line = qaOne($pdo, 'SELECT quantity, unit_price, subtotal, total FROM ap_bill_lines
        WHERE bill_id = :id', ['id' => $billId]);
    qaExpect(($goodPreview['error_count'] ?? -1) === 0
        && ($committed['imported_count'] ?? 0) === 1 && $billId > 0
        && ($bill['status'] ?? '') === 'pending_approval'
        && abs((float) ($bill['total'] ?? 0) - 12.50) < 0.005
        && $bill['journal_entry_id'] === null
        && abs((float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0)
            - (float) ($line['subtotal'] ?? 0)) < 0.005
        && qaBalances($pdo, (int) $bill['entity_id']) === $before,
        'valid CSV creates one unposted bill with consistent line amounts');

    qaRequest('/modules/ap/api/bills.php?action=void&id=' . $billId, 'POST',
        ['reason' => 'Synthetic AP CSV acceptance cleanup'], $cookie);
    $void = qaOne($pdo, 'SELECT status FROM ap_bills WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $billId]);
    qaExpect(($void['status'] ?? '') === 'void'
        && qaBalances($pdo, (int) $bill['entity_id']) === $before,
        'invented CSV bill is voided without moving the GL');
    echo json_encode(['run' => $run, 'void_bill_id' => $billId], JSON_PRETTY_PRINT), "\n";
} finally {
    if (is_string($cookie) && file_exists($cookie)) unlink($cookie);
    if ($actor && isset($actor['id'])) {
        $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
            WHERE id = :id AND tenant_id = :t')
            ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
    }
}
