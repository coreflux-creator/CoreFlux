<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$apLib = $read('modules/ap/lib/ap.php');
$apApi = $read('modules/ap/api/payments.php');
$bankLib = $read('modules/accounting/lib/bank_rec.php');
$bankApi = $read('modules/accounting/api/bank_statements.php');
$bankUi = $read('modules/accounting/ui/BankReconciliation.jsx');
$treasuryApi = $read('modules/treasury/api/account_transactions.php');
$treasuryUi = $read('modules/treasury/ui/AccountTransactions.jsx');

echo "Shared AP clearing path\n";
$check('AP clearing is a reusable library operation', str_contains($apLib, 'function apClearPayment('));
$check('AP screen delegates to the shared clearing operation', str_contains($apApi, 'apClearPayment($tid, $id'));
$check('clearing is idempotent only with a valid journal',
    str_contains($apLib, '(($row[\'status\'] ?? \'\') === \'cleared\'')
    && str_contains($apLib, 'has no valid ledger posting'));
$check('AP clearing locks payment and owns its journal transaction',
    str_contains($apLib, 'function apClearPaymentLocked(')
    && str_contains($apLib, 'FROM ap_payments WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE')
    && str_contains($apLib, 'cf_tx_commit($pdo, $ownsTransaction)')
    && str_contains($apLib, 'cf_tx_rollback($pdo, $ownsTransaction)'));
$check('ordinary void refuses payment ledger activity under lock',
    str_contains($apApi, 'apPaymentHasLedgerActivity($pdo, $tid, $row)')
    && str_contains($apApi, 'Cleared payments need a linked accounting and bank correction')
    && str_contains($apApi, 'ap_payments WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE'));
$check('clearing requires a real entity bank account',
    str_contains($apLib, 'Connect or create an active bank account for this entity before clearing the payment.'));
$check('payment and bank currencies must agree',
    str_contains($apLib, 'The funding bank account uses a different currency.'));

echo "\nBank/AP matching\n";
$check('outgoing bank lines preload AP payment candidates',
    str_contains($bankLib, 'function bankRecAttachApPaymentSuggestions(')
    && str_contains($bankApi, 'bankRecAttachApPaymentSuggestions('));
$check('only released or cleared, fully allocated payments are candidates',
    str_contains($bankLib, 'p.status IN ("sent", "cleared")')
    && str_contains($bankLib, 'p.unallocated_amount <= 0.005'));
$check('candidate matching is exact-amount, bank, currency, entity, and date scoped',
    str_contains($bankLib, 'ROUND(p.amount, 2) IN')
    && str_contains($bankLib, 'p.currency = :bank_currency')
    && str_contains($bankLib, 'p.bank_account_id IS NULL OR p.bank_account_id = :bank_account_id')
    && str_contains($bankLib, 'p.pay_date BETWEEN :date_from AND :date_to'));
$check('AP payment match endpoint exists with both permissions',
    str_contains($bankApi, "action === 'match_ap_payment'")
    && str_contains($bankApi, "'accounting.bank.manage'")
    && str_contains($bankApi, "'ap.payment.send'"));
$check('endpoint validates direction, amount, currency, entity, and funding account',
    str_contains($bankApi, 'Only outgoing bank lines can clear AP payments')
    && str_contains($bankApi, 'does not equal this bank debit')
    && str_contains($bankApi, 'uses a different currency')
    && str_contains($bankApi, 'belongs to a different entity')
    && str_contains($bankApi, 'released from a different bank account'));
$check('endpoint clears once and then reconciles the resulting journal',
    str_contains($bankApi, 'apClearPayment(')
    && str_contains($bankApi, 'bankRecMatchLine(')
    && str_contains($bankApi, 'accounting.bank.ap_payment_matched'));
$check('exact AP match retry is allowed only for its cleared payment journal',
    str_contains($bankApi, "\$sameMatch = (\$line['match_status'] ?? '') === 'matched'")
    && str_contains($bankApi, "(\$payment['status'] ?? '') === 'cleared'")
    && str_contains($bankApi, "(int) (\$line['matched_je_id'] ?? 0) === (int) \$payment['journal_entry_id']"));
$check('bank-match replay reflects the line transition, not a prior AP clear',
    str_contains($bankApi, "'idempotent_replay' => !empty(\$match['idempotent_replay'])")
    && str_contains($bankApi, "if (empty(\$match['idempotent_replay'])) {"));
$check('AP clear retry does not duplicate its business audit event',
    str_contains($apApi, "if (empty(\$result['idempotent_replay'])) {")
    && str_contains($apApi, "apAudit('ap.payment.cleared'"));
$matchAction = substr($bankApi, (int) strpos($bankApi, "if (\$method === 'POST' && \$action === 'match_ap_payment')"), 6000);
$check('AP clear and bank match commit or roll back together',
    str_contains($matchAction, 'cf_tx_begin($pdo)')
    && str_contains($matchAction, 'cf_tx_commit($pdo, $ownsTransaction)')
    && str_contains($matchAction, 'cf_tx_rollback($pdo, $ownsTransaction)'));

echo "\nReconciliation integrity\n";
$check('manual journal match requires a posted journal', str_contains($bankLib, 'Only a posted journal entry can be matched'));
$matchFunction = substr($bankLib, (int) strpos($bankLib, 'function bankRecMatchLine('), 4200);
$journalLock = strpos($matchFunction, 'accounting_journal_entries') !== false
    ? strpos($matchFunction, 'WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE') : false;
$bankLineLock = strpos($matchFunction, 'WHERE bl.tenant_id = :tenant_id AND bl.id = :id FOR UPDATE');
$check('manual match serializes journal reversal before locking the bank line',
    str_contains($matchFunction, 'cf_tx_begin($pdo)')
    && $journalLock !== false && $bankLineLock !== false && $journalLock < $bankLineLock
    && str_contains($matchFunction, 'cf_tx_commit($pdo, $ownsTransaction)')
    && str_contains($matchFunction, 'cf_tx_rollback($pdo, $ownsTransaction)'));
$check('manual journal match validates signed bank movement',
    str_contains($bankLib, 'line.debit - line.credit')
    && str_contains($bankLib, 'does not contain the matching cash movement'));
$check('manual journal match rejects currency, entity, and duplicate-use conflicts',
    str_contains($bankLib, 'journal entry belongs to a different entity')
    && str_contains($bankLib, 'journal entry uses a different currency')
    && str_contains($bankLib, 'already matched to another bank line'));
$check('line match uses a conditional write to prevent double resolution',
    str_contains($bankLib, 'AND match_status = "unmatched"')
    && str_contains($bankLib, 'changed while it was being matched'));
$check('line-created journals cannot be silently detached',
    str_contains($bankLib, 'function bankRecUnmatchBlocker(')
    && str_contains($bankLib, 'Correct the source transaction to keep the books in sync.'));
$check('Treasury bulk unmatch routes through the same guard',
    str_contains($treasuryApi, 'bankRecUnmatchLine($tenantId, $eligibleId)')
    && str_contains($treasuryUi, 'Transactions created from a bank line must be reversed instead.'));

echo "\nOperator workflow\n";
$check('bank row highlights a likely AP payment',
    str_contains($bankUi, 'accounting-bank-line-ap-payment-match-')
    && str_contains($bankUi, 'Match payment'));
$check('operator can clear and match in one action',
    str_contains($bankUi, 'Clear & match')
    && str_contains($bankUi, 'match_ap_payment&line_id='));
$check('receipt action is described directly', str_contains($bankUi, 'Apply receipt'));

echo "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
