<?php
/** Resume an interrupted bank-reconciliation proof for synthetic staging records only. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute'
    || !preg_match('/^--run=(\d{14}-[a-f0-9]{6})$/', (string) ($argv[2] ?? ''), $runMatch)) {
    fwrite(STDERR, "Run with --execute --run=YYYYMMDDHHMMSS-abcdef on isolated staging only.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require __DIR__ . '/accounting_staging_lifecycle.php';

$run = $runMatch[1];
$entity = qaOne($pdo, 'SELECT id FROM accounting_entities WHERE tenant_id = :t AND code = :code',
    ['t' => QA_TENANT, 'code' => QA_ENTITY_CODE]);
$entityId = (int) ($entity['id'] ?? 0);
$bank = qaOne($pdo, 'SELECT id FROM accounting_bank_accounts
    WHERE tenant_id = :t AND entity_id = :e AND gl_account_code = :code',
    ['t' => QA_TENANT, 'e' => $entityId, 'code' => QA_BANK_CODE]);
$bankId = (int) ($bank['id'] ?? 0);
$receipt = qaOne($pdo, 'SELECT id, journal_entry_id, amount FROM billing_payments
    WHERE tenant_id = :t AND bank_account_id = :bank AND reference = :reference',
    ['t' => QA_TENANT, 'bank' => $bankId, 'reference' => 'SIM-RECEIPT-' . $run]);
$payment = qaOne($pdo, 'SELECT id, journal_entry_id, amount, status FROM ap_payments
    WHERE tenant_id = :t AND bank_account_id = :bank AND reference = :reference',
    ['t' => QA_TENANT, 'bank' => $bankId, 'reference' => 'SIM-PAYMENT-' . $run]);
$lineIds = [];
foreach (['receipt' => 'RECEIPT', 'payment' => 'PAYMENT', 'treasury' => 'CHARGE'] as $key => $type) {
    $line = qaOne($pdo, 'SELECT id, match_status, matched_je_id, amount
        FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND bank_account_id = :bank AND fitid = :fitid',
        ['t' => QA_TENANT, 'bank' => $bankId, 'fitid' => 'SIM-BANK-' . $type . '-' . $run]);
    if (!$line) throw new RuntimeException("Missing synthetic {$key} bank line for {$run}");
    $lineIds[$key] = (int) $line['id'];
    $lineRows[$key] = $line;
}
qaExpect($entityId > 0 && $bankId > 0 && $receipt && $payment
    && abs((float) $receipt['amount'] - 400) < 0.005
    && abs((float) $payment['amount'] - 250) < 0.005
    && $payment['status'] === 'cleared'
    && (float) $lineRows['receipt']['amount'] === 400.0
    && (float) $lineRows['payment']['amount'] === -250.0
    && (float) $lineRows['treasury']['amount'] === -40.0,
    'recovery is limited to the expected synthetic entity, bank and amounts');

$maker = null;
$reviewer = null;
$cookies = [];
try {
    $maker = qaEnsureActor($pdo, 'maker');
    $reviewer = qaEnsureActor($pdo, 'reviewer');
    $makerCookie = tempnam(sys_get_temp_dir(), 'cf-maker-');
    $reviewerCookie = tempnam(sys_get_temp_dir(), 'cf-review-');
    $cookies = [$makerCookie, $reviewerCookie];
    qaLogin($maker, $makerCookie);
    qaLogin($reviewer, $reviewerCookie);

    $receiptLineId = $lineIds['receipt'];
    $paymentLineId = $lineIds['payment'];
    $treasuryLineId = $lineIds['treasury'];
    $baseline = qaBalances($pdo, $entityId);
    $reportsBefore = qaReports($entityId, $reviewerCookie);
    $journalsBefore = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entries
        WHERE tenant_id = :t AND entity_id = :e AND status = "posted"',
        ['t' => QA_TENANT, 'e' => $entityId])['n'];

    $oldAuditCount = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM audit_log
        WHERE event = :event AND target_id = :id',
        ['event' => 'accounting.bank.line_matched', 'id' => $receiptLineId])['n'];
    $receiptRetry = qaRequest('/modules/accounting/api/bank_statements.php?action=match&line_id=' .
        $receiptLineId, 'POST', ['je_id' => (int) $receipt['journal_entry_id']], $reviewerCookie);
    $newAuditCount = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM audit_log
        WHERE event = :event AND target_id = :id',
        ['event' => 'accounting.bank.line_matched', 'id' => $receiptLineId])['n'];
    qaExpect(!empty($receiptRetry['idempotent_replay'])
        && $oldAuditCount === 1 && $newAuditCount === 1,
        'earlier matched receipt stays idempotent despite its pre-fix audit context');

    $auditFitid = 'SIM-BANK-AUDIT-' . $run;
    $auditLine = qaOne($pdo, 'SELECT id, match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND bank_account_id = :bank AND fitid = :fitid',
        ['t' => QA_TENANT, 'bank' => $bankId, 'fitid' => $auditFitid]);
    if ($auditLine && $auditLine['match_status'] === 'matched') {
        $priorReceipt = qaOne($pdo, 'SELECT id, journal_entry_id FROM billing_payments
            WHERE tenant_id = :t AND bank_account_id = :bank AND journal_entry_id = :je
              AND reference LIKE :prefix AND amount = 400',
            ['t' => QA_TENANT, 'bank' => $bankId, 'je' => (int) $auditLine['matched_je_id'],
                'prefix' => 'SIM-RECEIPT-%']);
    } else {
        $priorReceipt = qaOne($pdo, 'SELECT bp.id, bp.journal_entry_id
            FROM billing_payments bp
            LEFT JOIN accounting_bank_statement_lines bl
              ON bl.tenant_id = bp.tenant_id AND bl.matched_je_id = bp.journal_entry_id
            WHERE bp.tenant_id = :t AND bp.bank_account_id = :bank
              AND bp.reference LIKE :prefix AND bp.amount = 400
              AND bp.id <> :current AND bp.journal_entry_id IS NOT NULL AND bl.id IS NULL
            ORDER BY bp.id DESC LIMIT 1',
            ['t' => QA_TENANT, 'bank' => $bankId, 'prefix' => 'SIM-RECEIPT-%',
                'current' => (int) $receipt['id']]);
    }
    qaExpect((int) ($priorReceipt['journal_entry_id'] ?? 0) > 0,
        'a synthetic receipt journal is available for audit verification');
    if (!$auditLine) {
        $auditCsv = qaCsv([
            ['Date', 'Description', 'Amount', 'Transaction ID'],
            ['2026-10-07', 'Synthetic QA audit receipt ' . $run, '400.00', $auditFitid],
        ]);
        $auditImport = qaRequest('/modules/accounting/api/bank_statements.php?action=import_csv&bank_account_id=' .
            $bankId, 'POST', ['csv' => $auditCsv], $makerCookie);
        qaExpect((int) ($auditImport['inserted'] ?? -1) === 1,
            'one new synthetic bank line imported for audit verification');
        $auditLine = qaOne($pdo, 'SELECT id, match_status, matched_je_id FROM accounting_bank_statement_lines
            WHERE tenant_id = :t AND bank_account_id = :bank AND fitid = :fitid',
            ['t' => QA_TENANT, 'bank' => $bankId, 'fitid' => $auditFitid]);
    }
    $auditLineId = (int) ($auditLine['id'] ?? 0);
    qaExpect($auditLineId > 0 && in_array($auditLine['match_status'], ['unmatched', 'matched'], true),
        'synthetic audit-proof line is available');
    $auditPath = '/modules/accounting/api/bank_statements.php?action=match&line_id=' . $auditLineId;
    $auditMatch = qaRequest($auditPath, 'POST',
        ['je_id' => (int) $priorReceipt['journal_entry_id']], $reviewerCookie);
    $auditRetry = qaRequest($auditPath, 'POST',
        ['je_id' => (int) $priorReceipt['journal_entry_id']], $reviewerCookie);
    $audit = qaOne($pdo, 'SELECT tenant_id, actor_user_id FROM audit_log
        WHERE event = :event AND target_id = :id ORDER BY id DESC LIMIT 1',
        ['event' => 'accounting.bank.line_matched', 'id' => $auditLineId]);
    qaExpect(!empty($auditMatch['idempotent_replay']) === ($auditLine['match_status'] === 'matched')
        && !empty($auditRetry['idempotent_replay'])
        && qaAuditCount($pdo, 'accounting.bank.line_matched', $auditLineId) === 1
        && (int) ($audit['tenant_id'] ?? 0) === QA_TENANT
        && (int) ($audit['actor_user_id'] ?? 0) === (int) $reviewer['id'],
        'fresh bank match records one tenant-scoped, actor-attributed audit event');

    $apPath = '/modules/accounting/api/bank_statements.php?action=match_ap_payment&line_id=' . $paymentLineId;
    $apMatch = qaRequest($apPath, 'POST', ['payment_id' => (int) $payment['id']], $reviewerCookie);
    $apRetry = qaRequest($apPath, 'POST', ['payment_id' => (int) $payment['id']], $reviewerCookie);
    $apAudit = qaOne($pdo, 'SELECT tenant_id, actor_user_id FROM audit_log
        WHERE event = :event AND target_id = :id ORDER BY id DESC LIMIT 1',
        ['event' => 'accounting.bank.ap_payment_matched', 'id' => $paymentLineId]);
    qaExpect(!empty($apMatch['idempotent_replay']) === ($lineRows['payment']['match_status'] === 'matched')
        && !empty($apRetry['idempotent_replay'])
        && (int) ($apMatch['matched_je_id'] ?? 0) === (int) $payment['journal_entry_id']
        && qaAuditCount($pdo, 'accounting.bank.ap_payment_matched', $paymentLineId) === 1
        && (int) ($apAudit['tenant_id'] ?? 0) === QA_TENANT
        && (int) ($apAudit['actor_user_id'] ?? 0) === (int) $reviewer['id'],
        'AP bank match and retry preserve one tenant-scoped audit event');
    qaExpect(qaBalances($pdo, $entityId) === $baseline
        && (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entries
            WHERE tenant_id = :t AND entity_id = :e AND status = "posted"',
            ['t' => QA_TENANT, 'e' => $entityId])['n'] === $journalsBefore,
        'receipt and AP bank matches create no second journal or GL movement');

    $expense = qaOne($pdo, 'SELECT id FROM accounting_accounts
        WHERE tenant_id = :t AND code = "5000" AND active = 1 AND is_postable = 1',
        ['t' => QA_TENANT]);
    qaExpect((int) ($expense['id'] ?? 0) > 0,
        'synthetic expense counterpart is available');
    $treasuryPath = '/modules/treasury/api/account_transactions.php?action=categorize_and_post';
    $treasuryBody = ['type' => 'deposit', 'line_id' => $treasuryLineId,
        'counterpart_account_id' => (int) $expense['id'], 'memo' => 'Synthetic bank fee ' . $run];
    $priorCorrection = qaOne($pdo, 'SELECT original_je_id, reversal_je_id
        FROM treasury_statement_corrections
        WHERE tenant_id = :t AND line_type = "deposit" AND line_id = :id
        ORDER BY attempt_no DESC LIMIT 1', ['t' => QA_TENANT, 'id' => $treasuryLineId]);
    $firstJe = (int) ($priorCorrection['original_je_id'] ?? 0);
    if ($priorCorrection) {
        $original = qaOne($pdo, 'SELECT status, reversed_by_je_id FROM accounting_journal_entries
            WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $firstJe]);
        $reversal = qaOne($pdo, 'SELECT status FROM accounting_journal_entries
            WHERE tenant_id = :t AND id = :id',
            ['t' => QA_TENANT, 'id' => (int) $priorCorrection['reversal_je_id']]);
        qaExpect($lineRows['treasury']['match_status'] === 'unmatched'
            && $original['status'] === 'reversed' && $reversal['status'] === 'posted'
            && (int) $original['reversed_by_je_id'] === (int) $priorCorrection['reversal_je_id'],
            'previous Treasury correction has a paired reversal and an open source line');
    } else {
        $post = qaRequest($treasuryPath, 'POST', $treasuryBody, $reviewerCookie);
        $firstJe = (int) ($post['matched_je_id'] ?? 0);
        $line = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
            WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $treasuryLineId]);
        qaExpect($firstJe > 0 && $line['match_status'] === 'matched'
            && (int) $line['matched_je_id'] === $firstJe
            && qaDelta($baseline, qaBalances($pdo, $entityId), QA_BANK_CODE, -40)
            && qaDelta($baseline, qaBalances($pdo, $entityId), '5000', 40),
            'Treasury classification posts and matches once');
        $correction = qaRequest('/modules/treasury/api/account_transactions.php?action=correct_categorization',
            'POST', ['type' => 'deposit', 'line_id' => $treasuryLineId,
                'reason' => 'Synthetic staging correction proof'], $reviewerCookie);
        $line = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
            WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $treasuryLineId]);
        qaExpect((int) ($correction['reversal_je_id'] ?? 0) > 0
            && $line['match_status'] === 'unmatched' && $line['matched_je_id'] === null
            && qaBalances($pdo, $entityId) === $baseline,
            'Treasury correction reverses the entry and reopens the bank line');
    }
    $rebook = qaRequest($treasuryPath, 'POST', $treasuryBody, $reviewerCookie);
    $line = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $treasuryLineId]);
    qaExpect((int) ($rebook['matched_je_id'] ?? 0) > 0
        && (int) $rebook['matched_je_id'] !== $firstJe && $line['match_status'] === 'matched'
        && qaDelta($baseline, qaBalances($pdo, $entityId), QA_BANK_CODE, -40)
        && qaDelta($baseline, qaBalances($pdo, $entityId), '5000', 40),
        'Treasury rebook makes a fresh balanced entry without duplicate expense');

    $reportsAfter = qaReports($entityId, $reviewerCookie);
    qaExpect(qaDelta($reportsBefore['income_statement'], $reportsAfter['income_statement'], 'net_income', -40)
        && qaDelta($reportsBefore['cash_flow_indirect'], $reportsAfter['cash_flow_indirect'], 'net_change_in_cash', -40)
        && abs((float) ($reportsAfter['cash_flow_indirect']['reconciliation_diff'] ?? 1)) < 0.005
        && !empty($reportsAfter['balance_sheet']['balanced']),
        'income statement, balance sheet and cash flow reflect the corrected bank fee once');
    $open = qaRequest('/modules/accounting/api/bank_statements.php?bank_account_id=' . $bankId .
        '&match_status=unmatched&per_page=200', 'GET', null, $reviewerCookie);
    $openIds = array_map('intval', array_column($open['rows'] ?? [], 'id'));
    qaExpect(!array_intersect([$receiptLineId, $paymentLineId, $treasuryLineId, $auditLineId], $openIds),
        'all recovered synthetic lines leave the bank review queue');
    echo json_encode(['run' => $run, 'bank_line_ids' => $lineIds,
        'audit_proof_line_id' => $auditLineId, 'balances' => qaBalances($pdo, $entityId)],
        JSON_PRETTY_PRINT), "\n";
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
