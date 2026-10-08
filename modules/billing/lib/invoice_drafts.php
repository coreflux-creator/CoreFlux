<?php
/** Shared direct-invoice draft service for the ERP and CoreOne. */
declare(strict_types=1);

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/../../ap/lib/ap.php';
require_once __DIR__ . '/../../staffing/lib/clients.php';
require_once __DIR__ . '/../../people/lib/companies.php';
require_once __DIR__ . '/../../../core/active_entity.php';

function billingPrepareDirectInvoiceLines(PDO $pdo, int $tenantId, array $lines, bool $activeOnly = true): array
{
    if (!$lines) throw new InvalidArgumentException('lines must be a non-empty array');
    if (count($lines) > 500) throw new InvalidArgumentException('Invoices are limited to 500 lines');
    foreach ($lines as $line) {
        if (!is_array($line)) throw new InvalidArgumentException('Each invoice line must be an object');
    }

    $catalogIds = array_values(array_unique(array_filter(
        array_map(static fn(array $line): int => (int) ($line['catalog_item_id'] ?? 0), $lines),
        static fn(int $id): bool => $id > 0
    )));
    $catalogById = [];
    if ($catalogIds) {
        $marks = implode(',', array_fill(0, count($catalogIds), '?'));
        $activeSql = $activeOnly ? ' AND active = 1' : '';
        $stmt = $pdo->prepare(
            "SELECT id, name, item_type, description, default_unit, default_unit_price,
                    gl_revenue_account_code, taxable
               FROM billing_items WHERE tenant_id = ?{$activeSql} AND id IN ({$marks})"
        );
        $stmt->execute(array_merge([$tenantId], $catalogIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) $catalogById[(int) $item['id']] = $item;
        if (count($catalogById) !== count($catalogIds)) {
            throw new InvalidArgumentException('One or more products or services are unavailable');
        }
    }

    foreach ($lines as &$line) {
        $catalogId = (int) ($line['catalog_item_id'] ?? 0);
        if ($catalogId <= 0) continue;
        $item = $catalogById[$catalogId];
        if (trim((string) ($line['description'] ?? '')) === '') $line['description'] = $item['description'] ?: $item['name'];
        if (empty($line['item_type'])) $line['item_type'] = $item['item_type'];
        if (empty($line['unit'])) $line['unit'] = $item['default_unit'];
        if (!array_key_exists('unit_price', $line) || $line['unit_price'] === '') $line['unit_price'] = $item['default_unit_price'] ?? 0;
        if (empty($line['gl_revenue_account_code'])) $line['gl_revenue_account_code'] = $item['gl_revenue_account_code'];
        if (!array_key_exists('taxable', $line)) $line['taxable'] = (int) $item['taxable'] === 1;
    }
    unset($line);
    return $lines;
}

function billingInsertDirectInvoiceLines(PDO $pdo, int $invoiceId, array $lines): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO billing_invoice_lines
          (invoice_id, line_no, source_type, catalog_item_id, item_type, description, quantity, unit, unit_price,
           subtotal, tax_rate_pct, tax_amount, total, gl_revenue_account_code)
         VALUES
          (:invoice_id, :line_no, "manual", :catalog_item_id, :item_type, :description, :quantity, :unit, :unit_price,
           :subtotal, :tax_rate_pct, :tax_amount, :total, :gl_rev)'
    );
    $lineNo = 1;
    foreach ($lines as $line) {
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'line_no' => $lineNo++,
            'catalog_item_id' => !empty($line['catalog_item_id']) ? (int) $line['catalog_item_id'] : null,
            'item_type' => apNormalizeItemType($line['item_type'] ?? null, 'manual'),
            'description' => $line['description'] ?? '',
            'quantity' => $line['quantity'] ?? 0,
            'unit' => $line['unit'] ?? 'each',
            'unit_price' => $line['unit_price'] ?? 0,
            'subtotal' => $line['subtotal'],
            'tax_rate_pct' => $line['tax_rate_pct'],
            'tax_amount' => $line['tax_amount'],
            'total' => $line['total'],
            'gl_rev' => $line['gl_revenue_account_code'] ?? null,
        ]);
    }
}

function billingValidateDirectInvoiceClientCompanyId(
    PDO $pdo,
    int $catalogTenantId,
    string $clientName,
    mixed $suppliedCompanyId
): ?int {
    $clientName = trim($clientName);
    if ($clientName === '' || strlen($clientName) > 255) {
        throw new InvalidArgumentException('Enter a client name of at most 255 characters');
    }
    if ($suppliedCompanyId === null || $suppliedCompanyId === '') return null;
    if ((!is_int($suppliedCompanyId) && !is_string($suppliedCompanyId))
        || !ctype_digit((string) $suppliedCompanyId) || (int) $suppliedCompanyId <= 0) {
        throw new InvalidArgumentException('Choose a valid client company');
    }
    $clientStmt = $pdo->prepare(
        'SELECT id, name FROM companies
          WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL'
    );
    $clientStmt->execute(['tenant_id' => $catalogTenantId, 'id' => (int) $suppliedCompanyId]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) throw new InvalidArgumentException('Client company is not available in this workspace');
    if (strcasecmp(trim((string) $client['name']), $clientName) !== 0) {
        throw new InvalidArgumentException('Client name does not match the selected company');
    }
    return (int) $client['id'];
}

function billingResolveDirectInvoiceClientCompanyId(
    PDO $pdo,
    int $catalogTenantId,
    string $clientName,
    mixed $suppliedCompanyId,
    ?int $actorUserId = null
): int {
    $clientCompanyId = billingValidateDirectInvoiceClientCompanyId(
        $pdo, $catalogTenantId, $clientName, $suppliedCompanyId
    );
    if ($clientCompanyId === null) {
        $clientCompanyId = companiesUpsertByName($catalogTenantId, trim($clientName),
            ['created_by_user_id' => $actorUserId], ['client']);
    }
    companiesBumpUsage($clientCompanyId);
    return $clientCompanyId;
}

function billingCreateDirectInvoiceDraft(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $clientName = trim((string) ($body['client_name'] ?? ''));
    if ($clientName === '' || strlen($clientName) > 255) {
        throw new InvalidArgumentException('Enter a client name of at most 255 characters');
    }
    if (empty($body['lines']) || !is_array($body['lines'])) {
        throw new InvalidArgumentException('lines must be a non-empty array');
    }
    $pdo = getDB();
    $body['lines'] = billingPrepareDirectInvoiceLines($pdo, $tenantId, $body['lines']);
    $taxStmt = $pdo->prepare('SELECT billing_tax_rate_pct, billing_invoice_terms FROM tenants WHERE id = :id');
    $taxStmt->execute(['id' => $tenantId]);
    $cfg = $taxStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $taxPct = (float) ($cfg['billing_tax_rate_pct'] ?? 0);
    if (array_key_exists('tax_rate_pct', $body) && $body['tax_rate_pct'] !== null && $body['tax_rate_pct'] !== '') {
        if (!is_numeric($body['tax_rate_pct'])) throw new InvalidArgumentException('Tax rate must be numeric');
        $taxPct = (float) $body['tax_rate_pct'];
    }
    if ($taxPct < 0 || $taxPct > 100) throw new InvalidArgumentException('Tax rate must be between 0 and 100');
    $netDays = preg_match('/^NET(\d+)$/i', (string) ($cfg['billing_invoice_terms'] ?? 'NET30'), $m)
        ? (int) $m[1] : 30;

    $clientCatalogTenantId = staffingClientCatalogTenantId($tenantId);
    try {
        $clientTerms = $pdo->prepare(
            'SELECT payment_terms_days FROM staffing_clients
              WHERE tenant_id = :t AND name = :n AND payment_terms_days IS NOT NULL LIMIT 1'
        );
        $clientTerms->execute(['t' => $clientCatalogTenantId, 'n' => $clientName]);
        $perClient = $clientTerms->fetchColumn();
        if ($perClient !== false && $perClient !== null && (int) $perClient >= 0) {
            $netDays = (int) $perClient;
        }
    } catch (Throwable $_) { /* staffing_clients may not exist yet */ }

    $validDate = static function (string $value): bool {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    };
    $issueDate = trim((string) ($body['issue_date'] ?? date('Y-m-d')));
    if (!$validDate($issueDate)) throw new InvalidArgumentException('Issue date must use YYYY-MM-DD');
    $dueDate = trim((string) ($body['due_date'] ?? ''));
    if ($dueDate === '') $dueDate = date('Y-m-d', strtotime("+{$netDays} days", strtotime($issueDate)));
    if (!$validDate($dueDate)) throw new InvalidArgumentException('Due date must use YYYY-MM-DD');
    if ($dueDate < $issueDate) throw new InvalidArgumentException('Due date cannot precede issue date');
    $resolvedInvoiceTerms = $netDays === 0 ? 'DUE_ON_RECEIPT' : 'NET' . $netDays;
    $computed = billingComputeTax($body['lines'], $taxPct);
    try {
        $issuingEntity = activeEntityResolveForTenant(
            $tenantId, !empty($body['entity_id']) ? (int) $body['entity_id'] : null
        );
    } catch (Throwable $e) {
        throw new InvalidArgumentException($e->getMessage(), 0, $e);
    }
    if (!$issuingEntity) throw new InvalidArgumentException('An active issuing entity is required');
    $currency = $body['currency'] ?? $issuingEntity['base_currency'];
    if (!is_string($currency) || $currency !== (string) $issuingEntity['base_currency']) {
        throw new InvalidArgumentException('Invoice currency must match the issuing entity');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $clientCompanyId = billingResolveDirectInvoiceClientCompanyId(
            $pdo, $clientCatalogTenantId, $clientName, $body['client_company_id'] ?? null, $actorUserId
        );
        $invoiceNumber = billingNextInvoiceNumber($tenantId);
        $invoiceId = scopedInsert('billing_invoices', [
            'tenant_id' => $tenantId,
            'invoice_number' => $invoiceNumber,
            'client_name' => $clientName,
            'client_company_id' => $clientCompanyId,
            'entity_id' => (int) $issuingEntity['id'],
            'bill_to_json' => isset($body['bill_to']) ? json_encode($body['bill_to']) : null,
            'currency' => $currency,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'payment_terms' => $resolvedInvoiceTerms,
            'po_number' => $body['po_number'] ?? null,
            'notes_internal' => $body['notes_internal'] ?? null,
            'notes_external' => $body['notes_external'] ?? null,
            'subtotal' => $computed['subtotal'],
            'tax_total' => $computed['tax_total'],
            'total' => $computed['total'],
            'amount_due' => $computed['total'],
            'aggregation' => 'per_client',
            'status' => 'draft',
            'created_by_user_id' => $actorUserId,
        ]);
        billingInsertDirectInvoiceLines($pdo, $invoiceId, $computed['lines']);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['id' => $invoiceId, 'invoice_number' => $invoiceNumber,
        'entity_id' => (int) $issuingEntity['id'], 'total' => $computed['total']];
}
