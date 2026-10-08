<?php
/** Hosted, synthetic-only CoreAccounting financial lifecycle acceptance. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || (($argv[1] ?? '') !== '--execute'
    && !defined('QA_LIFECYCLE_LIBRARY_MODE'))) {
    fwrite(STDERR, "Run from CLI with --execute on the isolated CoreFlux staging host only.\n");
    exit(2);
}

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/accounting/entity_setup.php';

const QA_TENANT = 999;
const QA_DATABASE = 'muzqvdvqbx';
const QA_APP_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const QA_ENTITY_CODE = 'SIM-LIFECYCLE-QA';
const QA_BANK_CODE = '1097';
const QA_BASE_URL = 'https://phpstack-1516771-6707602.cloudwaysapps.com';

$pdo = getDB();
if (realpath(__DIR__ . '/..') !== QA_APP_ROOT
    || $pdo->query('SELECT DATABASE()')->fetchColumn() !== QA_DATABASE) {
    throw new RuntimeException('Refusing a non-staging application or database');
}
$tenant = $pdo->query('SELECT id, is_simulation FROM tenants WHERE id = ' . QA_TENANT)->fetch(PDO::FETCH_ASSOC);
if (!$tenant || (int) $tenant['is_simulation'] !== 1) {
    throw new RuntimeException('Refusing a non-simulation tenant');
}

function qaOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function qaEnsureActor(PDO $pdo, string $label): array
{
    $email = 'coreaccounting-stage-' . $label . '@coreflux.test';
    $password = bin2hex(random_bytes(28));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $row = qaOne($pdo, 'SELECT id, tenant_id FROM users WHERE email = :email', ['email' => $email]);
    if ($row) {
        if ((int) $row['tenant_id'] !== QA_TENANT) {
            throw new RuntimeException('Synthetic actor email belongs to another tenant');
        }
        $userId = (int) $row['id'];
        $pdo->prepare('UPDATE users SET password_hash = :hash, password = NULL, is_active = 1
            WHERE id = :id AND tenant_id = :tenant_id')
            ->execute(['hash' => $hash, 'id' => $userId, 'tenant_id' => QA_TENANT]);
    } else {
        $pdo->prepare('INSERT INTO users (tenant_id, name, email, password_hash, role, is_active)
            VALUES (:tenant_id, :name, :email, :hash, "tenant_admin", 1)')
            ->execute(['tenant_id' => QA_TENANT, 'name' => 'Synthetic ' . ucfirst($label),
                'email' => $email, 'hash' => $hash]);
        $userId = (int) $pdo->lastInsertId();
    }
    if (!qaOne($pdo, 'SELECT id FROM tenant_memberships WHERE tenant_id = :t AND user_id = :u',
        ['t' => QA_TENANT, 'u' => $userId])) {
        $pdo->prepare('INSERT INTO tenant_memberships
            (user_id, tenant_id, persona_label, persona_type, is_primary, status)
            VALUES (:u, :t, :label, "tenant_admin", 1, "active")')
            ->execute(['u' => $userId, 't' => QA_TENANT, 'label' => 'Synthetic ' . ucfirst($label)]);
    }
    if (!qaOne($pdo, 'SELECT id FROM user_tenants WHERE tenant_id = :t AND user_id = :u',
        ['t' => QA_TENANT, 'u' => $userId])) {
        $pdo->prepare('INSERT INTO user_tenants (user_id, tenant_id, role, is_default, status)
            VALUES (:u, :t, "tenant_admin", 1, "active")')
            ->execute(['u' => $userId, 't' => QA_TENANT]);
    }
    return ['id' => $userId, 'email' => $email, 'password' => $password];
}

function qaRequest(string $path, string $method, ?array $body, string $cookie): array
{
    $url = QA_BASE_URL . $path;
    $curl = curl_init($url);
    $headers = ['Accept: application/json', 'X-CoreFlux-Tenant-Id: ' . QA_TENANT];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    unset($curl);
    if ($raw === false) throw new RuntimeException("{$method} {$path}: transport error {$error}");
    $decoded = json_decode($raw, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        throw new RuntimeException("{$method} {$path}: HTTP {$status} " . substr((string) $raw, 0, 500));
    }
    if (isset($decoded['data_warning'])) {
        throw new RuntimeException("{$method} {$path}: {$decoded['data_warning']}");
    }
    return $decoded;
}

function qaLogin(array $actor, string $cookie): void
{
    $curl = curl_init(QA_BASE_URL . '/login.php');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'username' => $actor['email'], 'password' => $actor['password'], 'redirect' => 'spa',
        ]),
    ]);
    curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $location = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
    $error = curl_error($curl);
    curl_close($curl);
    unset($curl);
    if ($status !== 302 || str_contains((string) $location, 'error=')) {
        throw new RuntimeException("Synthetic {$actor['email']} sign-in failed: HTTP {$status} {$error}");
    }
    qaRequest('/modules/accounting/api/reports.php?type=trial_balance&as_of=2026-10-07', 'GET', null, $cookie);
}

function qaExpect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

function qaBalances(PDO $pdo, int $entityId): array
{
    $stmt = $pdo->prepare('SELECT a.code, ROUND(SUM(l.debit - l.credit), 2) AS net
        FROM accounting_journal_entry_lines l
        JOIN accounting_journal_entries j ON j.id = l.je_id AND j.tenant_id = l.tenant_id
        JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = j.tenant_id
        WHERE j.tenant_id = :t AND j.entity_id = :e AND j.status IN ("posted", "reversed")
        GROUP BY a.code');
    $stmt->execute(['t' => QA_TENANT, 'e' => $entityId]);
    $balances = array_map('floatval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'net', 'code'));
    ksort($balances, SORT_STRING);
    return $balances;
}

function qaReports(int $entityId, string $cookie): array
{
    $reports = [];
    $asOf = max('2026-10-07', date('Y-m-d'));
    foreach (['income_statement', 'balance_sheet', 'cash_flow_indirect'] as $type) {
        $query = $type === 'balance_sheet' ? 'as_of=' . $asOf : 'from=2026-01-01&to=' . $asOf;
        $reports[$type] = qaRequest('/modules/accounting/api/reports.php?type=' . $type .
            '&entity_id=' . $entityId . '&' . $query, 'GET', null, $cookie);
    }
    return $reports;
}

function qaDelta(array $before, array $after, string $key, float $expected): bool
{
    return abs(((float) ($after[$key] ?? 0) - (float) ($before[$key] ?? 0)) - $expected) < 0.005;
}

function qaCsv(array $rows): string
{
    $stream = fopen('php://temp', 'w+');
    if (!$stream) throw new RuntimeException('Could not prepare synthetic bank CSV');
    try {
        foreach ($rows as $row) {
            if (fputcsv($stream, $row, ',', '"', '') === false) {
                throw new RuntimeException('Could not write synthetic bank CSV');
            }
        }
        rewind($stream);
        return (string) stream_get_contents($stream);
    } finally {
        fclose($stream);
    }
}

function qaAuditCount(PDO $pdo, string $event, int $targetId): int
{
    return (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM audit_log
        WHERE tenant_id = :t AND event = :event AND target_id = :target',
        ['t' => QA_TENANT, 'event' => $event, 'target' => $targetId])['n'];
}

if (defined('QA_LIFECYCLE_LIBRARY_MODE')) {
    return;
}

$maker = null;
$reviewer = null;
$cookies = [];
try {
    $entity = qaOne($pdo, 'SELECT id FROM accounting_entities WHERE tenant_id = :t AND code = :c',
        ['t' => QA_TENANT, 'c' => QA_ENTITY_CODE]);
    if (!$entity) {
        $created = accountingCreateEntityWithCalendar($pdo, QA_TENANT, [
            'code' => QA_ENTITY_CODE,
            'legal_name' => 'Synthetic Lifecycle QA LLC',
            'country' => 'US',
            'base_currency' => 'USD',
            'entity_type' => 'llc',
            'accounting_basis' => 'accrual',
            'fiscal_year_start_month' => 1,
        ], 2026);
        $entityId = $created['entity_id'];
    } else {
        $entityId = (int) $entity['id'];
    }
    if (!qaOne($pdo, 'SELECT id FROM accounting_accounts WHERE tenant_id = :t AND code = :c',
        ['t' => QA_TENANT, 'c' => QA_BANK_CODE])) {
        $pdo->prepare('INSERT INTO accounting_accounts
            (tenant_id, code, name, account_type, subtype, statement_section, normal_side,
             is_postable, is_system_account, currency, active)
            VALUES (:t, :c, "Synthetic QA Bank Cash", "asset", "current_asset", "current_assets",
                "debit", 1, 0, "USD", 1)')
            ->execute(['t' => QA_TENANT, 'c' => QA_BANK_CODE]);
    }
    $bank = qaOne($pdo, 'SELECT id FROM accounting_bank_accounts
        WHERE tenant_id = :t AND entity_id = :e AND gl_account_code = :c',
        ['t' => QA_TENANT, 'e' => $entityId, 'c' => QA_BANK_CODE]);
    if (!$bank) {
        $pdo->prepare('INSERT INTO accounting_bank_accounts
            (tenant_id, entity_id, name, gl_account_code, bank_name, currency, status)
            VALUES (:t, :e, "Synthetic Lifecycle QA Bank", :c, "Synthetic Bank", "USD", "active")')
            ->execute(['t' => QA_TENANT, 'e' => $entityId, 'c' => QA_BANK_CODE]);
        $bankId = (int) $pdo->lastInsertId();
    } else {
        $bankId = (int) $bank['id'];
    }
    $maker = qaEnsureActor($pdo, 'maker');
    $reviewer = qaEnsureActor($pdo, 'reviewer');
    $policy = qaOne($pdo, 'SELECT id FROM ap_approval_policies
        WHERE tenant_id = :t AND entity_id = :e AND name = "Synthetic lifecycle QA review"',
        ['t' => QA_TENANT, 'e' => $entityId]);
    $chain = json_encode([['step' => 1, 'approver_user_ids' => [$reviewer['id']],
        'quorum' => 1, 'label' => 'Independent synthetic reviewer']], JSON_THROW_ON_ERROR);
    if ($policy) {
        $pdo->prepare('UPDATE ap_approval_policies SET chain_json = :chain, active = 1 WHERE id = :id')
            ->execute(['chain' => $chain, 'id' => $policy['id']]);
    } else {
        $pdo->prepare('INSERT INTO ap_approval_policies
            (tenant_id, name, priority, entity_id, chain_json, active)
            VALUES (:t, "Synthetic lifecycle QA review", 1, :e, :chain, 1)')
            ->execute(['t' => QA_TENANT, 'e' => $entityId, 'chain' => $chain]);
    }
    echo "STAGING simulation tenant " . QA_TENANT . ", entity {$entityId}, bank {$bankId}\n";

    $makerCookie = tempnam(sys_get_temp_dir(), 'cf-maker-');
    $reviewerCookie = tempnam(sys_get_temp_dir(), 'cf-review-');
    $cookies = [$makerCookie, $reviewerCookie];
    qaLogin($maker, $makerCookie);
    qaLogin($reviewer, $reviewerCookie);
    qaExpect(true, 'independent synthetic actors signed in to staging');

    $abandonedInvoice = qaOne($pdo, 'SELECT id FROM billing_invoices
        WHERE tenant_id = :t AND entity_id = :e AND created_by_user_id = :u
          AND notes_internal LIKE "Synthetic staging lifecycle %" AND status = "draft"
          AND journal_entry_id IS NULL ORDER BY id DESC LIMIT 1',
        ['t' => QA_TENANT, 'e' => $entityId, 'u' => $maker['id']]);
    if ($abandonedInvoice) {
        $oldInvoiceId = (int) $abandonedInvoice['id'];
        $oldAssignment = qaRequest('/modules/billing/api/approval_assignment.php?invoice_id=' .
            $oldInvoiceId, 'GET', null, $makerCookie);
        if (!empty($oldAssignment['pending'])) {
            $oldAssignment = qaRequest('/modules/billing/api/approval_assignment.php', 'POST', [
                'invoice_id' => $oldInvoiceId, 'reviewer_user_ids' => [(int) $reviewer['id']],
            ], $makerCookie);
            qaRequest('/api/workflow.php?action=act&id=' . (int) $oldAssignment['workflow_instance_id'],
                'POST', ['action' => 'reject',
                    'comment' => 'Abandoned synthetic staging acceptance attempt'], $reviewerCookie);
        }
        qaRequest('/modules/billing/api/invoices.php?action=void&id=' . (int) $abandonedInvoice['id'],
            'POST', ['reason' => 'Abandoned synthetic staging acceptance attempt'], $makerCookie);
        echo "Voided abandoned synthetic invoice {$abandonedInvoice['id']}\n";
    }
    $abandonedBill = qaOne($pdo, 'SELECT id FROM ap_bills
        WHERE tenant_id = :t AND entity_id = :e AND created_by_user_id = :u
          AND notes_internal LIKE "Synthetic staging lifecycle %" AND status = "pending_approval"
          AND journal_entry_id IS NULL ORDER BY id DESC LIMIT 1',
        ['t' => QA_TENANT, 'e' => $entityId, 'u' => $maker['id']]);
    if ($abandonedBill) {
        qaRequest('/modules/ap/api/bills.php?action=void&id=' . (int) $abandonedBill['id'],
            'POST', ['reason' => 'Abandoned synthetic staging acceptance attempt'], $makerCookie);
        echo "Voided abandoned synthetic bill {$abandonedBill['id']}\n";
    }

    $beforeBalances = qaBalances($pdo, $entityId);
    $beforeReports = qaReports($entityId, $reviewerCookie);
    $otherEntityJournals = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entries
        WHERE tenant_id = :t AND entity_id <> :e AND status = "posted"',
        ['t' => QA_TENANT, 'e' => $entityId])['n'];

    $run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    echo "Run {$run}\n";
    $client = 'Synthetic QA Client ' . $run;
    $vendor = 'Synthetic QA Vendor ' . $run;
    $date = '2026-10-07';
    $due = '2026-11-06';
    $invoice = qaRequest('/modules/billing/api/invoices.php', 'POST', [
        'entity_id' => $entityId, 'client_name' => $client,
        'issue_date' => $date, 'due_date' => $due, 'currency' => 'USD', 'tax_rate_pct' => 0,
        'notes_internal' => 'Synthetic staging lifecycle ' . $run,
        'lines' => [['description' => 'Invented consulting service', 'quantity' => 1,
            'unit' => 'each', 'unit_price' => 1250, 'item_type' => 'fixed_fee',
            'gl_revenue_account_code' => '4000']],
    ], $makerCookie);
    $invoiceId = (int) ($invoice['id'] ?? 0);
    qaExpect($invoiceId > 0, "invoice draft {$invoiceId} created");
    $bill = qaRequest('/modules/ap/api/bills.php', 'POST', [
        'entity_id' => $entityId, 'vendor_name' => $vendor, 'vendor_type' => 'other',
        'bill_number' => 'QA-' . $run, 'bill_date' => $date, 'received_at' => $date,
        'due_date' => $due, 'currency' => 'USD', 'tax_rate_pct' => 0,
        'notes_internal' => 'Synthetic staging lifecycle ' . $run,
        'lines' => [['description' => 'Invented subcontract service', 'quantity' => 1,
            'unit' => 'each', 'unit_price' => 750, 'item_type' => 'expense',
            'gl_expense_account_code' => '5000']],
    ], $makerCookie);
    $billId = (int) ($bill['id'] ?? 0);
    qaExpect($billId > 0, "bill draft {$billId} created");

    $requestApproval = qaRequest('/modules/billing/api/invoices.php?action=request_approval&id=' .
        $invoiceId, 'POST', [], $makerCookie);
    qaExpect((int) ($requestApproval['workflow_instance_id'] ?? 0) > 0,
        'invoice approval requested through configured policy');
    $assignment = qaRequest('/modules/billing/api/approval_assignment.php', 'POST', [
        'invoice_id' => $invoiceId, 'reviewer_user_ids' => [(int) $reviewer['id']],
    ], $makerCookie);
    qaExpect(in_array((int) $reviewer['id'], $assignment['assigned_reviewer_user_ids'] ?? [], true),
        'invoice review reassigned through audited workflow');
    $approvedInvoice = qaRequest('/modules/billing/api/invoices.php?action=approve&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    qaExpect(!empty($approvedInvoice['approved']), 'independent invoice approval');
    $approvedBill = qaRequest('/modules/ap/api/bills.php?action=approve&id=' . $billId,
        'POST', [], $reviewerCookie);
    qaExpect(($approvedBill['workflow_status'] ?? '') === 'approved', 'independent bill approval');
    $invoicePost = qaRequest('/modules/billing/api/invoices.php?action=post&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    qaExpect((int) ($invoicePost['journal_entry_id'] ?? 0) > 0, 'invoice posted without sending');
    $invoiceReplay = qaRequest('/modules/billing/api/invoices.php?action=post&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    qaExpect(!empty($invoiceReplay['idempotent_replay'])
        && (int) $invoiceReplay['journal_entry_id'] === (int) $invoicePost['journal_entry_id'],
        'invoice repeat post reused its journal');
    $billPost = qaRequest('/modules/ap/api/bills.php?action=post&id=' . $billId,
        'POST', [], $reviewerCookie);
    qaExpect((int) ($billPost['journal_entry_id'] ?? 0) > 0, 'bill posted');
    $billReplay = qaRequest('/modules/ap/api/bills.php?action=post&id=' . $billId,
        'POST', [], $reviewerCookie);
    qaExpect(!empty($billReplay['idempotent_replay'])
        && (int) $billReplay['journal_entry_id'] === (int) $billPost['journal_entry_id'],
        'bill repeat post reused its journal');

    $receiptRequest = [
        'bank_account_id' => $bankId, 'client_name' => $client, 'received_at' => $date,
        'amount' => 400, 'currency' => 'USD', 'method' => 'other',
        'reference' => 'SIM-RECEIPT-' . $run, 'request_key' => 'sim_receipt_' . str_replace('-', '_', $run),
        'allocations' => [['invoice_id' => $invoiceId, 'amount' => 400]],
    ];
    $receipt = qaRequest('/modules/billing/api/payments.php', 'POST', $receiptRequest, $makerCookie);
    qaExpect((int) ($receipt['journal_entry_id'] ?? 0) > 0, 'partial customer receipt posted');
    $receiptReplay = qaRequest('/modules/billing/api/payments.php', 'POST', $receiptRequest, $makerCookie);
    qaExpect(!empty($receiptReplay['idempotent_replay'])
        && (int) $receiptReplay['journal_entry_id'] === (int) $receipt['journal_entry_id'],
        'receipt retry reused its journal and allocation');

    $payment = qaRequest('/modules/ap/api/payments.php', 'POST', [
        'entity_id' => $entityId, 'bank_account_id' => $bankId,
        'vendor_name' => $vendor, 'pay_date' => $date, 'method' => 'check',
        'reference' => 'SIM-PAYMENT-' . $run, 'amount' => 250, 'currency' => 'USD',
    ], $makerCookie);
    $paymentId = (int) ($payment['id'] ?? 0);
    qaExpect($paymentId > 0, 'manual synthetic payment drafted');
    $allocated = qaRequest('/modules/ap/api/payments.php?action=allocate&id=' . $paymentId,
        'POST', ['allocations' => [['bill_id' => $billId, 'amount' => 250]]], $makerCookie);
    qaExpect(abs((float) ($allocated['unallocated_remaining'] ?? -1)) < 0.005, 'payment allocated to bill');
    qaRequest('/modules/ap/api/payments.php?action=send&id=' . $paymentId,
        'POST', [], $reviewerCookie);
    $cleared = qaRequest('/modules/ap/api/payments.php?action=clear&id=' . $paymentId,
        'POST', ['bank_account_id' => $bankId, 'cleared_date' => $date], $reviewerCookie);
    qaExpect((int) ($cleared['journal_entry_id'] ?? 0) > 0, 'manual payment cleared without a payment rail');
    $clearReplay = qaRequest('/modules/ap/api/payments.php?action=clear&id=' . $paymentId,
        'POST', ['bank_account_id' => $bankId, 'cleared_date' => $date], $reviewerCookie);
    qaExpect(!empty($clearReplay['idempotent_replay'])
        && (int) $clearReplay['journal_entry_id'] === (int) $cleared['journal_entry_id'],
        'payment repeat clear reused its journal');
    qaExpect(qaAuditCount($pdo, 'ap.payment.cleared', $paymentId) === 1,
        'payment repeat clear did not duplicate the business audit event');

    $invoiceRow = qaOne($pdo, 'SELECT status, amount_paid, amount_due, journal_entry_id, sent_at FROM billing_invoices
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $invoiceId]);
    $billRow = qaOne($pdo, 'SELECT status, amount_paid, amount_due, journal_entry_id FROM ap_bills
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $billId]);
    $paymentRow = qaOne($pdo, 'SELECT disbursement_rail, rail_external_ref FROM ap_payments
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $paymentId]);
    qaExpect($invoiceRow['sent_at'] === null
        && $paymentRow['disbursement_rail'] === null
        && $paymentRow['rail_external_ref'] === null,
        'no customer message or external disbursement was initiated');
    qaExpect(abs((float) $invoiceRow['amount_paid'] - 400) < 0.005
        && abs((float) $invoiceRow['amount_due'] - 850) < 0.005, 'AR subledger partial balance is 850');
    qaExpect(abs((float) $billRow['amount_paid'] - 250) < 0.005
        && abs((float) $billRow['amount_due'] - 500) < 0.005, 'AP subledger partial balance is 500');

    $balances = qaBalances($pdo, $entityId);
    foreach ([QA_BANK_CODE => 150, '1100' => 850, '2000' => -500,
        '4000' => -1250, '5000' => 750] as $code => $expected) {
        qaExpect(qaDelta($beforeBalances, $balances, (string) $code, (float) $expected),
            "GL {$code} movement = {$expected}");
    }
    qaExpect(abs(array_sum($balances)) < 0.005, 'entity journal balances');
    $otherEntityJournalsAfter = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entries
        WHERE tenant_id = :t AND entity_id <> :e AND status = "posted"',
        ['t' => QA_TENANT, 'e' => $entityId])['n'];
    qaExpect($otherEntityJournalsAfter === $otherEntityJournals,
        'other legal entities have no new journals');

    $afterReports = qaReports($entityId, $reviewerCookie);
    $incomeBefore = $beforeReports['income_statement'];
    $incomeAfter = $afterReports['income_statement'];
    qaExpect(qaDelta($incomeBefore, $incomeAfter, 'total_revenue', 1250)
        && qaDelta($incomeBefore, $incomeAfter, 'total_expense', 750)
        && qaDelta($incomeBefore, $incomeAfter, 'net_income', 500),
        'income statement reflects source posting exactly once');
    $sheetBefore = $beforeReports['balance_sheet'];
    $sheetAfter = $afterReports['balance_sheet'];
    qaExpect(qaDelta($sheetBefore, $sheetAfter, 'total_assets', 1000)
        && qaDelta($sheetBefore, $sheetAfter, 'total_liabilities', 500)
        && qaDelta($sheetBefore, $sheetAfter, 'total_equity', 500)
        && !empty($sheetAfter['balanced']), 'balance sheet agrees with AR, AP and cash');
    $cashBefore = $beforeReports['cash_flow_indirect'];
    $cashAfter = $afterReports['cash_flow_indirect'];
    qaExpect(qaDelta($cashBefore, $cashAfter, 'net_change_in_cash', 150)
        && qaDelta($cashBefore, $cashAfter, 'cash_change_from_gl', 150)
        && abs((float) ($cashAfter['reconciliation_diff'] ?? 1)) < 0.005
        && !empty($cashAfter['balanced']), 'cash flow reconciles to bank GL');

    $fitids = [
        'receipt' => 'SIM-BANK-RECEIPT-' . $run,
        'payment' => 'SIM-BANK-PAYMENT-' . $run,
        'treasury' => 'SIM-BANK-CHARGE-' . $run,
    ];
    $csv = qaCsv([
        ['Date', 'Description', 'Amount', 'Transaction ID'],
        [$date, 'Synthetic QA receipt ' . $run, '400.00', $fitids['receipt']],
        [$date, 'Synthetic QA payment ' . $run, '-250.00', $fitids['payment']],
        [$date, 'Synthetic QA charge ' . $run, '-40.00', $fitids['treasury']],
    ]);
    $importPath = '/modules/accounting/api/bank_statements.php?action=import_csv&bank_account_id=' . $bankId;
    $import = qaRequest($importPath, 'POST', ['csv' => $csv], $makerCookie);
    qaExpect((int) ($import['inserted'] ?? -1) === 3 && (int) ($import['duplicates'] ?? -1) === 0,
        'three synthetic bank statement lines imported');
    $importReplay = qaRequest($importPath, 'POST', ['csv' => $csv], $makerCookie);
    qaExpect((int) ($importReplay['inserted'] ?? -1) === 0
        && (int) ($importReplay['duplicates'] ?? -1) === 3,
        'bank CSV replay does not create duplicate lines');
    $bankLines = [];
    foreach ($fitids as $key => $fitid) {
        $bankLines[$key] = qaOne($pdo, 'SELECT id, match_status, matched_je_id
            FROM accounting_bank_statement_lines
            WHERE tenant_id = :t AND bank_account_id = :b AND fitid = :fitid',
            ['t' => QA_TENANT, 'b' => $bankId, 'fitid' => $fitid]);
    }
    qaExpect(count(array_filter($bankLines, static fn($line) => $line
        && $line['match_status'] === 'unmatched')) === 3,
        'imported lines start unresolved without posting again');

    $receiptLineId = (int) $bankLines['receipt']['id'];
    $paymentLineId = (int) $bankLines['payment']['id'];
    $treasuryLineId = (int) $bankLines['treasury']['id'];
    $bankJournalCount = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entries
        WHERE tenant_id = :t AND entity_id = :e AND status = "posted"',
        ['t' => QA_TENANT, 'e' => $entityId])['n'];

    $suggestion = qaRequest('/modules/accounting/api/bank_ai.php?action=suggest_match&line_id=' .
        $receiptLineId, 'POST', [], $reviewerCookie);
    qaExpect(in_array((int) $receipt['journal_entry_id'], array_map('intval',
        array_column($suggestion['candidates'] ?? [], 'je_id')), true),
        'incoming bank line offers the existing posted receipt journal');
    $unmatched = qaRequest('/modules/accounting/api/bank_statements.php?bank_account_id=' . $bankId .
        '&match_status=unmatched&per_page=200', 'GET', null, $reviewerCookie);
    $apCandidate = false;
    foreach ($unmatched['rows'] ?? [] as $row) {
        if ((int) $row['id'] !== $paymentLineId) continue;
        foreach ($row['ap_payment_matches'] ?? [] as $candidate) {
            if ((int) $candidate['payment_id'] === $paymentId
                && !empty($candidate['can_clear_and_match'])) $apCandidate = true;
        }
    }
    qaExpect($apCandidate, 'outgoing bank line offers the already-cleared AP payment');

    $receiptMatchPath = '/modules/accounting/api/bank_statements.php?action=match&line_id=' . $receiptLineId;
    $receiptMatch = qaRequest($receiptMatchPath, 'POST',
        ['je_id' => (int) $receipt['journal_entry_id']], $reviewerCookie);
    qaExpect(empty($receiptMatch['idempotent_replay'])
        && (int) ($receiptMatch['je_id'] ?? 0) === (int) $receipt['journal_entry_id'],
        'existing customer receipt matched without another posting');
    $receiptMatchReplay = qaRequest($receiptMatchPath, 'POST',
        ['je_id' => (int) $receipt['journal_entry_id']], $reviewerCookie);
    qaExpect(!empty($receiptMatchReplay['idempotent_replay'])
        && qaAuditCount($pdo, 'accounting.bank.line_matched', $receiptLineId) === 1,
        'receipt match retry reuses the match without duplicate audit');

    $paymentMatchPath = '/modules/accounting/api/bank_statements.php?action=match_ap_payment&line_id=' . $paymentLineId;
    $paymentMatch = qaRequest($paymentMatchPath, 'POST', ['payment_id' => $paymentId], $reviewerCookie);
    qaExpect(empty($paymentMatch['idempotent_replay'])
        && (int) ($paymentMatch['matched_je_id'] ?? 0) === (int) $cleared['journal_entry_id'],
        'first bank match of an already-cleared AP payment is not labeled a replay');
    $paymentMatchReplay = qaRequest($paymentMatchPath, 'POST', ['payment_id' => $paymentId], $reviewerCookie);
    qaExpect(!empty($paymentMatchReplay['idempotent_replay'])
        && qaAuditCount($pdo, 'accounting.bank.ap_payment_matched', $paymentLineId) === 1,
        'exact AP bank-match retry is idempotent without duplicate audit');

    $receiptLine = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $receiptLineId]);
    $paymentLine = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $paymentLineId]);
    $afterBankJournalCount = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entries
        WHERE tenant_id = :t AND entity_id = :e AND status = "posted"',
        ['t' => QA_TENANT, 'e' => $entityId])['n'];
    qaExpect($receiptLine['match_status'] === 'matched'
        && (int) $receiptLine['matched_je_id'] === (int) $receipt['journal_entry_id']
        && $paymentLine['match_status'] === 'matched'
        && (int) $paymentLine['matched_je_id'] === (int) $cleared['journal_entry_id']
        && $afterBankJournalCount === $bankJournalCount
        && qaBalances($pdo, $entityId) === $balances,
        'bank reconciliation reused both source journals without moving the GL');

    $expense = qaOne($pdo, 'SELECT id FROM accounting_accounts
        WHERE tenant_id = :t AND code = "5000" AND active = 1 AND is_postable = 1',
        ['t' => QA_TENANT]);
    qaExpect((int) ($expense['id'] ?? 0) > 0, 'synthetic expense counterpart is available');
    $treasuryPath = '/modules/treasury/api/account_transactions.php?action=categorize_and_post';
    $treasuryBody = ['type' => 'deposit', 'line_id' => $treasuryLineId,
        'counterpart_account_id' => (int) $expense['id'], 'memo' => 'Synthetic bank fee ' . $run];
    $treasuryPost = qaRequest($treasuryPath, 'POST', $treasuryBody, $reviewerCookie);
    $treasuryJournalId = (int) ($treasuryPost['matched_je_id'] ?? 0);
    $treasuryLine = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $treasuryLineId]);
    qaExpect($treasuryJournalId > 0 && $treasuryLine['match_status'] === 'matched'
        && (int) $treasuryLine['matched_je_id'] === $treasuryJournalId
        && qaDelta($balances, qaBalances($pdo, $entityId), QA_BANK_CODE, -40)
        && qaDelta($balances, qaBalances($pdo, $entityId), '5000', 40),
        'Treasury classification posts and reconciles the bank line atomically');

    $correction = qaRequest('/modules/treasury/api/account_transactions.php?action=correct_categorization',
        'POST', ['type' => 'deposit', 'line_id' => $treasuryLineId,
            'reason' => 'Synthetic staging correction proof'], $reviewerCookie);
    $reopened = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $treasuryLineId]);
    qaExpect((int) ($correction['reversal_je_id'] ?? 0) > 0
        && $reopened['match_status'] === 'unmatched' && $reopened['matched_je_id'] === null
        && qaBalances($pdo, $entityId) === $balances,
        'Treasury correction reverses the journal and reopens only its source line');
    $treasuryRebook = qaRequest($treasuryPath, 'POST', $treasuryBody, $reviewerCookie);
    $rebookedLine = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $treasuryLineId]);
    qaExpect((int) ($treasuryRebook['matched_je_id'] ?? 0) > 0
        && (int) $treasuryRebook['matched_je_id'] !== $treasuryJournalId
        && $rebookedLine['match_status'] === 'matched'
        && qaDelta($balances, qaBalances($pdo, $entityId), QA_BANK_CODE, -40)
        && qaDelta($balances, qaBalances($pdo, $entityId), '5000', 40),
        'rebooked Treasury line has a fresh journal and remains reconciled');

    $finalReports = qaReports($entityId, $reviewerCookie);
    qaExpect(qaDelta($afterReports['income_statement'], $finalReports['income_statement'], 'net_income', -40)
        && qaDelta($afterReports['cash_flow_indirect'], $finalReports['cash_flow_indirect'], 'net_change_in_cash', -40)
        && abs((float) ($finalReports['cash_flow_indirect']['reconciliation_diff'] ?? 1)) < 0.005
        && !empty($finalReports['balance_sheet']['balanced']),
        'corrected and rebooked bank expense reaches balanced reports exactly once');
    $remaining = qaRequest('/modules/accounting/api/bank_statements.php?bank_account_id=' . $bankId .
        '&match_status=unmatched&per_page=200', 'GET', null, $reviewerCookie);
    $remainingIds = array_map('intval', array_column($remaining['rows'] ?? [], 'id'));
    qaExpect(!in_array($receiptLineId, $remainingIds, true)
        && !in_array($paymentLineId, $remainingIds, true)
        && !in_array($treasuryLineId, $remainingIds, true),
        'matched synthetic lines no longer appear in the bank review queue');
    echo json_encode(['run' => $run, 'entity_id' => $entityId, 'bank_account_id' => $bankId,
        'invoice_id' => $invoiceId, 'bill_id' => $billId, 'receipt_id' => $receipt['id'],
        'payment_id' => $paymentId, 'bank_line_ids' => array_map('intval', array_column($bankLines, 'id')),
        'balances' => qaBalances($pdo, $entityId)], JSON_PRETTY_PRINT), "\n";
} finally {
    foreach ($cookies as $cookie) if (is_string($cookie) && file_exists($cookie)) unlink($cookie);
    foreach ([$maker, $reviewer] as $actor) {
        if ($actor && isset($actor['id'])) {
            $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
                WHERE id = :id AND tenant_id = :t')
                ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
        }
    }
}
