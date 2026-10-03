<?php
/** Entity-scoped CoreOne payable intake on the existing AP bill service. */
declare(strict_types=1);

require_once __DIR__ . '/coreone_documents_v1.php';
require_once __DIR__ . '/../../modules/ap/lib/bill_drafts.php';

function coreoneV1NormalizeBill(array $credential, array $body): array
{
    $allowed = ['schema_version', 'source_record_id', 'vendor_name', 'vendor_type',
        'bill_number', 'received_at', 'bill_date', 'due_date', 'currency',
        'tax_rate_pct', 'po_number', 'notes_internal', 'lines'];
    if (array_diff(array_keys($body), $allowed)) {
        throw new InvalidArgumentException('The v1 bill request has unsupported fields.');
    }
    if (($body['schema_version'] ?? null) !== 1) {
        throw new InvalidArgumentException('schema_version must be 1.');
    }
    $sourceId = $body['source_record_id'] ?? null;
    if (!is_string($sourceId)
        || !preg_match('#^[A-Za-z0-9][A-Za-z0-9:_./-]{0,119}$#D', $sourceId)) {
        throw new InvalidArgumentException('source_record_id must be a stable ID of at most 120 characters.');
    }
    $vendorName = $body['vendor_name'] ?? null;
    if (!is_string($vendorName) || trim($vendorName) === '' || strlen(trim($vendorName)) > 255) {
        throw new InvalidArgumentException('vendor_name is required and must be at most 255 characters.');
    }
    $vendorType = $body['vendor_type'] ?? null;
    if (!is_string($vendorType)
        || !in_array($vendorType, ['1099_individual', 'c2c_corp', 'w9_business', 'utility', 'other'], true)) {
        throw new InvalidArgumentException('vendor_type must name a supported AP vendor type.');
    }
    $billNumber = $body['bill_number'] ?? null;
    if (!is_string($billNumber) || trim($billNumber) === '' || strlen(trim($billNumber)) > 80) {
        throw new InvalidArgumentException('bill_number is required and must be at most 80 characters.');
    }
    $dates = [];
    foreach (['received_at', 'bill_date', 'due_date'] as $field) {
        $value = $body[$field] ?? null;
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$field} must be a valid YYYY-MM-DD date.");
        }
        $dates[$field] = $value;
    }
    if ($dates['due_date'] < $dates['bill_date']) {
        throw new InvalidArgumentException('due_date cannot precede bill_date.');
    }
    if (($body['currency'] ?? null) !== (string) $credential['base_currency']) {
        throw new InvalidArgumentException('currency must match the entity base currency.');
    }
    $taxRate = coreoneV1DocumentDecimal4($body['tax_rate_pct'] ?? null, 'tax_rate_pct');
    if ((float) $taxRate > 100) {
        throw new InvalidArgumentException('tax_rate_pct must be between 0 and 100.');
    }
    $poNumber = $body['po_number'] ?? null;
    if ($poNumber !== null && (!is_string($poNumber) || strlen($poNumber) > 80)) {
        throw new InvalidArgumentException('po_number must be at most 80 characters.');
    }
    $notesInternal = $body['notes_internal'] ?? null;
    if ($notesInternal !== null && (!is_string($notesInternal) || strlen($notesInternal) > 2000)) {
        throw new InvalidArgumentException('notes_internal must be at most 2000 characters.');
    }
    $rawLines = $body['lines'] ?? null;
    if (!is_array($rawLines) || !array_is_list($rawLines)
        || count($rawLines) < 1 || count($rawLines) > 100) {
        throw new InvalidArgumentException('lines must contain 1 to 100 bill lines.');
    }
    $accountStmt = null;
    $accountSql =
        'SELECT a.id, a.account_type, a.normal_side, ba.id AS bank_account_id
           FROM accounting_accounts a
           LEFT JOIN accounting_bank_accounts ba
             ON ba.tenant_id = a.tenant_id AND ba.gl_account_code = a.code
          WHERE a.tenant_id = :t AND a.code = :code
            AND a.active = 1 AND a.is_postable = 1 LIMIT 1';
    $lines = [];
    $gross = 0.0;
    foreach ($rawLines as $index => $line) {
        if (!is_array($line) || array_diff(array_keys($line),
            ['item_type', 'description', 'quantity', 'unit', 'unit_price',
             'gl_expense_account_code', 'is_1099_eligible'])) {
            throw new InvalidArgumentException("Line {$index} has unsupported fields.");
        }
        $itemType = $line['item_type'] ?? null;
        if (!is_string($itemType) || !in_array($itemType, AP_LINE_ITEM_TYPES, true)) {
            throw new InvalidArgumentException("Line {$index} needs a supported item_type.");
        }
        $description = $line['description'] ?? null;
        if (!is_string($description) || trim($description) === '' || strlen(trim($description)) > 500) {
            throw new InvalidArgumentException("Line {$index} needs a description of at most 500 characters.");
        }
        $unit = $line['unit'] ?? null;
        if (!is_string($unit) || trim($unit) === '' || strlen(trim($unit)) > 40) {
            throw new InvalidArgumentException("Line {$index} needs a unit of at most 40 characters.");
        }
        if (!is_bool($line['is_1099_eligible'] ?? null)) {
            throw new InvalidArgumentException("Line {$index} needs an is_1099_eligible boolean.");
        }
        $quantity = coreoneV1DocumentDecimal4($line['quantity'] ?? null,
            "lines[{$index}].quantity", true);
        $unitPrice = coreoneV1DocumentDecimal4($line['unit_price'] ?? null,
            "lines[{$index}].unit_price");
        $gross += (float) $quantity * (float) $unitPrice;
        if ($gross > 4999999999) throw new InvalidArgumentException('Bill total exceeds the supported amount.');
        $glCode = $line['gl_expense_account_code'] ?? null;
        if ($glCode !== null) {
            if (!is_string($glCode) || trim($glCode) === '' || strlen(trim($glCode)) > 40
                || in_array(trim($glCode), COREONE_V1_PROTECTED_ACCOUNTS, true)) {
                throw new InvalidArgumentException("Line {$index} has an unavailable GL account.");
            }
            $glCode = trim($glCode);
            $accountStmt ??= getDB()->prepare($accountSql);
            $accountStmt->execute(['t' => (int) $credential['tenant_id'], 'code' => $glCode]);
            $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
            if (!$account || $account['bank_account_id'] !== null
                || !in_array($account['account_type'], ['asset', 'expense'], true)
                || $account['normal_side'] !== 'debit') {
                throw new InvalidArgumentException("Line {$index} has no available postable GL account.");
            }
        }
        $lines[] = [
            'item_type' => $itemType, 'description' => trim($description),
            'quantity' => $quantity, 'unit' => trim($unit), 'unit_price' => $unitPrice,
            'gl_expense_account_code' => $glCode,
            'is_1099_eligible' => $line['is_1099_eligible'],
        ];
    }
    if ($gross <= 0) throw new InvalidArgumentException('Bill total must be greater than zero.');
    return [
        'schema_version' => 1, 'source_record_id' => $sourceId,
        'vendor_name' => trim($vendorName), 'vendor_type' => $vendorType,
        'bill_number' => trim($billNumber),
        'received_at' => $dates['received_at'], 'bill_date' => $dates['bill_date'],
        'due_date' => $dates['due_date'], 'currency' => (string) $credential['base_currency'],
        'tax_rate_pct' => $taxRate, 'po_number' => $poNumber,
        'notes_internal' => $notesInternal, 'lines' => $lines,
    ];
}

function coreoneV1GetBill(array $credential, string $sourceId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT d.source_record_id, b.id, b.internal_ref, b.bill_number, b.vendor_name,
                b.vendor_type, b.entity_id, b.currency, b.received_at, b.bill_date,
                b.due_date, b.status, b.subtotal, b.tax_total, b.total,
                b.amount_paid, b.amount_due, b.journal_entry_id
           FROM coreone_document_requests d
           JOIN ap_bills b ON b.tenant_id = d.tenant_id
            AND b.entity_id = d.entity_id AND b.id = d.target_id
          WHERE d.tenant_id = :t AND d.entity_id = :e
            AND d.source_type = "ap.bill" AND d.source_record_id = :source_id
          LIMIT 1'
    );
    $stmt->execute(['t' => (int) $credential['tenant_id'],
        'e' => (int) $credential['entity_id'], 'source_id' => $sourceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['id'] = (int) $row['id'];
    $row['entity_id'] = (int) $row['entity_id'];
    $row['journal_entry_id'] = $row['journal_entry_id'] === null ? null : (int) $row['journal_entry_id'];
    $lines = getDB()->prepare(
        'SELECT line_no, item_type, description, quantity, unit, unit_price,
                subtotal, tax_rate_pct, tax_amount, total,
                gl_expense_account_code, is_1099_eligible
           FROM ap_bill_lines WHERE bill_id = :bill_id ORDER BY line_no'
    );
    $lines->execute(['bill_id' => $row['id']]);
    $row['lines'] = $lines->fetchAll(PDO::FETCH_ASSOC);
    return $row;
}

function coreoneV1PrepareBill(array $credential, array $body): array
{
    $normalized = coreoneV1NormalizeBill($credential, $body);
    $tenantId = (int) $credential['tenant_id'];
    $entityId = (int) $credential['entity_id'];
    $result = coreoneV1SubmitDocument($credential, 'ap.bill', $normalized['source_record_id'],
        $normalized,
        static function () use ($tenantId, $entityId, $normalized): int {
            $bill = apCreateManualBill($tenantId,
                array_merge($normalized, ['entity_id' => $entityId]), null);
            if ((float) $bill['total'] <= 0 || $bill['status'] !== 'pending_approval') {
                throw new RuntimeException('AP did not prepare a positive bill.');
            }
            return (int) $bill['id'];
        },
        static fn(string $id): ?array => coreoneV1GetBill($credential, $id));
    return ['bill' => $result['record'], 'idempotent_replay' => $result['idempotent_replay']];
}
