<?php
/** Entity-scoped CoreOne invoice drafts backed by the existing Billing service. */
declare(strict_types=1);

require_once __DIR__ . '/coreone_documents_v1.php';
require_once __DIR__ . '/../memberships.php';
require_once __DIR__ . '/../RBAC.php';
require_once __DIR__ . '/../../modules/billing/lib/invoice_drafts.php';
require_once __DIR__ . '/../../modules/billing/lib/workflow.php';
require_once __DIR__ . '/../../modules/billing/lib/approval_settings.php';

function coreoneV1NormalizeInvoiceDraft(array $credential, array $body): array
{
    $allowed = ['schema_version', 'source_record_id', 'client_name', 'client_company_id', 'issue_date',
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
    if (array_key_exists('client_company_id', $body)
        && (!is_int($body['client_company_id']) || $body['client_company_id'] <= 0)) {
        throw new InvalidArgumentException('client_company_id must be a positive integer.');
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

    $normalized = [
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
    // Keep the old intent hash stable when a caller omits the new dimension.
    if (array_key_exists('client_company_id', $body)) {
        $normalized['client_company_id'] = $body['client_company_id'];
    }
    return $normalized;
}

function coreoneV1GetInvoiceDraft(array $credential, string $sourceId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT d.source_record_id, i.id, i.invoice_number, i.client_name, i.client_company_id, i.entity_id,
                i.currency, i.issue_date, i.due_date, i.status, i.subtotal, i.tax_total,
                i.total, i.amount_paid, i.amount_due, i.journal_entry_id,
                wi.id AS workflow_instance_id, wi.status AS workflow_status
           FROM coreone_document_requests d
           JOIN billing_invoices i ON i.tenant_id = d.tenant_id
            AND i.entity_id = d.entity_id AND i.id = d.target_id
           LEFT JOIN workflow_instances wi ON wi.tenant_id = i.tenant_id
            AND wi.id = (
                SELECT MAX(wi_latest.id) FROM workflow_instances wi_latest
                 WHERE wi_latest.tenant_id = i.tenant_id
                   AND wi_latest.subject_type = "billing_invoice"
                   AND wi_latest.subject_id = i.id
            )
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
    $row['client_company_id'] = $row['client_company_id'] === null ? null : (int) $row['client_company_id'];
    $row['journal_entry_id'] = $row['journal_entry_id'] === null ? null : (int) $row['journal_entry_id'];
    $row['workflow_instance_id'] = $row['workflow_instance_id'] === null ? null : (int) $row['workflow_instance_id'];
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

function coreoneV1NormalizeInvoiceApprovalRequest(array $body): string
{
    if (array_diff(array_keys($body), ['schema_version', 'source_record_id'])
        || ($body['schema_version'] ?? null) !== 1) {
        throw new InvalidArgumentException('Approval request needs schema_version 1 and source_record_id only.');
    }
    $sourceId = $body['source_record_id'] ?? null;
    if (!is_string($sourceId)
        || !preg_match('#^[A-Za-z0-9][A-Za-z0-9:_./-]{0,119}$#D', $sourceId)) {
        throw new InvalidArgumentException('source_record_id must be a stable ID of at most 120 characters.');
    }
    return $sourceId;
}

/** A configured policy must resolve to at least one active human in this workspace. */
function coreoneV1InvoiceHasActiveApprover(int $tenantId, array $requirements): bool
{
    $userIds = [];
    foreach ($requirements as $requirement) {
        foreach (($requirement['approvers'] ?? []) as $actor) {
            $userIds = array_merge($userIds, _workflowPeopleGraphActorToUserIds($tenantId, $actor));
        }
    }
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) return false;

    $eligibleIds = array_column(billingInvoiceEligibleReviewers($tenantId), 'id');
    return array_intersect($userIds, $eligibleIds) !== [];
}

function coreoneV1RequestInvoiceApproval(array $credential, array $body): array
{
    $sourceId = coreoneV1NormalizeInvoiceApprovalRequest($body);
    $tenantId = (int) $credential['tenant_id'];
    $entityId = (int) $credential['entity_id'];
    $pdo = getDB();
    $owns = !$pdo->inTransaction();
    $savepoint = $owns ? null : 'coreone_approval_' . bin2hex(random_bytes(4));
    if ($owns) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT ' . $savepoint);
    try {
        $mapping = $pdo->prepare(
            'SELECT target_id FROM coreone_document_requests
              WHERE tenant_id = :tenant_id AND entity_id = :entity_id
                AND source_type = "billing.invoice" AND source_record_id = :source_id
              FOR UPDATE'
        );
        $mapping->execute(['tenant_id' => $tenantId, 'entity_id' => $entityId,
            'source_id' => $sourceId]);
        $invoiceId = (int) ($mapping->fetchColumn() ?: 0);
        if ($invoiceId <= 0) throw new OutOfBoundsException('Invoice not found for this entity.');

        $row = $pdo->prepare(
            'SELECT * FROM billing_invoices
              WHERE tenant_id = :tenant_id AND entity_id = :entity_id AND id = :id
              FOR UPDATE'
        );
        $row->execute(['tenant_id' => $tenantId, 'entity_id' => $entityId, 'id' => $invoiceId]);
        $invoice = $row->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) throw new OutOfBoundsException('Invoice not found for this entity.');

        $pendingId = billingInvoiceWorkflowPendingInstanceId($tenantId, $invoiceId);
        $status = (string) $invoice['status'];
        if ($status === 'void') {
            throw new CoreOneDocumentConflictException('A void invoice cannot request approval.');
        }
        $replay = true;
        if ($status === 'draft' && $pendingId <= 0) {
            $priorWorkflow = $pdo->prepare(
                'SELECT status FROM workflow_instances
                  WHERE tenant_id = :tenant_id AND subject_type = "billing_invoice"
                    AND subject_id = :invoice_id ORDER BY id DESC LIMIT 1'
            );
            $priorWorkflow->execute(['tenant_id' => $tenantId, 'invoice_id' => $invoiceId]);
            if ($priorWorkflow->fetchColumn() !== false) {
                throw new CoreOneDocumentConflictException(
                    'A previous approval workflow ended; review the invoice in Billing before requesting approval again.'
                );
            }
            $routing = billingInvoiceApprovalRouting($tenantId, $invoice);
            if (empty($routing['infrastructure_available']) || empty($routing['workflow_required'])) {
                throw new CoreOneDocumentConflictException(
                    'Configure a Billing invoice approval policy before requesting machine approval.'
                );
            }
            if (!coreoneV1InvoiceHasActiveApprover($tenantId, (array) $routing['requirements'])) {
                throw new CoreOneDocumentConflictException(
                    'The Billing invoice approval policy has no active human approver in this workspace.'
                );
            }
            $pendingId = (int) (billingInvoiceWorkflowStart($tenantId, $invoiceId, null) ?? 0);
            if ($pendingId <= 0) throw new RuntimeException('Billing invoice approval workflow could not start.');
            billingWorkflowAudit($tenantId, null, 'billing.invoice.approval_requested', [
                'invoice_id' => $invoiceId,
                'workflow_instance_id' => $pendingId,
                'credential_id' => (int) $credential['id'],
                'source_record_id' => $sourceId,
                'source' => 'coreone',
            ], $invoiceId);
            $replay = false;
        }

        $current = coreoneV1GetInvoiceDraft($credential, $sourceId);
        if (!$current) throw new RuntimeException('Invoice disappeared during approval request.');
        if ($owns) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        return ['invoice' => $current, 'approval_requested' => !$replay,
            'idempotent_replay' => $replay];
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$owns && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        throw $e;
    }
}
