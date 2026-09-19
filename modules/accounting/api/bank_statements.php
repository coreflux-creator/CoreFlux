<?php
/**
 * Accounting API — Bank statement import + line matching.
 *
 *   GET  /api/accounting/bank_statements?bank_account_id=N[&match_status=unmatched]
 *        [&q=vendor][&date_from=YYYY-MM-DD][&date_to=YYYY-MM-DD]
 *        [&amount_min=-100][&amount_max=100][&page=1][&per_page=25]
 *   GET  /api/accounting/bank_statements?action=invoice_candidates&line_id=N
 *   POST /api/accounting/bank_statements?action=import_csv&bank_account_id=N
 *        Body: { csv: <text>, header_map?: { date_col, desc_col, amount_col, fitid_col } }
 *   POST /api/accounting/bank_statements?action=match&line_id=N         Body: { je_id }
 *   POST /api/accounting/bank_statements?action=match_ap_payment&line_id=N Body: { payment_id }
 *   POST /api/accounting/bank_statements?action=match_invoice&line_id=N Body: { invoice_id }
 *   POST /api/accounting/bank_statements?action=split_match_invoices&line_id=N
 *        Body: { allocations: [{ invoice_id, amount }], account_splits: [{ account_id, amount, memo? }] }
 *   POST /api/accounting/bank_statements?action=unmatch&line_id=N
 *   POST /api/accounting/bank_statements?action=ignore&line_id=N
 *   POST /api/accounting/bank_statements?action=apply_rules&bank_account_id=N
 *        → walks unmatched lines and auto-applies any approved rule that matches.
 *          Suggested (is_approved=0) rules write ai_suggested_* fields instead.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/treasury/bank_transaction_identity.php';
require_once __DIR__ . '/../lib/accounting.php';
require_once __DIR__ . '/../lib/bank_rec.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'invoice_candidates') {
    rbac_legacy_require($user, 'accounting.bank.manage');
    rbac_legacy_require($user, 'billing.view');
    $lid = (int) ($_GET['line_id'] ?? 0);
    if ($lid <= 0) api_error('line_id required', 400);

    $pdo = getDB();
    $lineStmt = $pdo->prepare(
        'SELECT bl.id, bl.amount, bl.posted_date, ba.entity_id AS bank_entity_id,
                ba.currency AS bank_currency
           FROM accounting_bank_statement_lines bl
           JOIN accounting_bank_accounts ba
             ON ba.tenant_id = bl.tenant_id AND ba.id = bl.bank_account_id
          WHERE bl.tenant_id = :tenant_id AND bl.id = :id LIMIT 1'
    );
    $lineStmt->execute(['tenant_id' => (int) $ctx['tenant_id'], 'id' => $lid]);
    $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
    if (!$line) api_error('Line not found', 404);
    if ((float) $line['amount'] <= 0) api_error('Only incoming bank receipts can be applied to customer invoices', 422);

    $params = [
        'bank_currency' => (string) ($line['bank_currency'] ?: 'USD'),
        'posted_date' => (string) $line['posted_date'],
    ];
    $entitySql = '';
    if (!empty($line['bank_entity_id'])) {
        $entitySql = ' AND (bi.entity_id = :bank_entity_id OR bi.entity_id IS NULL)';
        $params['bank_entity_id'] = (int) $line['bank_entity_id'];
    }
    $rows = scopedQuery(
        'SELECT bi.id, bi.invoice_number, bi.client_name, bi.client_company_id,
                bi.entity_id, bi.issue_date, bi.due_date, bi.currency,
                bi.amount_due, bi.status, bi.journal_entry_id
           FROM billing_invoices bi
           JOIN accounting_journal_entries je
             ON je.tenant_id = bi.tenant_id AND je.id = bi.journal_entry_id AND je.status = "posted"
          WHERE bi.tenant_id = :tenant_id
            AND bi.status IN ("approved", "sent", "partially_paid")
            AND bi.amount_due > 0
            AND bi.currency = :bank_currency
            AND bi.issue_date <= :posted_date' . $entitySql . '
          ORDER BY bi.client_name ASC, bi.due_date ASC, bi.id ASC
          LIMIT 300',
        $params
    );
    foreach ($rows as &$row) {
        $row['amount_due'] = round((float) $row['amount_due'], 2);
        $row['label'] = (string) $row['client_name'] . ' - ' . (string) $row['invoice_number'];
    }
    unset($row);
    api_ok(['rows' => $rows, 'line_amount' => round((float) $line['amount'], 2)]);
    exit;
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.coa.view');
    $bid = (int) ($_GET['bank_account_id'] ?? 0);
    if ($bid <= 0) api_error('bank_account_id required', 400);
    $pdo = getDB();
    if (empty($_GET['match_status']) || $_GET['match_status'] === 'unmatched') {
        bankRecRepairPostedMatches((int) $ctx['tenant_id'], $bid);
    }
    $where  = ['tenant_id = :tenant_id', 'bank_account_id = :b'];
    $params = ['b' => $bid];
    if (bankTxnHasColumn($pdo, 'accounting_bank_statement_lines', 'duplicate_of_line_id')) {
        $where[] = 'duplicate_of_line_id IS NULL';
    }
    $matchStatus = trim((string) ($_GET['match_status'] ?? ''));
    if ($matchStatus !== '' && !in_array($matchStatus, ['unmatched', 'matched', 'ignored'], true)) {
        api_error('Invalid match_status', 422, ['allowed' => ['unmatched', 'matched', 'ignored']]);
    }
    if ($matchStatus !== '') {
        $where[] = 'match_status = :ms';
        $params['ms'] = $matchStatus;
    }

    $q = trim((string) ($_GET['q'] ?? ''));
    if (strlen($q) > 120) api_error('Search is too long (max 120 characters)', 422);
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(description LIKE :q_description
                  OR bank_reference LIKE :q_reference
                  OR fitid LIKE :q_fitid
                  OR CAST(amount AS CHAR) LIKE :q_amount)';
        $params['q_description'] = $like;
        $params['q_reference'] = $like;
        $params['q_fitid'] = $like;
        $params['q_amount'] = $like;
    }

    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $field => $date) {
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            api_error("{$field} must use YYYY-MM-DD", 422);
        }
    }
    if ($dateFrom !== '') {
        $where[] = 'posted_date >= :date_from';
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'posted_date <= :date_to';
        $params['date_to'] = $dateTo;
    }
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        api_error('date_from cannot be after date_to', 422);
    }

    foreach (['amount_min' => '>=', 'amount_max' => '<='] as $field => $operator) {
        $raw = trim((string) ($_GET[$field] ?? ''));
        if ($raw === '') continue;
        if (!is_numeric($raw)) api_error("{$field} must be numeric", 422);
        $where[] = "amount {$operator} :{$field}";
        $params[$field] = (float) $raw;
    }
    if (isset($params['amount_min'], $params['amount_max']) && $params['amount_min'] > $params['amount_max']) {
        api_error('amount_min cannot exceed amount_max', 422);
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(200, max(1, (int) ($_GET['per_page'] ?? 25)));
    $whereSql = implode(' AND ', $where);
    $countRow = scopedFind(
        'SELECT COUNT(*) AS total
           FROM accounting_bank_statement_lines
          WHERE ' . $whereSql,
        $params
    );
    $total = (int) ($countRow['total'] ?? 0);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $rows = scopedQuery(
        'SELECT id, posted_date, description, amount, bank_reference, fitid, match_status,
                matched_je_id, matched_at, ai_suggested_account_code, ai_suggested_je_id,
                ai_suggested_rule_id, ai_suggested_confidence, applied_rule_id
         FROM accounting_bank_statement_lines
         WHERE ' . $whereSql . '
         ORDER BY posted_date DESC, id DESC
         LIMIT ' . $perPage . ' OFFSET ' . $offset,
        $params
    );
    if (rbac_legacy_can($user, 'billing.view')) {
        $rows = bankRecAttachInvoiceSuggestions((int) $ctx['tenant_id'], $bid, $rows);
    }
    if (rbac_legacy_can($user, 'ap.view')) {
        $rows = bankRecAttachApPaymentSuggestions((int) $ctx['tenant_id'], $bid, $rows);
    }
    api_ok([
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => $pages,
    ]);
}

if ($method === 'POST' && $action === 'import_csv') {
    rbac_legacy_require($user, 'accounting.je.create');
    $bid = (int) ($_GET['bank_account_id'] ?? 0);
    if ($bid <= 0) api_error('bank_account_id required', 400);
    $bank = scopedFind('SELECT id FROM accounting_bank_accounts WHERE tenant_id = :tenant_id AND id = :id', ['id' => $bid]);
    if (!$bank) api_error('Bank account not found', 404);
    $body = api_json_body();
    $csv  = (string) ($body['csv'] ?? '');
    $map  = $body['header_map'] ?? null;
    if ($csv === '') api_error('csv body required', 422);
    $res = bankRecImportCsv((int) $ctx['tenant_id'], $bid, $csv, is_array($map) ? $map : null, $user['id'] ?? null);
    accountingAudit('accounting.bank.statement_imported',
        ['bank_account_id' => $bid, 'rows' => $res['inserted'], 'duplicates' => $res['duplicates']],
        $res['import_id']);
    api_ok($res, 201);
}

if ($method === 'POST' && $action === 'match') {
    rbac_legacy_require($user, 'accounting.je.create');
    $lid = (int) ($_GET['line_id'] ?? 0);
    if ($lid <= 0) api_error('line_id required', 400);
    $body = api_json_body();
    $jeId = (int) ($body['je_id'] ?? 0);
    if ($jeId <= 0) api_error('je_id required', 422);
    try {
        $res = bankRecMatchLine((int) $ctx['tenant_id'], $lid, $jeId, $user['id'] ?? null);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 409);
    }
    accountingAudit('accounting.bank.line_matched', ['line_id' => $lid, 'je_id' => $jeId], $lid);
    api_ok($res);
}

if ($method === 'POST' && $action === 'match_ap_payment') {
    rbac_legacy_require($user, 'accounting.bank.manage');
    rbac_legacy_require($user, 'ap.payment.send');
    $lid = (int) ($_GET['line_id'] ?? 0);
    if ($lid <= 0) api_error('line_id required', 400);
    $body = api_json_body();
    $paymentId = (int) ($body['payment_id'] ?? 0);
    if ($paymentId <= 0) api_error('payment_id required', 422);

    $line = scopedFind(
        'SELECT bl.id, bl.amount, bl.posted_date, bl.match_status,
                ba.id AS bank_account_id, ba.entity_id AS bank_entity_id,
                COALESCE(NULLIF(ba.currency, ""), "USD") AS bank_currency
           FROM accounting_bank_statement_lines bl
           JOIN accounting_bank_accounts ba
             ON ba.tenant_id = bl.tenant_id AND ba.id = bl.bank_account_id
          WHERE bl.tenant_id = :tenant_id AND bl.id = :id',
        ['id' => $lid]
    );
    if (!$line) api_error('Line not found', 404);
    if (($line['match_status'] ?? '') !== 'unmatched') api_error('This bank line is already resolved', 409);
    if ((float) $line['amount'] >= 0) api_error('Only outgoing bank lines can clear AP payments', 422);

    $payment = scopedFind(
        'SELECT * FROM ap_payments WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $paymentId]
    );
    if (!$payment) api_error('AP payment not found', 404);
    if (!in_array((string) $payment['status'], ['sent', 'cleared'], true)) {
        api_error('Only a released or cleared AP payment can be matched', 409);
    }
    if ((float) ($payment['unallocated_amount'] ?? 0) > 0.005) {
        api_error('Allocate the full AP payment before matching it', 409);
    }
    if (abs(abs((float) $line['amount']) - (float) $payment['amount']) > 0.005) {
        api_error('The AP payment amount does not equal this bank debit', 409);
    }
    if (strcasecmp((string) ($payment['currency'] ?: 'USD'), (string) $line['bank_currency']) !== 0) {
        api_error('The AP payment uses a different currency', 409);
    }
    if (!empty($line['bank_entity_id']) && !empty($payment['entity_id'])
        && (int) $line['bank_entity_id'] !== (int) $payment['entity_id']) {
        api_error('The AP payment belongs to a different entity', 409);
    }
    if (!empty($payment['bank_account_id'])
        && (int) $payment['bank_account_id'] !== (int) $line['bank_account_id']) {
        api_error('The AP payment was released from a different bank account', 409);
    }

    require_once __DIR__ . '/../../ap/lib/ap.php';
    try {
        $cleared = apClearPayment(
            (int) $ctx['tenant_id'],
            $paymentId,
            (string) $line['posted_date'],
            (int) $line['bank_account_id'],
            $user['id'] ?? null
        );
        $match = bankRecMatchLine(
            (int) $ctx['tenant_id'],
            $lid,
            (int) $cleared['journal_entry_id'],
            $user['id'] ?? null
        );
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422, ['retryable' => true]);
    }

    if (empty($cleared['idempotent_replay'])) {
        apAudit('ap.payment.cleared_from_bank', [
            'payment_id' => $paymentId,
            'bank_line_id' => $lid,
            'journal_entry_id' => (int) $cleared['journal_entry_id'],
            'bank_account_id' => (int) $line['bank_account_id'],
        ], $paymentId);
    }
    accountingAudit('accounting.bank.ap_payment_matched', [
        'line_id' => $lid,
        'payment_id' => $paymentId,
        'je_id' => (int) $cleared['journal_entry_id'],
    ], $lid);
    api_ok([
        'ok' => true,
        'line_id' => $lid,
        'payment_id' => $paymentId,
        'matched_je_id' => (int) $cleared['journal_entry_id'],
        'payment_status' => 'cleared',
        'idempotent_replay' => !empty($cleared['idempotent_replay']) || !empty($match['idempotent_replay']),
    ]);
}

if ($method === 'POST' && $action === 'split_match_invoices') {
    rbac_legacy_require($user, 'accounting.bank.manage');
    rbac_legacy_require($user, 'billing.payments.record');
    $lid = (int) ($_GET['line_id'] ?? 0);
    if ($lid <= 0) api_error('line_id required', 400);
    $body = api_json_body();
    $allocations = (array) ($body['allocations'] ?? []);
    $accountSplits = (array) ($body['account_splits'] ?? []);
    if (!$allocations) api_error('Choose at least one customer invoice', 422);

    $pdo = getDB();
    $lineStmt = $pdo->prepare(
        'SELECT bl.*, ba.gl_account_code, ba.entity_id AS bank_entity_id,
                ba.currency AS bank_currency
           FROM accounting_bank_statement_lines bl
           JOIN accounting_bank_accounts ba
             ON ba.tenant_id = bl.tenant_id AND ba.id = bl.bank_account_id
          WHERE bl.tenant_id = :tenant_id AND bl.id = :id LIMIT 1'
    );
    $lineStmt->execute(['tenant_id' => (int) $ctx['tenant_id'], 'id' => $lid]);
    $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
    if (!$line) api_error('Line not found', 404);
    if (($line['match_status'] ?? '') !== 'unmatched') api_error('This bank line is already resolved', 409);
    $lineAmount = round((float) ($line['amount'] ?? 0), 2);
    if ($lineAmount <= 0) api_error('Only incoming bank receipts can be applied to customer invoices', 422);

    $invoiceAmounts = [];
    foreach ($allocations as $allocation) {
        $invoiceId = (int) ($allocation['invoice_id'] ?? 0);
        $amount = round((float) ($allocation['amount'] ?? 0), 2);
        if ($invoiceId <= 0 || $amount <= 0) api_error('Every invoice allocation needs an invoice and positive amount', 422);
        $invoiceAmounts[$invoiceId] = round(($invoiceAmounts[$invoiceId] ?? 0) + $amount, 2);
    }

    $invoiceRows = [];
    $invoiceTotal = 0.0;
    foreach ($invoiceAmounts as $invoiceId => $amount) {
        $invoice = scopedFind(
            'SELECT bi.*, je.status AS journal_status
               FROM billing_invoices bi
               LEFT JOIN accounting_journal_entries je
                 ON je.tenant_id = bi.tenant_id AND je.id = bi.journal_entry_id
              WHERE bi.tenant_id = :tenant_id AND bi.id = :id',
            ['id' => $invoiceId]
        );
        if (!$invoice) api_error("Invoice {$invoiceId} not found", 404);
        if (!in_array($invoice['status'], ['approved', 'sent', 'partially_paid'], true)) {
            api_error("Invoice {$invoice['invoice_number']} is not ready to receive payment", 409);
        }
        if (($invoice['journal_status'] ?? null) !== 'posted') {
            api_error("Post invoice {$invoice['invoice_number']} to the ledger before applying payment", 409);
        }
        if ($amount - 0.005 > (float) $invoice['amount_due']) {
            api_error("Allocation exceeds the open balance on invoice {$invoice['invoice_number']}", 409);
        }
        if (!empty($invoice['entity_id']) && !empty($line['bank_entity_id'])
            && (int) $invoice['entity_id'] !== (int) $line['bank_entity_id']) {
            api_error("Invoice {$invoice['invoice_number']} belongs to a different entity", 409);
        }
        $bankCurrency = (string) ($line['bank_currency'] ?: 'USD');
        if (strcasecmp((string) ($invoice['currency'] ?: 'USD'), $bankCurrency) !== 0) {
            api_error("Invoice {$invoice['invoice_number']} uses a different currency", 409);
        }
        if ((string) $invoice['issue_date'] > (string) $line['posted_date']) {
            api_error("Invoice {$invoice['invoice_number']} was issued after this bank receipt; record it as unapplied cash first", 409);
        }
        $invoice['apply_amount'] = $amount;
        $invoiceRows[] = $invoice;
        $invoiceTotal += $amount;
    }

    $validatedAccountSplits = [];
    $accountTotal = 0.0;
    foreach ($accountSplits as $split) {
        $accountId = (int) ($split['account_id'] ?? 0);
        $amount = round((float) ($split['amount'] ?? 0), 2);
        if ($accountId <= 0 || $amount <= 0) api_error('Every GL split needs an account and positive amount', 422);
        $account = scopedFind(
            'SELECT id, code, name, active, is_postable
               FROM accounting_accounts
              WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => $accountId]
        );
        if (!$account || !(int) $account['active'] || !(int) $account['is_postable']) {
            api_error('Choose an active, postable GL account for every non-invoice portion', 409);
        }
        if ((string) $account['code'] === '1100') {
            api_error('Use an Invoice target instead of posting a generic Accounts Receivable split', 409);
        }
        try {
            $counterpartyEntityId = accountingValidateActiveEntityId(
                (int) $ctx['tenant_id'],
                $split['entity_id'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        }
        $validatedAccountSplits[] = [
            'account_id' => $accountId,
            'amount' => $amount,
            'memo' => trim((string) ($split['memo'] ?? '')),
            'entity_id' => $counterpartyEntityId,
        ];
        $accountTotal += $amount;
    }

    $assignedTotal = round($invoiceTotal + $accountTotal, 2);
    if (abs($assignedTotal - $lineAmount) > 0.005) {
        api_error(sprintf('Invoice and GL portions must total the bank receipt (%.2f of %.2f assigned)', $assignedTotal, $lineAmount), 422);
    }

    require_once __DIR__ . '/../../billing/lib/billing.php';
    $jeLines = [[
        'account_code' => (string) $line['gl_account_code'],
        'debit' => $lineAmount,
        'credit' => 0,
        'memo' => (string) ($line['description'] ?: 'Customer receipt'),
    ]];
    foreach ($invoiceRows as $invoice) {
        $jeLines[] = [
            'account_code' => '1100',
            'debit' => 0,
            'credit' => (float) $invoice['apply_amount'],
            'memo' => 'Apply receipt to ' . $invoice['invoice_number'],
            'counterparty_company_id' => $invoice['client_company_id'] ?? null,
        ];
    }
    foreach ($validatedAccountSplits as $split) {
        $jeLines[] = [
            'account_id' => $split['account_id'],
            'debit' => 0,
            'credit' => $split['amount'],
            'memo' => $split['memo'] ?: ((string) ($line['description'] ?? 'Receipt split')),
            'counterparty_entity_id' => $split['entity_id'],
        ];
    }

    try {
        $receiptJe = accountingPostJe((int) $ctx['tenant_id'], [
            'entity_id' => (int) ($line['bank_entity_id'] ?? 0),
            'posting_date' => (string) $line['posted_date'],
            'currency' => (string) ($line['bank_currency'] ?: 'USD'),
            'source_module' => 'billing',
            'source_ref_type' => 'bank_statement_line',
            'source_ref_id' => $lid,
            'idempotency_key' => 'billing:bank-receipt-split:' . $lid,
            'memo' => 'Split customer receipt / ' . (string) ($line['description'] ?? ''),
            'lines' => $jeLines,
        ], $user['id'] ?? null, true);
    } catch (\Throwable $e) {
        api_error('Could not post the split receipt: ' . $e->getMessage(), 422);
    }

    $groups = [];
    foreach ($invoiceRows as $invoice) {
        $groupKey = !empty($invoice['client_company_id'])
            ? 'company:' . (int) $invoice['client_company_id']
            : 'name:' . sha1(strtolower(trim((string) $invoice['client_name'])));
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'client_name' => (string) $invoice['client_name'],
                'currency' => (string) ($invoice['currency'] ?: 'USD'),
                'amount' => 0.0,
                'allocations' => [],
            ];
        }
        $groups[$groupKey]['amount'] = round($groups[$groupKey]['amount'] + (float) $invoice['apply_amount'], 2);
        $groups[$groupKey]['allocations'][] = [
            'invoice_id' => (int) $invoice['id'],
            'amount' => (float) $invoice['apply_amount'],
        ];
    }

    $paymentIds = [];
    try {
        foreach ($groups as $groupKey => $group) {
            $externalId = 'bank-line:' . $lid . ':' . substr($groupKey, 0, 80);
            $payment = scopedFind(
                'SELECT * FROM billing_payments
                  WHERE tenant_id = :tenant_id AND source_system = "manual" AND external_id = :external_id
                  LIMIT 1',
                ['external_id' => $externalId]
            );
            $paymentId = $payment ? (int) $payment['id'] : scopedInsert('billing_payments', [
                'tenant_id' => (int) $ctx['tenant_id'],
                'client_name' => $group['client_name'],
                'received_at' => (string) $line['posted_date'],
                'method' => str_contains(strtoupper((string) $line['description']), 'WIRE') ? 'wire'
                    : (str_contains(strtoupper((string) $line['description']), 'ACH') ? 'ach'
                    : (str_contains(strtoupper((string) $line['description']), 'CHECK') ? 'check' : 'other')),
                'reference' => $line['bank_reference'] ?: ($line['fitid'] ?? null),
                'external_id' => $externalId,
                'source_system' => 'manual',
                'amount' => $group['amount'],
                'currency' => $group['currency'],
                'unallocated_amount' => $group['amount'],
                'notes' => 'Allocated from split bank receipt line #' . $lid,
                'created_by_user_id' => $user['id'] ?? null,
            ]);

            $missing = [];
            foreach ($group['allocations'] as $allocation) {
                $existing = $pdo->prepare(
                    'SELECT id, amount_applied FROM billing_payment_allocations
                      WHERE payment_id = :payment_id AND invoice_id = :invoice_id LIMIT 1'
                );
                $existing->execute(['payment_id' => $paymentId, 'invoice_id' => $allocation['invoice_id']]);
                $existingAllocation = $existing->fetch(PDO::FETCH_ASSOC);
                if (!$existingAllocation) {
                    $missing[] = $allocation;
                } elseif (abs((float) $existingAllocation['amount_applied'] - (float) $allocation['amount']) > 0.005) {
                    throw new RuntimeException('An existing bank allocation has a different amount; review the payment before retrying');
                }
            }
            if ($missing) billingAllocatePayment($paymentId, ['allocations' => $missing], $user['id'] ?? null);
            $paymentIds[] = $paymentId;
        }
    } catch (\Throwable $e) {
        api_error('The receipt posted, but its invoice allocation needs attention: ' . $e->getMessage(), 422);
    }

    bankRecMarkLineMatched((int) $ctx['tenant_id'], $lid, (int) $receiptJe['je_id'], $user['id'] ?? null);
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:tenant_id, "billing", :source_record_id, :journal_entry_id, "primary")'
        )->execute([
            'tenant_id' => (int) $ctx['tenant_id'],
            'source_record_id' => 'bank_line:invoice_split:' . $lid,
            'journal_entry_id' => (int) $receiptJe['je_id'],
        ]);
    } catch (\Throwable $_) { /* optional on older tenants */ }

    billingAudit('billing.payment.recorded', [
        'payment_ids' => $paymentIds,
        'invoice_allocations' => $invoiceAmounts,
        'bank_statement_line_id' => $lid,
        'amount' => $invoiceTotal,
    ], $paymentIds[0] ?? null);
    accountingAudit('accounting.bank.line_matched', [
        'line_id' => $lid,
        'payment_ids' => $paymentIds,
        'invoice_allocations' => $invoiceAmounts,
        'account_split_count' => count($validatedAccountSplits),
        'je_id' => (int) $receiptJe['je_id'],
    ], $lid);
    api_ok([
        'ok' => true,
        'line_id' => $lid,
        'payment_ids' => $paymentIds,
        'matched_je_id' => (int) $receiptJe['je_id'],
        'invoice_total' => round($invoiceTotal, 2),
        'account_total' => round($accountTotal, 2),
    ]);
}

if ($method === 'POST' && $action === 'match_invoice') {
    rbac_legacy_require($user, 'accounting.bank.manage');
    rbac_legacy_require($user, 'billing.payments.record');
    $lid = (int) ($_GET['line_id'] ?? 0);
    if ($lid <= 0) api_error('line_id required', 400);
    $body = api_json_body();
    $invoiceId = (int) ($body['invoice_id'] ?? 0);
    if ($invoiceId <= 0) api_error('invoice_id required', 422);

    $pdo = getDB();
    $lineStmt = $pdo->prepare(
        'SELECT bl.*, ba.gl_account_code, ba.entity_id AS bank_entity_id
           FROM accounting_bank_statement_lines bl
           JOIN accounting_bank_accounts ba
             ON ba.tenant_id = bl.tenant_id AND ba.id = bl.bank_account_id
          WHERE bl.tenant_id = :tenant_id AND bl.id = :id LIMIT 1'
    );
    $lineStmt->execute(['tenant_id' => (int) $ctx['tenant_id'], 'id' => $lid]);
    $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
    if (!$line) api_error('Line not found', 404);
    if (($line['match_status'] ?? '') !== 'unmatched') api_error('This bank line is already resolved', 409);
    $amount = round((float) ($line['amount'] ?? 0), 2);
    if ($amount <= 0) api_error('Only incoming bank receipts can be matched to customer invoices', 422);

    $invoice = scopedFind(
        'SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $invoiceId]
    );
    if (!$invoice) api_error('Invoice not found', 404);
    if (!in_array($invoice['status'], ['approved', 'sent', 'partially_paid'], true)) {
        api_error('Only approved or sent invoices can receive a bank payment', 409);
    }
    if (empty($invoice['journal_entry_id'])) {
        api_error('Post this invoice to the ledger before applying its bank payment', 409);
    }
    $invoiceJe = scopedFind(
        'SELECT id FROM accounting_journal_entries
          WHERE tenant_id = :tenant_id AND id = :id AND status = "posted"',
        ['id' => (int) $invoice['journal_entry_id']]
    );
    if (!$invoiceJe) api_error('The invoice ledger entry is not posted yet', 409);
    if (abs((float) $invoice['amount_due'] - $amount) > 0.005) {
        api_error('The bank receipt must exactly match the invoice open balance', 409);
    }
    if (!empty($invoice['entity_id']) && !empty($line['bank_entity_id'])
        && (int) $invoice['entity_id'] !== (int) $line['bank_entity_id']) {
        api_error('The invoice and bank account belong to different entities', 409);
    }

    require_once __DIR__ . '/../../billing/lib/billing.php';
    require_once __DIR__ . '/../lib/accounting.php';

    $externalId = 'bank-line:' . $lid;
    $existingPayment = scopedFind(
        'SELECT * FROM billing_payments
          WHERE tenant_id = :tenant_id AND source_system = "manual" AND external_id = :external_id
          LIMIT 1',
        ['external_id' => $externalId]
    );
    if ($existingPayment) {
        $existingAllocation = $pdo->prepare(
            'SELECT invoice_id FROM billing_payment_allocations WHERE payment_id = :payment_id LIMIT 1'
        );
        $existingAllocation->execute(['payment_id' => (int) $existingPayment['id']]);
        $allocatedInvoiceId = (int) ($existingAllocation->fetchColumn() ?: 0);
        if ($allocatedInvoiceId > 0 && $allocatedInvoiceId !== $invoiceId) {
            api_error('This bank receipt is already allocated to another invoice', 409);
        }
    }

    try {
        $receiptJe = accountingPostJe((int) $ctx['tenant_id'], [
            'entity_id' => (int) ($invoice['entity_id'] ?: $line['bank_entity_id']),
            'posting_date' => (string) $line['posted_date'],
            'currency' => (string) ($invoice['currency'] ?: 'USD'),
            'source_module' => 'billing',
            'source_ref_type' => 'bank_statement_line',
            'source_ref_id' => $lid,
            'idempotency_key' => 'billing:bank-receipt:' . $lid . ':invoice:' . $invoiceId,
            'memo' => 'Payment received for ' . $invoice['invoice_number'] . ' / ' . $invoice['client_name'],
            'lines' => [
                [
                    'account_code' => (string) $line['gl_account_code'],
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => (string) ($line['description'] ?: 'Customer receipt'),
                    'counterparty_company_id' => $invoice['client_company_id'] ?? null,
                ],
                [
                    'account_code' => '1100',
                    'debit' => 0,
                    'credit' => $amount,
                    'memo' => 'Apply receipt to ' . $invoice['invoice_number'],
                    'counterparty_company_id' => $invoice['client_company_id'] ?? null,
                ],
            ],
        ], $user['id'] ?? null, true);
    } catch (\Throwable $e) {
        api_error('Could not post the invoice receipt: ' . $e->getMessage(), 422);
    }

    cf_begin_transaction();
    try {
        $paymentId = $existingPayment ? (int) $existingPayment['id'] : scopedInsert('billing_payments', [
            'tenant_id' => (int) $ctx['tenant_id'],
            'client_name' => (string) $invoice['client_name'],
            'received_at' => (string) $line['posted_date'],
            'method' => str_contains(strtoupper((string) $line['description']), 'WIRE') ? 'wire'
                : (str_contains(strtoupper((string) $line['description']), 'ACH') ? 'ach'
                : (str_contains(strtoupper((string) $line['description']), 'CHECK') ? 'check' : 'other')),
            'reference' => $line['bank_reference'] ?: ($line['fitid'] ?? null),
            'external_id' => $externalId,
            'source_system' => 'manual',
            'amount' => $amount,
            'currency' => (string) ($invoice['currency'] ?: 'USD'),
            'unallocated_amount' => $amount,
            'notes' => 'Matched from bank reconciliation line #' . $lid,
            'created_by_user_id' => $user['id'] ?? null,
        ]);

        $allocationStmt = $pdo->prepare(
            'SELECT id FROM billing_payment_allocations
              WHERE payment_id = :payment_id AND invoice_id = :invoice_id LIMIT 1'
        );
        $allocationStmt->execute(['payment_id' => $paymentId, 'invoice_id' => $invoiceId]);
        if (!$allocationStmt->fetchColumn()) {
            billingAllocatePayment($paymentId, [
                'allocations' => [['invoice_id' => $invoiceId, 'amount' => $amount]],
            ], $user['id'] ?? null);
        }

        bankRecMarkLineMatched((int) $ctx['tenant_id'], $lid, (int) $receiptJe['je_id'], $user['id'] ?? null);
        try {
            $pdo->prepare(
                'INSERT IGNORE INTO accounting_subledger_links
                    (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
                 VALUES (:tenant_id, "billing", :source_record_id, :journal_entry_id, "primary")'
            )->execute([
                'tenant_id' => (int) $ctx['tenant_id'],
                'source_record_id' => 'bank_line:invoice:' . $lid,
                'journal_entry_id' => (int) $receiptJe['je_id'],
            ]);
        } catch (\Throwable $_) { /* optional on older tenants */ }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_error('Could not apply the bank receipt to the invoice: ' . $e->getMessage(), 422);
    }

    billingAudit('billing.payment.recorded', [
        'payment_id' => $paymentId,
        'invoice_id' => $invoiceId,
        'bank_statement_line_id' => $lid,
        'amount' => $amount,
    ], $paymentId);
    accountingAudit('accounting.bank.line_matched', [
        'line_id' => $lid,
        'invoice_id' => $invoiceId,
        'payment_id' => $paymentId,
        'je_id' => (int) $receiptJe['je_id'],
    ], $lid);
    api_ok([
        'ok' => true,
        'line_id' => $lid,
        'invoice_id' => $invoiceId,
        'payment_id' => $paymentId,
        'matched_je_id' => (int) $receiptJe['je_id'],
        'invoice_status' => abs($amount - (float) $invoice['amount_due']) <= 0.005 ? 'paid' : 'partially_paid',
    ]);
}

if ($method === 'POST' && $action === 'unmatch') {
    rbac_legacy_require($user, 'accounting.je.create');
    $lid = (int) ($_GET['line_id'] ?? 0);
    if ($lid <= 0) api_error('line_id required', 400);
    try {
        $res = bankRecUnmatchLine((int) $ctx['tenant_id'], $lid);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 409);
    }
    accountingAudit('accounting.bank.line_unmatched', ['line_id' => $lid], $lid);
    api_ok($res);
}

if ($method === 'POST' && $action === 'ignore') {
    rbac_legacy_require($user, 'accounting.je.create');
    $lid = (int) ($_GET['line_id'] ?? 0);
    if ($lid <= 0) api_error('line_id required', 400);
    scopedUpdate('accounting_bank_statement_lines', $lid, ['match_status' => 'ignored']);
    accountingAudit('accounting.bank.line_ignored', ['line_id' => $lid], $lid);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'apply_rules') {
    rbac_legacy_require($user, 'accounting.je.create');
    $bid = (int) ($_GET['bank_account_id'] ?? 0);
    if ($bid <= 0) api_error('bank_account_id required', 400);
    $res = bankRecApplyRules((int) $ctx['tenant_id'], $bid, $user['id'] ?? null);
    accountingAudit('accounting.bank.rules_applied',
        ['bank_account_id' => $bid, 'auto_applied' => $res['auto_applied'], 'suggested' => $res['suggested']]);
    api_ok($res);
}

if ($method === 'POST' && $action === 'accept_ai_categorize') {
    // Stamp the user-accepted COA code onto the bank line + record the
    // accept/override into ai_categorization_history so future predictions
    // for the same merchant get a high-confidence history hit.
    rbac_legacy_require($user, 'accounting.je.create');
    $lid  = (int) ($_GET['line_id'] ?? 0);
    if ($lid <= 0) api_error('line_id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['account_code']);
    $line = scopedFind('SELECT id, description, amount FROM accounting_bank_statement_lines WHERE tenant_id = :tenant_id AND id = :id', ['id' => $lid]);
    if (!$line) api_error('Line not found', 404);
    scopedUpdate('accounting_bank_statement_lines', $lid, [
        'categorized_account_code' => (string) $body['account_code'],
        'categorized_at'           => date('Y-m-d H:i:s'),
        'categorized_by_user_id'   => $user['id'] ?? null,
        'categorized_via'          => 'ai_accepted',
    ]);

    // Resolve account_code → account_id for the unified history table.
    $finalAcct = scopedFind(
        'SELECT id FROM accounting_accounts WHERE tenant_id = :tenant_id AND code = :c LIMIT 1',
        ['c' => (string) $body['account_code']]
    );
    $finalAccountId = $finalAcct ? (int) $finalAcct['id'] : 0;

    require_once __DIR__ . '/../../../core/ai_categorization.php';
    aiRecordCategorizationOutcome(
        (int) $ctx['tenant_id'],
        (int) ($body['ai_suggestion_id'] ?? 0) ?: null,
        $finalAccountId,
        [
            'id'            => $lid,
            'merchant_name' => $line['description'],
            'category'      => null,
        ],
        (int) ($user['id'] ?? 0)
    );
    accountingAudit('accounting.bank.ai_categorize_accepted',
        ['line_id' => $lid, 'account_code' => $body['account_code'], 'final_account_id' => $finalAccountId], $lid);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
