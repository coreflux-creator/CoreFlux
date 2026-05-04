<?php
/**
 * Plaid transactions feed + CoA hierarchy auto-grouping smoke.
 *
 * Covers:
 *   • plaid_sync_transactions.php fans out per-account (deposits → bank_statement_lines,
 *     liabilities → treasury_liability_statement_lines)
 *   • account_transactions.php returns rows by (account_id, type)
 *     with inflow/outflow + plaid_item_pk for the "Sync from Plaid" button
 *   • plaidEnsureInstitutionParent in plaid_service.php creates a header row
 *     (is_postable=0) the first time, returns existing id thereafter
 *   • plaid_bank_link.php passes the parent_id when inserting a card row
 *   • accounts.php exposes ?action=auto_group_plaid + ?action=tree
 *   • Migration 003 file declares the right columns and index
 *   • Treasury UI wires per-row "View →" + AccountTransactions component
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; } else { echo "  ✗ {$name}\n"; $fail++; }
};
$lint = function (string $path): bool {
    $rc = 0; @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $_, $rc); return $rc === 0;
};

// ─── Sync endpoint fan-out ───
echo "/api/plaid_sync_transactions.php (per-account fan-out)\n";
$src = file_get_contents(__DIR__ . '/../api/plaid_sync_transactions.php');
$assert('endpoint exists',                   is_string($src) && strlen($src) > 200);
$assert('builds plaid → destination map',    strpos($src, '_plaidBuildAccountDestinationMap(') !== false);
$assert('routes by transaction.account_id',  strpos($src, "\$t['account_id']") !== false);
$assert('writes deposits to bank_statement_lines',
                                             strpos($src, 'INSERT INTO accounting_bank_statement_lines') !== false);
$assert('writes liabilities to treasury_liability_statement_lines',
                                             strpos($src, 'INSERT INTO treasury_liability_statement_lines') !== false);
$assert('flips amount sign (Plaid + outflow → ledger - debit)',
                                             strpos($src, '* -1') !== false);
$assert('handles MUTATION_DURING_PAGINATION',strpos($src, 'MUTATION_DURING_PAGINATION') !== false);
$assert('refuses sync when no destinations', strpos($src, 'No mirrored deposit or liability accounts') !== false);
$assert('removed → match_status=ignored',    strpos($src, "match_status = 'ignored'") !== false);
$assert('updates plaid_items.transactions_cursor',
                                             strpos($src, "'transactions_cursor'") !== false);
$assert('per-account counter in result',     strpos($src, "'per_account'") !== false);
$assert('PHP parses cleanly',                $lint(__DIR__ . '/../api/plaid_sync_transactions.php'));

// ─── Treasury account_transactions API ───
echo "modules/treasury/api/account_transactions.php\n";
$at = file_get_contents(__DIR__ . '/../modules/treasury/api/account_transactions.php');
$assert('endpoint exists',                   is_string($at) && strlen($at) > 200);
$assert('GET requires account_id',           strpos($at, "account_id required") !== false);
$assert('type=deposit reads bank_statement_lines',
                                             strpos($at, 'FROM accounting_bank_statement_lines') !== false);
$assert('type=liability reads treasury_liability_statement_lines',
                                             strpos($at, 'FROM treasury_liability_statement_lines') !== false);
$assert('limit clamped 1-500',               strpos($at, 'min(500') !== false);
$assert('returns inflow_total + outflow_total', strpos($at, "'inflow_total'") !== false
                                             && strpos($at, "'outflow_total'") !== false);
$assert('returns plaid_item_pk for deposit + liability paths',
                                             substr_count($at, 'plaid_item_pk') >= 2);
$assert('?action=sync wires Plaid sync',     strpos($at, "action'] ?? '') === 'sync'") !== false);
$assert('sync requires plaid_item_pk',       strpos($at, 'plaid_item_pk required') !== false);
$assert('sync requires accounting.bank.manage perm',
                                             strpos($at, "'accounting.bank.manage'") !== false);
$assert('PHP parses cleanly',                $lint(__DIR__ . '/../modules/treasury/api/account_transactions.php'));

// ─── Migration 003 ───
echo "treasury migration 003\n";
$mig = file_get_contents(__DIR__ . '/../modules/treasury/migrations/003_liability_statement_lines.sql');
$assert('migration exists',                  is_string($mig) && strlen($mig) > 100);
$assert('creates treasury_liability_statement_lines', strpos($mig, 'CREATE TABLE IF NOT EXISTS treasury_liability_statement_lines') !== false);
$assert('UNIQUE (tenant, liability_account, fitid)', strpos($mig, 'uq_tlsl_fitid') !== false);
$assert('match_status enum',                 strpos($mig, "ENUM('unmatched','matched','ignored')") !== false);
$assert('idx_aa_tenant_parent index for tree queries',
                                             strpos($mig, 'idx_aa_tenant_parent') !== false);

// ─── plaidEnsureInstitutionParent helper ───
echo "core/plaid_service.php (institution parent helper)\n";
$svc = file_get_contents(__DIR__ . '/../core/plaid_service.php');
$assert('plaidEnsureInstitutionParent exists',
                                             strpos($svc, 'function plaidEnsureInstitutionParent(') !== false);
$assert('matches by name first (idempotent)',strpos($svc, "AND name = :n AND account_type = 'liability'") !== false);
$assert('creates header row (is_postable=0)',strpos($svc, "'liability', 'credit', 0, NULL, 1, NOW()") !== false);
$assert('plaidAllocateBankGlCode still present (helper neighbour)',
                                             strpos($svc, 'function plaidAllocateBankGlCode(') !== false);
$assert('plaid_service.php parses cleanly',  $lint(__DIR__ . '/../core/plaid_service.php'));

// ─── plaid_bank_link.php uses the helper for new cards ───
echo "/api/plaid_bank_link.php (auto-parent on liability create)\n";
$bl = file_get_contents(__DIR__ . '/../api/plaid_bank_link.php');
$assert('calls plaidEnsureInstitutionParent', strpos($bl, 'plaidEnsureInstitutionParent(') !== false);
$assert('passes parent_account_id on new liability INSERT',
                                             strpos($bl, "(tenant_id, code, name, account_type, normal_side, parent_account_id, active, created_at)") !== false);
$assert('plaid_bank_link.php parses cleanly',$lint(__DIR__ . '/../api/plaid_bank_link.php'));

// ─── Accounts API: tree + auto_group_plaid actions ───
echo "modules/accounting/api/accounts.php (tree + auto_group_plaid)\n";
$ac = file_get_contents(__DIR__ . '/../modules/accounting/api/accounts.php');
$assert('?action=auto_group_plaid handler',  strpos($ac, "action'] ?? '') === 'auto_group_plaid'") !== false);
$assert('auto_group_plaid loads plaid_service',
                                             strpos($ac, "require_once __DIR__ . '/../../../core/plaid_service.php'") !== false);
$assert('auto_group_plaid requires accounting.coa.manage perm',
                                             strpos($ac, "'accounting.coa.manage'") !== false);
$assert('?action=tree returns rows',         strpos($ac, "action'] ?? '') === 'tree'") !== false);
$assert('PATCH supports parent_account_id',  strpos($ac, "if (\$method === 'PATCH')") !== false);
$assert('audits accounting.coa.auto_grouped_plaid',
                                             strpos($ac, "'accounting.coa.auto_grouped_plaid'") !== false);
$assert('accounts.php parses cleanly',       $lint(__DIR__ . '/../modules/accounting/api/accounts.php'));

// ─── React UI ───
echo "Treasury UI (deposit + liability detail pages)\n";
$at_ui = file_get_contents(__DIR__ . '/../modules/treasury/ui/AccountTransactions.jsx');
$assert('AccountTransactions component',     strpos($at_ui, 'export default function AccountTransactions(') !== false);
$assert('loads from account_transactions.php', strpos($at_ui, '/modules/treasury/api/account_transactions.php?account_id=') !== false);
$assert('Sync from Plaid button',            strpos($at_ui, 'treasury-${type}-sync-btn') !== false);
$assert('renders inflow/outflow headline',   strpos($at_ui, 'Inflow ') !== false && strpos($at_ui, 'Outflow ') !== false);

$dep_ui = file_get_contents(__DIR__ . '/../modules/treasury/ui/DepositAccounts.jsx');
$assert('DepositDetail wires AccountTransactions',
                                             strpos($dep_ui, 'function DepositDetail()') !== false
                                             && strpos($dep_ui, '<AccountTransactions') !== false);
$assert('Deposit row View → button',         strpos($dep_ui, 'treasury-deposit-view-${r.id}') !== false);
$assert('Open Bank Reconciliation deep-link',strpos($dep_ui, 'treasury-deposit-bankrec-') !== false);
$assert('Sync URL has .php suffix (router-safe)',
                                             strpos($dep_ui, '/api/plaid_sync_transactions.php') !== false);

$lia_ui = file_get_contents(__DIR__ . '/../modules/treasury/ui/LiabilityAccounts.jsx');
$assert('LiabilityDetail wires AccountTransactions',
                                             strpos($lia_ui, 'function LiabilityDetail()') !== false
                                             && strpos($lia_ui, '<AccountTransactions') !== false);
$assert('Liability row View → button',       strpos($lia_ui, 'treasury-liability-view-${r.id}') !== false);
$assert('Liability route :id',               strpos($lia_ui, 'path=":id"') !== false);

$br_ui = file_get_contents(__DIR__ . '/../modules/accounting/ui/BankReconciliation.jsx');
$assert('BankReconciliation sync URL has .php',
                                             strpos($br_ui, '/api/plaid_sync_transactions.php') !== false);

echo "Accounting UI (Chart of Accounts tree + reparent)\n";
$coa = file_get_contents(__DIR__ . '/../modules/accounting/ui/ChartOfAccounts.jsx');
$assert('buildTree + descendantSet present', strpos($coa, 'function buildTree(') !== false
                                             && strpos($coa, 'function descendantSet(') !== false);
$assert('Move… button per row',              strpos($coa, 'accounting-accounts-move-${r.code}') !== false);
$assert('MoveDialog with parent select',     strpos($coa, 'function MoveDialog(') !== false
                                             && strpos($coa, 'accounting-accounts-move-parent-select') !== false);
$assert('Auto-group Plaid liabilities button',
                                             strpos($coa, 'data-testid="accounting-accounts-auto-group-plaid"') !== false);
$assert('PATCH parent_account_id on save',   strpos($coa, "parent_account_id: newParentId || null") !== false);

echo "\nPass: {$pass}\nFail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
