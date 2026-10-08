<?php
/** Atomic first-run cutover of ordinary balances and open AR/AP source documents. */
declare(strict_types=1);

require_once __DIR__ . '/opening_balances.php';
require_once __DIR__ . '/../../billing/lib/invoice_drafts.php';
require_once __DIR__ . '/../../ap/lib/bill_drafts.php';

use Core\CsvImportService;

function accountingOpeningDocumentRegisterSchemas(): void
{
    CsvImportService::registerSchema('accounting_open_ar', [
        'fields' => [
            'invoice_number' => ['label' => 'Invoice number', 'required' => true],
            'client_name' => ['label' => 'Client name', 'required' => true],
            'issue_date' => ['label' => 'Issue date', 'required' => true, 'type' => 'date'],
            'due_date' => ['label' => 'Due date', 'required' => true, 'type' => 'date'],
            'open_amount' => ['label' => 'Open amount', 'required' => true],
        ],
        'unique_within_batch' => ['invoice_number'],
    ]);
    CsvImportService::registerSchema('accounting_open_ap', [
        'fields' => [
            'bill_number' => ['label' => 'Bill number', 'required' => true],
            'vendor_name' => ['label' => 'Vendor name', 'required' => true],
            'bill_date' => ['label' => 'Bill date', 'required' => true, 'type' => 'date'],
            'due_date' => ['label' => 'Due date', 'required' => true, 'type' => 'date'],
            'open_amount' => ['label' => 'Open amount', 'required' => true],
        ],
    ]);
}

function accountingOpeningDocumentDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

/** @return array{rows:list<array<string,string>>,errors:array<int,list<string>>,total_cents:int} */
function accountingOpeningDocumentRows(PDO $pdo, int $tenantId, int $entityId,
    string $csv, string $kind, string $cutoverDate, bool $checkExisting): array
{
    if (strlen($csv) > 1048576) throw new InvalidArgumentException('Each source-document CSV is limited to 1 MB.');
    $schema = $kind === 'ar' ? 'accounting_open_ar' : 'accounting_open_ap';
    $dry = CsvImportService::dryRun($schema, $csv);
    $errors = $dry['errors'];
    if ($dry['row_count'] > 500) $errors[0][] = 'Use at most 500 open documents per file.';
    $rows = [];
    $totalCents = 0;
    $seenBills = [];
    $existing = $pdo->prepare($kind === 'ar'
        ? 'SELECT id FROM billing_invoices WHERE tenant_id = :t AND invoice_number = :number LIMIT 1'
        : 'SELECT id FROM ap_bills WHERE tenant_id = :t AND entity_id = :e
            AND vendor_name = :party AND bill_number = :number AND status <> "void" LIMIT 1');
    foreach ($dry['rows'] as $rowNumber => $row) {
        if (isset($errors[$rowNumber])) continue;
        $number = trim((string) $row[$kind === 'ar' ? 'invoice_number' : 'bill_number']);
        $party = trim((string) $row[$kind === 'ar' ? 'client_name' : 'vendor_name']);
        $date = (string) $row[$kind === 'ar' ? 'issue_date' : 'bill_date'];
        $due = (string) $row['due_date'];
        if (strlen($number) > ($kind === 'ar' ? 40 : 80)) {
            $errors[$rowNumber][] = 'Reference exceeds the document number limit.';
        }
        if (strlen($party) > 255) $errors[$rowNumber][] = 'Party name exceeds 255 characters.';
        if (!accountingOpeningDocumentDate($date) || !accountingOpeningDocumentDate($due)
            || $date > $cutoverDate || $due < $date) {
            $errors[$rowNumber][] = 'Use real dates: document date no later than cutover, due date on or after document date.';
        }
        try {
            $cents = accountingOpeningSignedCents((string) $row['open_amount']);
            if ($cents <= 0) $errors[$rowNumber][] = 'Open amount must be greater than zero.';
        } catch (InvalidArgumentException $error) {
            $errors[$rowNumber][] = $error->getMessage();
            $cents = 0;
        }
        if ($kind === 'ap') {
            $signature = $party . "\0" . $number;
            $key = function_exists('mb_strtolower')
                ? mb_strtolower($signature, 'UTF-8') : strtolower($signature);
            if (isset($seenBills[$key])) {
                $errors[$rowNumber][] = 'Duplicate vendor and bill number (also row ' . $seenBills[$key] . ').';
            }
            $seenBills[$key] = $rowNumber;
        }
        if ($checkExisting && !isset($errors[$rowNumber])) {
            $params = ['t' => $tenantId, 'number' => $number];
            if ($kind === 'ap') $params += ['e' => $entityId, 'party' => $party];
            $existing->execute($params);
            if ($existing->fetchColumn()) $errors[$rowNumber][] = 'This source document already exists.';
        }
        if (isset($errors[$rowNumber])) continue;
        $row['open_amount'] = accountingOpeningAmount($cents);
        $rows[] = $row;
        $totalCents += $cents;
    }
    if ($totalCents > 99999999999) $errors[0][] = 'Document total cannot exceed $999,999,999.99.';
    return ['rows' => $rows, 'errors' => $errors, 'total_cents' => $totalCents];
}

/** Source documents can establish opening AR/AP without an unrelated balance journal. */
function accountingOpeningDocumentOnlyReview(PDO $pdo, int $tenantId, int $entityId, ?array $prior): array
{
    $context = accountingOpeningContext($pdo, $tenantId, $entityId);
    $date = $context['posting_date'];
    $errors = [];
    if ($prior) {
        if ($prior['balance_je_id'] !== null) {
            $errors[0][] = 'This cutover already includes ordinary opening balances.';
        }
    } else {
        $journals = $pdo->prepare(
            'SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = :t AND entity_id = :e'
        );
        $journals->execute(['t' => $tenantId, 'e' => $entityId]);
        if ((int) $journals->fetchColumn() > 0) {
            $errors[0][] = 'Opening documents must be imported before other journals for this legal entity.';
        }
        $bankLines = $pdo->prepare(
            'SELECT COUNT(*) FROM accounting_bank_statement_lines l
               JOIN accounting_bank_accounts b ON b.id = l.bank_account_id AND b.tenant_id = l.tenant_id
              WHERE l.tenant_id = :t AND b.entity_id = :e'
        );
        $bankLines->execute(['t' => $tenantId, 'e' => $entityId]);
        if ((int) $bankLines->fetchColumn() > 0) {
            $errors[0][] = 'Import opening documents before bank statement lines for this legal entity.';
        }
    }
    $period = $pdo->prepare(
        'SELECT status FROM accounting_periods WHERE tenant_id = :t AND entity_id = :e
            AND start_date <= :d1 AND end_date >= :d2 LIMIT 1'
    );
    $period->execute(['t' => $tenantId, 'e' => $entityId, 'd1' => $date, 'd2' => $date]);
    $status = $period->fetchColumn();
    if ($status && !in_array($status, ['open', 'reopened'], true)) {
        $errors[0][] = 'The cutover period is closed; reopen it before importing opening documents.';
    }
    $token = hash('sha256', json_encode([$tenantId, $entityId, $date, []], JSON_THROW_ON_ERROR));
    return [
        'entity_id' => $entityId, 'entity_name' => $context['entity_name'],
        'first_fiscal_day' => $context['first_fiscal_day'], 'posting_date' => $date,
        'rows' => [], 'row_count' => 0,
        'balancing_equity' => ['account_code' => '3000', 'debit' => '0.00', 'credit' => '0.00'],
        'total_debit' => '0.00', 'total_credit' => '0.00',
        'errors' => $errors, 'error_count' => count($errors),
        'preview_token' => $errors ? null : $token,
        'already_posted' => $prior !== null && !$errors,
        'journal_entry_id' => null, 'journal_lines' => [],
    ];
}

function accountingOpeningCutoverFirstJournal(PDO $pdo, int $tenantId, int $batchId): ?int
{
    foreach (['billing_invoices', 'ap_bills'] as $table) {
        $stmt = $pdo->prepare(
            "SELECT journal_entry_id FROM {$table}
              WHERE tenant_id = :t AND opening_cutover_id = :batch
              ORDER BY id LIMIT 1"
        );
        $stmt->execute(['t' => $tenantId, 'batch' => $batchId]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) return $id;
    }
    return null;
}

function accountingOpeningCutoverReview(PDO $pdo, int $tenantId, int $entityId,
    string $balancesCsv, string $arCsv, string $apCsv): array
{
    if (trim($arCsv) === '' && trim($apCsv) === '') {
        throw new InvalidArgumentException('Add an open invoices or open bills CSV, or use the balances-only import.');
    }
    accountingOpeningDocumentRegisterSchemas();
    $priorStmt = $pdo->prepare(
        'SELECT * FROM accounting_opening_document_cutovers WHERE tenant_id = :t AND entity_id = :e LIMIT 1'
    );
    $priorStmt->execute(['t' => $tenantId, 'e' => $entityId]);
    $prior = $priorStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $base = trim($balancesCsv) === ''
        ? accountingOpeningDocumentOnlyReview($pdo, $tenantId, $entityId, $prior)
        : accountingOpeningReview($pdo, $tenantId, $entityId, $balancesCsv);
    $ar = trim($arCsv) === '' ? ['rows' => [], 'errors' => [], 'total_cents' => 0]
        : accountingOpeningDocumentRows($pdo, $tenantId, $entityId, $arCsv, 'ar', $base['posting_date'], !$prior);
    $ap = trim($apCsv) === '' ? ['rows' => [], 'errors' => [], 'total_cents' => 0]
        : accountingOpeningDocumentRows($pdo, $tenantId, $entityId, $apCsv, 'ap', $base['posting_date'], !$prior);
    $errors = ['balances' => $base['errors'], 'ar' => $ar['errors'], 'ap' => $ap['errors']];
    if (!$ar['rows'] && !$ap['rows'] && !$ar['errors'] && !$ap['errors']) {
        $errors['cutover'][0][] = 'Add at least one open invoice or bill row, or use the balances-only import.';
    }
    if ($base['already_posted'] && !$prior) {
        $errors['cutover'][0][] = 'The balances-only opening was already posted. Source documents cannot be added to it afterward.';
    }
    if (!$prior && !$base['already_posted']) {
        foreach (['billing_invoices' => 'invoices', 'ap_bills' => 'bills'] as $table => $label) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE tenant_id = :t AND entity_id = :e");
            $stmt->execute(['t' => $tenantId, 'e' => $entityId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $errors['cutover'][0][] = "Opening documents must be imported before other {$label} for this entity.";
            }
        }
    }
    if ($prior && (!$base['already_posted'] || (int) $prior['balance_je_id'] !== (int) $base['journal_entry_id'])) {
        $errors['cutover'][0][] = 'The existing source-document cutover no longer matches its opening journal.';
    }
    $accountStmt = $pdo->prepare(
        'SELECT code, account_type, normal_side, active, is_postable, currency
           FROM accounting_accounts WHERE tenant_id = :t AND code = :code LIMIT 1'
    );
    foreach (array_filter(['1100' => count($ar['rows']), '2000' => count($ap['rows']), '3000' => 1]) as $code => $_count) {
        $accountStmt->execute(['t' => $tenantId, 'code' => $code]);
        $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
        $expected = match ($code) {
            1100 => ['asset', 'debit'],
            2000 => ['liability', 'credit'],
            default => ['equity', 'credit'],
        };
        if (!$account || !(int) $account['active'] || !(int) $account['is_postable']
            || [$account['account_type'], $account['normal_side']] !== $expected
            || ($account['currency'] && strtoupper((string) $account['currency']) !== 'USD')) {
            $errors['cutover'][0][] = "Control account {$code} must be active, postable USD, and have its standard normal side.";
        }
    }
    $token = hash('sha256', json_encode([$tenantId, $entityId, $base['posting_date'],
        $base['journal_lines'], $ar['rows'], $ap['rows']], JSON_THROW_ON_ERROR));
    if ($prior && !hash_equals((string) $prior['preview_hash'], $token)) {
        $errors['cutover'][0][] = 'This entity already has a different opening source-document cutover.';
    }
    if ($prior && empty($errors['cutover'])) {
        foreach (['ar' => 'billing_invoices', 'ap' => 'ap_bills'] as $kind => $table) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) AS count_docs, COUNT(je.id) AS count_posted,
                        COALESCE(SUM(d.total), 0) AS total
                   FROM {$table} d LEFT JOIN accounting_journal_entries je
                     ON je.id = d.journal_entry_id AND je.tenant_id = d.tenant_id AND je.status = 'posted'
                  WHERE d.tenant_id = :t AND d.opening_cutover_id = :id"
            );
            $stmt->execute(['t' => $tenantId, 'id' => (int) $prior['id']]);
            $counts = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int) $counts['count_docs'] !== (int) $prior[$kind . '_count']
                || (int) $counts['count_posted'] !== (int) $prior[$kind . '_count']
                || accountingOpeningSignedCents((string) $counts['total'])
                    !== accountingOpeningSignedCents((string) $prior[$kind . '_total'])) {
                $errors['cutover'][0][] = 'An opening source document or its posted journal is missing or changed.';
            }
        }
    }
    $errorCount = 0;
    foreach ($errors as $group) $errorCount += count($group);
    return [
        'balances' => $base,
        'ar_rows' => $ar['rows'], 'ap_rows' => $ap['rows'],
        'ar_count' => count($ar['rows']), 'ap_count' => count($ap['rows']),
        'ar_total' => accountingOpeningAmount($ar['total_cents']),
        'ap_total' => accountingOpeningAmount($ap['total_cents']),
        'errors' => $errors, 'error_count' => $errorCount,
        'preview_token' => $errorCount === 0 ? $token : null,
        'already_posted' => $prior !== null && $errorCount === 0,
        'cutover_id' => $prior && $errorCount === 0 ? (int) $prior['id'] : null,
        'journal_entry_id' => $prior && $errorCount === 0
            ? ($base['journal_entry_id'] ?? accountingOpeningCutoverFirstJournal($pdo, $tenantId, (int) $prior['id']))
            : null,
    ];
}

function accountingOpeningCutoverLink(PDO $pdo, int $tenantId, string $source, int $documentId, int $jeId): void
{
    $pdo->prepare(
        'INSERT INTO accounting_subledger_links
           (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
         VALUES (:t, :source, :document, :je, "primary")'
    )->execute(['t' => $tenantId, 'source' => $source, 'document' => $documentId, 'je' => $jeId]);
}

function accountingOpeningCutoverInvoice(PDO $pdo, int $tenantId, int $entityId,
    int $batchId, string $postingDate, array $row, ?int $actorUserId): array
{
    $companyId = billingResolveDirectInvoiceClientCompanyId(
        $pdo, staffingClientCatalogTenantId($tenantId), (string) $row['client_name'], null, $actorUserId
    );
    $amount = (string) $row['open_amount'];
    $number = (string) $row['invoice_number'];
    $invoiceId = scopedInsert('billing_invoices', [
        'tenant_id' => $tenantId, 'entity_id' => $entityId,
        'invoice_number' => $number, 'client_name' => $row['client_name'],
        'client_company_id' => $companyId, 'currency' => 'USD',
        'issue_date' => $row['issue_date'], 'due_date' => $row['due_date'],
        'subtotal' => $amount, 'tax_total' => '0.00', 'total' => $amount,
        'amount_paid' => '0.00', 'amount_due' => $amount,
        'status' => 'approved', 'aggregation' => 'per_client',
        'opening_cutover_id' => $batchId,
        'notes_internal' => 'Opening receivable: remaining unpaid balance only. Original invoice history was not imported.',
        'approved_by_user_id' => $actorUserId, 'approved_at' => date('Y-m-d H:i:s'),
        'created_by_user_id' => $actorUserId,
    ]);
    billingInsertDirectInvoiceLines($pdo, $invoiceId, [[
        'description' => 'Opening receivable from prior system: ' . $number,
        'quantity' => '1.0000', 'unit' => 'each', 'unit_price' => $amount,
        'subtotal' => $amount, 'tax_rate_pct' => '0.0000',
        'tax_amount' => '0.00', 'total' => $amount,
    ]]);
    $posted = accountingPostJe($tenantId, [
        'entity_id' => $entityId, 'posting_date' => $postingDate, 'currency' => 'USD',
        'source_module' => 'billing', 'source_ref_type' => 'billing_invoice',
        'source_ref_id' => $invoiceId,
        'idempotency_key' => "opening:ar:{$tenantId}:{$entityId}:{$invoiceId}",
        'memo' => 'Opening receivable / ' . $number,
        'lines' => [
            ['account_code' => '1100', 'debit' => $amount, 'credit' => '0.00',
                'counterparty_company_id' => $companyId, 'dims' => ['client' => $companyId]],
            ['account_code' => '3000', 'debit' => '0.00', 'credit' => $amount],
        ],
    ], $actorUserId, true);
    $jeId = (int) $posted['je_id'];
    $pdo->prepare('UPDATE billing_invoices SET journal_entry_id = :je WHERE tenant_id = :t AND id = :id')
        ->execute(['je' => $jeId, 't' => $tenantId, 'id' => $invoiceId]);
    accountingOpeningCutoverLink($pdo, $tenantId, 'billing', $invoiceId, $jeId);
    return ['id' => $invoiceId, 'reference' => $number, 'journal_entry_id' => $jeId];
}

function accountingOpeningCutoverBill(PDO $pdo, int $tenantId, int $entityId,
    int $batchId, string $postingDate, array $row, ?int $actorUserId): array
{
    $amount = (string) $row['open_amount'];
    $bill = apCreateManualBill($tenantId, [
        'entity_id' => $entityId, 'vendor_name' => $row['vendor_name'],
        'bill_number' => $row['bill_number'], 'bill_date' => $row['bill_date'],
        'received_at' => $postingDate, 'due_date' => $row['due_date'],
        'currency' => 'USD', 'tax_rate_pct' => 0,
        'notes_internal' => 'Opening payable: remaining unpaid balance only. Original bill history was not imported.',
        'lines' => [[
            'description' => 'Opening payable from prior system: ' . $row['bill_number'],
            'quantity' => 1, 'unit' => 'each', 'unit_price' => $amount,
            'is_1099_eligible' => false,
        ]],
    ], $actorUserId);
    $billId = (int) $bill['id'];
    $companyStmt = $pdo->prepare('SELECT vendor_company_id FROM ap_bills WHERE tenant_id = :t AND id = :id');
    $companyStmt->execute(['t' => $tenantId, 'id' => $billId]);
    $companyId = (int) ($companyStmt->fetchColumn() ?: 0);
    $dims = $companyId > 0 ? ['vendor' => $companyId] : [];
    $posted = accountingPostJe($tenantId, [
        'entity_id' => $entityId, 'posting_date' => $postingDate, 'currency' => 'USD',
        'source_module' => 'ap', 'source_ref_type' => 'ap_bill', 'source_ref_id' => $billId,
        'idempotency_key' => "opening:ap:{$tenantId}:{$entityId}:{$billId}",
        'memo' => 'Opening payable / ' . $row['bill_number'],
        'lines' => [
            ['account_code' => '3000', 'debit' => $amount, 'credit' => '0.00'],
            ['account_code' => '2000', 'debit' => '0.00', 'credit' => $amount,
                'counterparty_company_id' => $companyId ?: null, 'dims' => $dims],
        ],
    ], $actorUserId, true);
    $jeId = (int) $posted['je_id'];
    $pdo->prepare(
        'UPDATE ap_bills SET status = "approved", approved_at = NOW(),
                approved_by_user_id = :actor, opening_cutover_id = :batch,
                journal_entry_id = :je
          WHERE tenant_id = :t AND id = :id'
    )->execute(['actor' => $actorUserId, 'batch' => $batchId, 'je' => $jeId,
        't' => $tenantId, 'id' => $billId]);
    accountingOpeningCutoverLink($pdo, $tenantId, 'ap', $billId, $jeId);
    return ['id' => $billId, 'reference' => (string) $row['bill_number'], 'journal_entry_id' => $jeId];
}

/** One outer transaction owns the opening journal, all source documents, and their journals. */
function accountingOpeningCutoverCommit(PDO $pdo, int $tenantId, int $entityId,
    string $balancesCsv, string $arCsv, string $apCsv, string $previewToken, ?int $actorUserId): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $previewToken)) {
        throw new InvalidArgumentException('Preview the opening documents before posting.');
    }
    $owns = !$pdo->inTransaction();
    $savepoint = $owns ? null : 'opening_cutover_' . bin2hex(random_bytes(4));
    if ($owns) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT ' . $savepoint);
    try {
        $lock = $pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id = :t AND id = :e FOR UPDATE');
        $lock->execute(['t' => $tenantId, 'e' => $entityId]);
        if (!$lock->fetchColumn()) throw new InvalidArgumentException('Choose a legal entity in this workspace.');
        $review = accountingOpeningCutoverReview($pdo, $tenantId, $entityId,
            $balancesCsv, $arCsv, $apCsv);
        if ($review['error_count'] > 0) {
            $messages = [];
            foreach ($review['errors'] as $group) foreach ($group as $rowErrors) {
                array_push($messages, ...$rowErrors);
            }
            throw new AccountingOpeningConflict('Opening cutover cannot be posted: ' . implode('; ', $messages));
        }
        if (!hash_equals((string) $review['preview_token'], $previewToken)) {
            throw new AccountingOpeningConflict('Opening documents changed since preview; review them again.');
        }
        if ($review['already_posted']) {
            if ($owns) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return ['cutover_id' => $review['cutover_id'],
                'journal_entry_id' => $review['journal_entry_id'],
                'ar_count' => $review['ar_count'], 'ap_count' => $review['ap_count'],
                'idempotent_replay' => true];
        }
        if (trim($balancesCsv) === '') {
            accountingOpeningEnsurePeriod($pdo, $tenantId, $entityId, $review['balances']['posting_date']);
            $base = ['journal_entry_id' => null];
        } else {
            $base = accountingOpeningCommit($pdo, $tenantId, $entityId, $balancesCsv,
                (string) $review['balances']['preview_token'], $actorUserId);
        }
        $pdo->prepare(
            'INSERT INTO accounting_opening_document_cutovers
               (tenant_id, entity_id, posting_date, preview_hash, balance_je_id,
                ar_count, ap_count, ar_total, ap_total, created_by_user_id)
             VALUES (:t, :e, :date, :hash, :je, :ar_count, :ap_count, :ar_total, :ap_total, :actor)'
        )->execute([
            't' => $tenantId, 'e' => $entityId,
            'date' => $review['balances']['posting_date'], 'hash' => $previewToken,
            'je' => $base['journal_entry_id'], 'ar_count' => $review['ar_count'],
            'ap_count' => $review['ap_count'], 'ar_total' => $review['ar_total'],
            'ap_total' => $review['ap_total'], 'actor' => $actorUserId,
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $invoices = [];
        foreach ($review['ar_rows'] as $row) {
            $invoices[] = accountingOpeningCutoverInvoice($pdo, $tenantId, $entityId,
                $batchId, $review['balances']['posting_date'], $row, $actorUserId);
        }
        $bills = [];
        foreach ($review['ap_rows'] as $row) {
            $bills[] = accountingOpeningCutoverBill($pdo, $tenantId, $entityId,
                $batchId, $review['balances']['posting_date'], $row, $actorUserId);
        }
        if ($invoices) {
            $tenant = $pdo->prepare('SELECT billing_invoice_prefix, billing_next_invoice_seq FROM tenants WHERE id = :t FOR UPDATE');
            $tenant->execute(['t' => $tenantId]);
            $config = $tenant->fetch(PDO::FETCH_ASSOC);
            $prefix = trim((string) ($config['billing_invoice_prefix'] ?? 'INV')) ?: 'INV';
            $next = (int) ($config['billing_next_invoice_seq'] ?? 1);
            $pattern = '/^' . preg_quote($prefix, '/') . '-' . date('Y') . '-(\d+)$/D';
            foreach ($invoices as $invoice) {
                if (preg_match($pattern, $invoice['reference'], $match)) {
                    $next = max($next, (int) $match[1] + 1);
                }
            }
            $pdo->prepare('UPDATE tenants SET billing_next_invoice_seq = :next WHERE id = :t')
                ->execute(['next' => $next, 't' => $tenantId]);
        }
        if ($owns) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        $firstSourceJournal = $invoices[0]['journal_entry_id'] ?? $bills[0]['journal_entry_id'] ?? null;
        return ['cutover_id' => $batchId,
            'journal_entry_id' => $base['journal_entry_id'] ?? $firstSourceJournal,
            'ar_count' => count($invoices), 'ap_count' => count($bills),
            'invoices' => $invoices, 'bills' => $bills, 'idempotent_replay' => false];
    } catch (Throwable $error) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$owns && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        throw $error;
    }
}
