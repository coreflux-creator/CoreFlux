<?php
/** Two-entity maker/checker accounting acceptance; synthetic staging only. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

if (!defined('COREFLUX_STAGING') || !COREFLUX_STAGING) {
    throw new RuntimeException('Refusing a non-staging environment.');
}

function multiEntityPostStatus(string $path, array $body, string $cookie): int
{
    $curl = curl_init(QA_BASE_URL . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json',
            'X-CoreFlux-Tenant-Id: ' . QA_TENANT],
    ]);
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) throw new RuntimeException("POST {$path}: {$error}");
    return $status;
}

$cutover = qaOne($pdo, 'SELECT id FROM accounting_entities
    WHERE tenant_id = :t AND code = "SIM-CUTOVER-QA" AND active = 1', ['t' => QA_TENANT]);
$lifecycle = qaOne($pdo, 'SELECT id FROM accounting_entities
    WHERE tenant_id = :t AND code = "SIM-LIFECYCLE-QA" AND active = 1', ['t' => QA_TENANT]);
if (!$cutover || !$lifecycle) throw new RuntimeException('Synthetic staging entities are missing.');
$entityId = (int) $cutover['id'];
$otherEntityId = (int) $lifecycle['id'];

$maker = null;
$reviewer = null;
$cookies = [];
try {
    $maker = qaEnsureActor($pdo, 'cross-entity-maker');
    $reviewer = qaEnsureActor($pdo, 'cross-entity-reviewer');
    $makerCookie = tempnam(sys_get_temp_dir(), 'cf-cross-maker-');
    $reviewerCookie = tempnam(sys_get_temp_dir(), 'cf-cross-review-');
    $cookies = array_values(array_filter([$makerCookie, $reviewerCookie], 'is_string'));
    if ($makerCookie === false || $reviewerCookie === false) {
        throw new RuntimeException('Could not create isolated test sessions.');
    }
    qaLogin($maker, $makerCookie);
    qaLogin($reviewer, $reviewerCookie);

    $policyName = 'Synthetic cross-entity QA review';
    $policy = qaOne($pdo, 'SELECT id FROM ap_approval_policies
        WHERE tenant_id = :t AND entity_id = :e AND name = :name',
        ['t' => QA_TENANT, 'e' => $entityId, 'name' => $policyName]);
    $chain = json_encode([['step' => 1, 'approver_user_ids' => [(int) $reviewer['id']],
        'quorum' => 1, 'label' => 'Independent synthetic reviewer']], JSON_THROW_ON_ERROR);
    if ($policy) {
        $pdo->prepare('UPDATE ap_approval_policies SET chain_json = :chain, active = 1
            WHERE id = :id AND tenant_id = :t AND entity_id = :e')
            ->execute(['chain' => $chain, 'id' => $policy['id'],
                't' => QA_TENANT, 'e' => $entityId]);
    } else {
        $pdo->prepare('INSERT INTO ap_approval_policies
            (tenant_id, name, priority, entity_id, chain_json, active)
            VALUES (:t, :name, 1, :e, :chain, 1)')
            ->execute(['t' => QA_TENANT, 'name' => $policyName,
                'e' => $entityId, 'chain' => $chain]);
    }

    $before = qaBalances($pdo, $entityId);
    $otherBefore = qaBalances($pdo, $otherEntityId);
    $reportsBefore = qaReports($entityId, $reviewerCookie);
    $run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $date = date('Y-m-d');
    $due = date('Y-m-d', strtotime($date . ' +30 days'));
    $invoice = qaRequest('/modules/billing/api/invoices.php', 'POST', [
        'entity_id' => $entityId, 'client_name' => 'Synthetic Cross Client ' . $run,
        'issue_date' => $date, 'due_date' => $due, 'currency' => 'USD', 'tax_rate_pct' => 0,
        'notes_internal' => 'Synthetic cross-entity accounting ' . $run,
        'lines' => [['description' => 'Invented accounting service', 'quantity' => 1,
            'unit' => 'each', 'unit_price' => 101, 'item_type' => 'fixed_fee',
            'gl_revenue_account_code' => '4000']],
    ], $makerCookie);
    $invoiceId = (int) ($invoice['id'] ?? 0);
    qaExpect($invoiceId > 0, 'second-entity invoice drafted by maker');
    $bill = qaRequest('/modules/ap/api/bills.php', 'POST', [
        'entity_id' => $entityId, 'vendor_name' => 'Synthetic Cross Vendor ' . $run,
        'vendor_type' => 'other', 'bill_number' => 'CROSS-' . $run,
        'bill_date' => $date, 'received_at' => $date, 'due_date' => $due,
        'currency' => 'USD', 'tax_rate_pct' => 0,
        'notes_internal' => 'Synthetic cross-entity accounting ' . $run,
        'lines' => [['description' => 'Invented supplier cost', 'quantity' => 1,
            'unit' => 'each', 'unit_price' => 41, 'item_type' => 'expense',
            'gl_expense_account_code' => '5000']],
    ], $makerCookie);
    $billId = (int) ($bill['id'] ?? 0);
    qaExpect($billId > 0, 'second-entity bill drafted by maker');

    qaRequest('/modules/billing/api/invoices.php?action=request_approval&id=' . $invoiceId,
        'POST', [], $makerCookie);
    qaRequest('/modules/billing/api/approval_assignment.php', 'POST', [
        'invoice_id' => $invoiceId, 'reviewer_user_ids' => [(int) $reviewer['id']],
    ], $makerCookie);
    qaExpect(multiEntityPostStatus('/modules/billing/api/invoices.php?action=approve&id=' . $invoiceId,
        [], $makerCookie) === 403, 'maker cannot approve own invoice');
    qaExpect(multiEntityPostStatus('/modules/ap/api/bills.php?action=approve&id=' . $billId,
        [], $makerCookie) === 403, 'maker cannot approve own bill');
    $approvedInvoice = qaRequest('/modules/billing/api/invoices.php?action=approve&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    $approvedBill = qaRequest('/modules/ap/api/bills.php?action=approve&id=' . $billId,
        'POST', [], $reviewerCookie);
    qaExpect(!empty($approvedInvoice['approved'])
        && ($approvedBill['workflow_status'] ?? '') === 'approved',
        'independent reviewer approved both source documents');

    $invoicePost = qaRequest('/modules/billing/api/invoices.php?action=post&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    $billPost = qaRequest('/modules/ap/api/bills.php?action=post&id=' . $billId,
        'POST', [], $reviewerCookie);
    $invoiceReplay = qaRequest('/modules/billing/api/invoices.php?action=post&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    $billReplay = qaRequest('/modules/ap/api/bills.php?action=post&id=' . $billId,
        'POST', [], $reviewerCookie);
    qaExpect((int) ($invoicePost['journal_entry_id'] ?? 0) > 0
        && (int) ($billPost['journal_entry_id'] ?? 0) > 0
        && !empty($invoiceReplay['idempotent_replay'])
        && !empty($billReplay['idempotent_replay']),
        'both documents posted once through the canonical journal');

    $sourceLinks = qaOne($pdo, 'SELECT i.entity_id AS invoice_entity, b.entity_id AS bill_entity,
        ij.entity_id AS invoice_journal_entity, bj.entity_id AS bill_journal_entity,
        i.sent_at AS invoice_sent_at, i.amount_due AS invoice_due, b.amount_due AS bill_due
        FROM billing_invoices i
        JOIN ap_bills b ON b.tenant_id = i.tenant_id AND b.id = :bill_id
        JOIN accounting_journal_entries ij ON ij.tenant_id = i.tenant_id AND ij.id = i.journal_entry_id
        JOIN accounting_journal_entries bj ON bj.tenant_id = b.tenant_id AND bj.id = b.journal_entry_id
        WHERE i.tenant_id = :t AND i.id = :invoice_id',
        ['bill_id' => $billId, 't' => QA_TENANT, 'invoice_id' => $invoiceId]);
    qaExpect($sourceLinks && (int) $sourceLinks['invoice_entity'] === $entityId
        && (int) $sourceLinks['bill_entity'] === $entityId
        && (int) $sourceLinks['invoice_journal_entity'] === $entityId
        && (int) $sourceLinks['bill_journal_entity'] === $entityId
        && $sourceLinks['invoice_sent_at'] === null
        && abs((float) $sourceLinks['invoice_due'] - 101) < 0.005
        && abs((float) $sourceLinks['bill_due'] - 41) < 0.005,
        'documents and journals share the intended entity, with no delivery or payment');

    $after = qaBalances($pdo, $entityId);
    qaExpect(qaDelta($before, $after, '1100', 101)
        && qaDelta($before, $after, '4000', -101)
        && qaDelta($before, $after, '5000', 41)
        && qaDelta($before, $after, '2000', -41)
        && abs(array_sum($after)) < 0.005,
        'entity GL has balanced AR, AP, revenue and expense movements');
    qaExpect(qaBalances($pdo, $otherEntityId) === $otherBefore,
        'the first entity has no GL movement');

    $reportsAfter = qaReports($entityId, $reviewerCookie);
    qaExpect(qaDelta($reportsBefore['income_statement'], $reportsAfter['income_statement'],
        'total_revenue', 101)
        && qaDelta($reportsBefore['income_statement'], $reportsAfter['income_statement'],
            'total_expense', 41)
        && qaDelta($reportsBefore['income_statement'], $reportsAfter['income_statement'],
            'net_income', 60), 'entity income statement agrees with documents');
    qaExpect(qaDelta($reportsBefore['balance_sheet'], $reportsAfter['balance_sheet'],
        'total_assets', 101)
        && qaDelta($reportsBefore['balance_sheet'], $reportsAfter['balance_sheet'],
            'total_liabilities', 41)
        && qaDelta($reportsBefore['balance_sheet'], $reportsAfter['balance_sheet'],
            'total_equity', 60)
        && !empty($reportsAfter['balance_sheet']['balanced']),
        'entity balance sheet is balanced after posting');
    qaExpect(qaDelta($reportsBefore['cash_flow_indirect'], $reportsAfter['cash_flow_indirect'],
        'net_change_in_cash', 0)
        && !empty($reportsAfter['cash_flow_indirect']['balanced']),
        'no cash flow is invented before collection or payment');

    $ownList = qaRequest('/modules/billing/api/invoices.php?entity_id=' . $entityId,
        'GET', null, $reviewerCookie);
    $otherList = qaRequest('/modules/billing/api/invoices.php?entity_id=' . $otherEntityId,
        'GET', null, $reviewerCookie);
    qaExpect(in_array($invoiceId, array_map('intval', array_column($ownList['rows'], 'id')), true)
        && !in_array($invoiceId, array_map('intval', array_column($otherList['rows'], 'id')), true),
        'new invoice appears only in the correct company worklist');

    echo json_encode(['run' => $run, 'entity_id' => $entityId,
        'invoice_id' => $invoiceId, 'bill_id' => $billId,
        'invoice_journal_id' => (int) $invoicePost['journal_entry_id'],
        'bill_journal_id' => (int) $billPost['journal_entry_id']], JSON_PRETTY_PRINT), "\n";
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
