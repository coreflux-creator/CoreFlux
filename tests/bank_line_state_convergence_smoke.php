<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$passed = 0; $failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? "  OK  " : "  FAIL ") . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$lib = (string) file_get_contents($root . '/modules/accounting/lib/bank_rec.php');
$bankApi = (string) file_get_contents($root . '/modules/accounting/api/bank_statements.php');
$treasury = (string) file_get_contents($root . '/modules/treasury/api/account_transactions.php');
$integrity = (string) file_get_contents($root . '/core/business_integrity.php');
$bankIntegrityApi = (string) file_get_contents($root . '/modules/accounting/api/bank_integrity.php');
$bankIntegrityUi = (string) file_get_contents($root . '/modules/accounting/ui/BankIntegrityReview.jsx');
$bankRecUi = (string) file_get_contents($root . '/modules/accounting/ui/BankReconciliation.jsx');

$check('shared match transition exists', str_contains($lib, 'function bankRecMarkLineMatched('));
$check('shared transition records match metadata', str_contains($lib, 'matched_at = NOW()') && str_contains($lib, 'matched_by_user_id = :user_id'));
$check('historical repair exists', str_contains($lib, 'function bankRecRepairPostedMatches('));
$check('bank reconciliation loads canonical transaction identity helpers', str_contains($bankApi, 'bank_transaction_identity.php'));
$check('bank reconciliation excludes duplicate audit copies', str_contains($bankApi, 'duplicate_of_line_id IS NULL'));
$check('repair only uses posted journals', substr_count($lib, 'je.status = "posted"') >= 3);
$check('repair recognizes direct Treasury source lineage', str_contains($lib, 'je.source_module = "treasury_feed"') && str_contains($lib, 'je.source_ref_type = "bank_statement_line"'));
$check('repair recognizes regular and split subledger lineage', str_contains($lib, 'CONCAT("bank_line:", bl.id)') && str_contains($lib, 'CONCAT("bank_line:split:", bl.id)'));
$check('repair ignores reversed Treasury links after a correction',
    str_contains($lib, 'AND sl.link_kind = "primary"'));
$repairStart = strpos($lib, 'function bankRecRepairPostedMatches(');
$repairBody = $repairStart === false ? '' : substr($lib, $repairStart, 5000);
$check('repair does not use fuzzy amount/date matching', !str_contains($repairBody, 'ABS(l.debit') && !str_contains($repairBody, 'DATE_SUB'));
$check('repair validates the journal before changing bank-line state',
    str_contains($repairBody, 'bankRecMatchLine($tenantId, $lineId, $jeIds[0], null)')
    && !str_contains($repairBody, 'bankRecMarkLineMatched($tenantId, $lineId'));
$check('repair refuses ambiguous posted lineage',
    str_contains($repairBody, 'count($jeIds) !== 1')
    && str_contains($repairBody, "'conflicts' => \$conflicts"));
$rulesStart = strpos($lib, 'function bankRecApplyRules(');
$rulesBody = $rulesStart === false ? '' : substr($lib, $rulesStart, 4500);
$check('bank rules leave conflicted posted lines for ledger review',
    str_contains($rulesBody, 'bankRecRepairPostedMatches($tenantId, $bankAccountId)')
    && str_contains($rulesBody, "isset(\$conflicts[(int) \$l['id']])")
    && str_contains($rulesBody, "'lineage_conflict_count' => count(\$conflicts)"));
$check('read-only integrity audit compares cash movement and currency',
    str_contains($integrity, "'bank_match_integrity'")
    && str_contains($integrity, 'journal_line.debit - journal_line.credit')
    && str_contains($integrity, "COALESCE(NULLIF(bank.currency, ''), 'USD')"));
$check('read-only integrity audit detects duplicate and unresolved bank lineage',
    str_contains($integrity, "'bank_duplicate_journal_matches'")
    && str_contains($integrity, "'bank_unmatched_explicit_lineage'")
    && str_contains($integrity, "link.link_kind = 'primary'"));
$check('bank review reuses the shared read-only audit with accounting access control',
    str_contains($integrity, 'function businessIntegrityBankChecks(')
    && str_contains($integrity, 'businessIntegrityBankChecks($accountingTenantId)')
    && str_contains($bankIntegrityApi, "rbac_legacy_require(\$ctx['user'], 'accounting.coa.view')")
    && str_contains($bankIntegrityApi, 'businessIntegrityBankChecks($accountingTenantId)'));
$check('bank exceptions link back to bank lines and journals',
    str_contains($bankRecUi, 'path="integrity"')
    && str_contains($bankRecUi, 'accounting-bank-integrity-link')
    && str_contains($bankIntegrityUi, '?line_id=${lineId}')
    && str_contains($bankIntegrityUi, 'match_status=matched')
    && str_contains($bankIntegrityUi, '/modules/accounting/journal-entries/${journalId}'));
$check('write routes guard stale posted lineage',
    str_contains($bankApi, 'bankRecGuardPostedLineage(')
    && str_contains($treasury, 'bankRecGuardPostedLineage($tenantId, $lineId)'));
$check('both bank surfaces expose conflict review instead of posting controls',
    str_contains($bankApi, "\$lineRow['lineage_conflict']")
    && str_contains($treasury, "\$row['lineage_conflict']")
    && str_contains((string) file_get_contents($root . '/modules/accounting/ui/BankReconciliation.jsx'), 'Ledger review required')
    && str_contains((string) file_get_contents($root . '/modules/treasury/ui/AccountTransactions.jsx'), 'Ledger review required'));
$check('bank reconciliation repairs before listing unmatched lines', str_contains($bankApi, 'bankRecRepairPostedMatches((int) $ctx[\'tenant_id\'], $bid)'));
$check('Treasury deposit postings use shared validated transitions',
    !str_contains($treasury, 'bankRecMarkLineMatched($tenantId')
    && substr_count($treasury, 'bankRecMatchLine($tenantId') >= 3);
$check('Treasury bulk unmatch uses the guarded bank transition',
    str_contains($treasury, "if (\$type === 'deposit') bankRecUnmatchLine(\$tenantId, \$eligibleId);"));
$check('Treasury deposit unmatch clears audit metadata', str_contains($lib, 'matched_by_user_id')
    && str_contains($lib, 'bankRecUnmatchLine('));

echo "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
