<?php
/** Entity-scoped CoreOne invoice drafts backed by the existing Billing service. */
declare(strict_types=1);

require_once __DIR__ . '/coreone_documents_v1.php';
require_once __DIR__ . '/../../modules/billing/lib/invoice_drafts.php';

function coreoneV1NormalizeInvoiceDraft(array $credential, array $body): array
{
    $allowed = ['schema_version', 'source_record_id', 'client_name', 'issue_date',
        'due_date', 'currency', 'tax_rate_pct', 'po_number', 'notes_external', 'lines'];
    if (array_diff(array_keys($body), $allowed)) {
        throw new InvalidArgumentException('The v1 invoice draft request has unsupported fields.');
    }
    if (($body['schema_version'] ?? null) !== 1) {
        throw new InvalidArgumentException('schema_version must be 1.');
    }
    $sourceId = $body['source_record_id'] ?? null;
    if (!is_string($sourceId)
        || !preg_match('#^[A-Za-z0-9][A-Za-z0-9:_./-]{0,119}$#D', $sourceId)) {
        throw new InvalidArgumentException('source_record_id must be a stable ID of at most 120 characters.');
    }
    $clientName = $body['client_name'] ?? null;
    if (!is_string($clientName) || trim($clientName) === '' || strlen(trim($clientName)) > 255) {
        throw new InvalidArgumentException('client_name is required and must be at most 255 characters.');
    }
    $dates = [];
    foreach (['issue_date', 'due_date'] as $field) {
        $value = $body[$field] ?? null;
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$field} must be a valid YYYY-MM-DD date.");
        }
        $dates[$field] = $value;
    }
    if ($dates['due_date'] < $dates['issue_date']) {
        throw new InvalidArgumentException('due_date cannot precede issue_date.');
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
    $notesExternal = $body['notes_external'] ?? null;
    if ($notesExternal !== null && (!is_string($notesExternal) || strlen($notesExternal) > 2000)) {
        throw new InvalidArgumentException('notes_external must be at most 2000 characters.');
    }
    $rawLines = $body['lines'] ?? null;
    if (!is_array($rawLines) || !array_is_list($rawLines)
        || count($rawLines) < 1 || count($rawLines) > 100) {
        throw new InvalidArgumentException('lines must contain 1 to 100 invoice lines.');
    }
    $lines = [];
    $gross = 0.0;
    foreach ($rawLines as $index => $line) {
        if (!is_array($line) || array_diff(array_keys($line),
            ['catalog_item_id', 'description', 'quantity', 'unit', 'unit_price', 'taxable'])) {
            throw new InvalidArgumentException("Line {$index} has unsupported fields.");
        }
        $catalogId = $line['catalog_item_id'] ?? null;
        if ($catalogId !== null && (!is_int($catalogId) || $catalogId <= 0)) {
            throw new InvalidArgumentException("Line {$index} has an invalid catalog_item_id.");
        }
        $description = $line['description'] ?? null;
        if (!is_string($description) || trim($description) === '' || strlen(trim($description)) > 500) {
            throw new InvalidArgumentException("Line {$index} needs a description of at most 500 characters.");
        }
        $unit = $line['unit'] ?? null;
        if (!is_string($unit) || trim($unit) === '' || strlen(trim($unit)) > 40) {
            throw new InvalidArgumentException("Line {$index} needs a unit of at most 40 characters.");
        }
        if (!is_bool($line['taxable'] ?? null)) {
            throw new InvalidArgumentException("Line {$index} needs a taxable boolean.");
        }
        $quantity = coreoneV1DocumentDecimal4($line['quantity'] ?? null, "lines[{$index}].quantity", true);
        $unitPrice = coreoneV1DocumentDecimal4($line['unit_price'] ?? null, "lines[{$index}].unit_price");
        $gross += (float) $quantity * (float) $unitPrice;
        if ($gross > 4999999999) {
            throw new InvalidArgumentException('Invoice total exceeds the supported amount.');
        }
        $lines[] = [
            'catalog_item_id' => $catalogId,
            'description' => trim($description),
            'quantity' => $quantity,
            'unit' => trim($unit),
            'unit_price' => $unitPrice,
            'taxable' => $line['taxable'],
        ];
    }
    if ($gross <= 0) throw new InvalidArgumentException('Invoice total must be greater than zero.');

    return [
        'schema_version' => 1,
        'source_record_id' => $sourceId,
        'client_name' => trim($clientName),
        'issue_date' => $dates['issue_date'],
        'due_date' => $dates['due_date'],
        'currency' => (string) $credential['base_currency'],
        'tax_rate_pct' => $taxRate,
        'po_number' => $poNumber,
        'notes_external' => $notesExternal,
        'lines' => $lines,
    ];
}

function coreoneV1GetInvoiceDraft(array $credential, string $sourceId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT d.source_record_id, i.id, i.invoice_number, i.client_name, i.entity_id,
                i.currency, i.issue_date, i.due_date, i.status, i.subtotal, i.tax_total,
                i.total, i.amount_paid, i.amount_due, i.journal_entry_id
           FROM coreone_document_requests d
           JOIN billing_invoices i ON i.tenant_id = d.tenant_id
            AND i.entity_id = d.entity_id AND i.id = d.target_id
          WHERE d.tenant_id = :t AND d.entity_id = :e
            AND d.source_type = "billing.invoice" AND d.source_record_id = :source_id
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
        'SELECT line_no, catalog_item_id, description, quantity, unit, unit_price,
                subtotal, tax_rate_pct, tax_amount, total
           FROM billing_invoice_lines WHERE invoice_id = :invoice_id ORDER BY line_no'
    );
    $lines->execute(['invoice_id' => $row['id']]);
    $row['lines'] = $lines->fetchAll(PDO::FETCH_ASSOC);
    return $row;
}

function coreoneV1CreateInvoiceDraft(array $credential, array $body): array
{
    $normalized = coreoneV1NormalizeInvoiceDraft($credential, $body);
    $sourceId = $normalized['source_record_id'];
    $tenantId = (int) $credential['tenant_id'];
    $entityId = (int) $credential['entity_id'];
    $result = coreoneV1SubmitDocument($credential, 'billing.invoice', $sourceId, $normalized,
        static function () use ($tenantId, $entityId, $normalized): int {
            $draft = billingCreateDirectInvoiceDraft($tenantId,
                array_merge($normalized, ['entity_id' => $entityId]), null);
            if ((float) $draft['total'] <= 0) {
                throw new RuntimeException('Billing did not produce a positive draft invoice.');
            }
            return (int) $draft['id'];
        },
        static fn(string $id): ?array => coreoneV1GetInvoiceDraft($credential, $id));
    return ['invoice' => $result['record'], 'idempotent_replay' => $result['idempotent_replay']];
}
