<?php
/**
 * Smoke: Plaid account-select + Treasury per-account remove + item disconnect.
 *
 * Static checks (no DB, no Plaid network required) — verify that the API and
 * UI files declare the right routes, payload contracts, and UI affordances
 * the user requested:
 *
 *   • /api/plaid_bank_link.php link_token attaches account_filters.
 *   • /api/plaid_bank_link.php exchange honors selected_account_ids[].
 *   • /modules/treasury/api/deposit_accounts.php DELETE supports mode=hide|delete.
 *   • /modules/treasury/api/liability_accounts.php DELETE supports mode=hide|delete.
 *   • /api/plaid_items.php exists and exposes GET (list) + DELETE (disconnect).
 *   • Frontend deposit row exposes Sync / Hide / Delete (no shared "Reconnect /
 *     Sync" button), liability row exposes Sync / Hide / Delete.
 *   • Treasury Overview renders ConnectedInstitutions panel + post-Link picker.
 */
declare(strict_types=1);

$assertCount = 0; $failCount = 0;
function _a(string $label, bool $cond, ?string $hint = null): void {
    global $assertCount, $failCount;
    $assertCount++;
    if ($cond) {
        echo "  ok  $label\n";
    } else {
        $failCount++;
        echo "FAIL  $label" . ($hint ? "  ($hint)" : '') . "\n";
    }
}

echo "Plaid bank-link link_token + exchange contract\n";
$bankLink = (string) file_get_contents(__DIR__ . '/../api/plaid_bank_link.php');
_a('account_filters present in link_token branch',          str_contains($bankLink, "'account_filters'"));
_a('depository subtypes list',                              str_contains($bankLink, "'checking', 'savings'"));
_a('credit subtypes list',                                  str_contains($bankLink, "'credit card'"));
_a('exchange accepts selected_account_ids',                 str_contains($bankLink, 'selected_account_ids'));
_a('exchange skips opt-out accounts',                       str_contains($bankLink, 'skipped_opt_out'));
_a('exchange uses selectedSet for gating',                  str_contains($bankLink, '$selectedSet'));
_a('audit log includes selected_account_ids',               str_contains($bankLink, "'selected_account_ids'"));

echo "\nDeposit accounts API — DELETE\n";
$dep = (string) file_get_contents(__DIR__ . '/../modules/treasury/api/deposit_accounts.php');
_a('DELETE branch present',                                 str_contains($dep, "case 'DELETE':"));
_a('mode=hide supported',                                   str_contains($dep, "'hide'"));
_a('mode=delete supported',                                 str_contains($dep, "'delete'"));
_a('hard delete blocks when posted JE references account',  str_contains($dep, 'Cannot hard-delete'));
_a('hide sets status=closed',                               str_contains($dep, "['status' => 'closed']"));
_a('delete cleans accounting_bank_statement_lines',         str_contains($dep, 'DELETE FROM accounting_bank_statement_lines'));

echo "\nLiability accounts API — DELETE\n";
$lia = (string) file_get_contents(__DIR__ . '/../modules/treasury/api/liability_accounts.php');
_a('DELETE branch present',                                 str_contains($lia, "case 'DELETE':"));
_a('mode=hide deactivates COA row',                         str_contains($lia, "['active' => 0]"));
_a('mode=delete blocks when JE posted',                     str_contains($lia, 'Cannot hard-delete'));
_a('cascades treasury_liability_accounts on delete',        str_contains($lia, 'DELETE FROM treasury_liability_accounts'));

echo "\nPlaid items endpoint\n";
$items = (string) file_get_contents(__DIR__ . '/../api/plaid_items.php');
_a('plaid_items.php exists',                                $items !== '');
_a('GET lists connected institutions',                      str_contains($items, "if (\$method === 'GET')"));
_a('GET returns account counts',                            str_contains($items, 'mirrored_deposit_count')
                                                          && str_contains($items, 'mirrored_liability_count'));
_a('DELETE revokes via /item/remove',                       str_contains($items, "/item/remove"));
_a('DELETE cascade-hides deposits',                         str_contains($items, 'cascadedDeposits'));
_a('DELETE cascade-deactivates liabilities',                str_contains($items, 'cascadedLiabilities'));
_a('DELETE marks plaid_item disconnected',                  str_contains($items, "'status'             => 'disconnected'"));
_a('DELETE permission gated on accounting.bank.manage',     str_contains($items, "rbac_legacy_require(\$user, 'accounting.bank.manage')"));

echo "\nDeposit row UI — Sync / Hide / Delete (no shared Reconnect/Sync)\n";
$depUI = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/DepositAccounts.jsx');
_a('PlaidLinkButton import removed from DepositAccounts',   !str_contains($depUI, "import PlaidLinkButton"));
_a('"Reconnect / Sync" combined label removed',             !str_contains($depUI, 'Reconnect / Sync'));
_a('per-row Sync button has data-testid',                   str_contains($depUI, "treasury-deposit-sync-"));
_a('per-row Hide button',                                   str_contains($depUI, "treasury-deposit-hide-"));
_a('per-row Delete button',                                 str_contains($depUI, "treasury-deposit-delete-"));
_a('Sync resolves item via diagnostics',                    str_contains($depUI, '/api/plaid_diagnostics.php'));
_a('Sync calls plaid_sync_transactions directly',           str_contains($depUI, '/api/plaid_sync_transactions.php'));

echo "\nLiability row UI — Sync / Hide / Delete\n";
$liaUI = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/LiabilityAccounts.jsx');
_a('per-row Sync button',                                   str_contains($liaUI, 'treasury-liability-sync-'));
_a('per-row Hide button',                                   str_contains($liaUI, 'treasury-liability-hide-'));
_a('per-row Delete button',                                 str_contains($liaUI, 'treasury-liability-delete-'));

echo "\nTreasury Overview — Account picker modal + Connected institutions\n";
$ov = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/TreasuryOverview.jsx');
_a('post-Link account picker modal',                        str_contains($ov, 'plaid-account-picker-modal'));
_a('checkbox per Plaid account',                            str_contains($ov, 'plaid-account-picker-cb-'));
_a('Confirm sends selected_account_ids',                    str_contains($ov, 'selected_account_ids'));
_a('Connected institutions panel renders',                  str_contains($ov, 'treasury-connected-institutions'));
_a('Disconnect button per item',                            str_contains($ov, 'treasury-plaid-item-disconnect-'));
_a('Disconnect uses DELETE /api/plaid_items.php',           str_contains($ov, "'/api/plaid_items.php?id=") || str_contains($ov, '`/api/plaid_items.php?id='));
_a('Overview prefers live bank balance over GL',            str_contains($ov, 'bank_balance')
                                                          && str_contains($ov, 'balanceOf'));

echo "\nLive Plaid balance pipeline (migration 010 + helper + UI)\n";
$mig = (string) file_get_contents(__DIR__ . '/../core/migrations/010_plaid_account_balances.sql');
_a('migration 010 exists',                                  $mig !== '');
_a('migration adds current_balance_cents',                  str_contains($mig, 'current_balance_cents'));
_a('migration adds available_balance_cents',                str_contains($mig, 'available_balance_cents'));
_a('migration adds balance_as_of timestamp',                str_contains($mig, 'balance_as_of'));

$svc = (string) file_get_contents(__DIR__ . '/../core/plaid_service.php');
_a('plaidPersistAccountBalances helper exists',             str_contains($svc, 'function plaidPersistAccountBalances'));
_a('helper self-heals missing columns at runtime',          str_contains($svc, "'plaid_accounts'")
                                                          && str_contains($svc, 'ADD COLUMN'));

_a('exchange persists balances after upsert',               str_contains($bankLink, 'plaidPersistAccountBalances'));
$sync = (string) file_get_contents(__DIR__ . '/../api/plaid_sync_transactions.php');
_a('sync refreshes balances post-cursor advance',           str_contains($sync, 'plaidPersistAccountBalances')
                                                          && str_contains($sync, 'plaidGetAccounts'));

_a('deposit GET joins plaid_accounts for balance',          str_contains($dep, 'pa.current_balance_cents'));
_a('deposit GET exposes bank_balance on each row',          str_contains($dep, "'bank_balance'"));
_a('liability GET joins plaid_accounts for balance',        str_contains($lia, 'pa.current_balance_cents'));
_a('liability GET exposes bank_balance + uses Plaid limit', str_contains($lia, "'bank_balance'")
                                                          && str_contains($lia, 'plaid_limit_cents'));

echo "\nNavigation fix — no more hash-based bank-rec bounce\n";
_a('Deposit row uses navigate (not window.location.hash)',  !str_contains($depUI, "window.location.hash = `#/modules/accounting/bank-rec/"));
_a('Open reconciliation label removed',                     !str_contains($depUI, 'Open reconciliation'));
_a('Deposit row label says "Transactions →"',               str_contains($depUI, 'Transactions →'));
_a('Liability row label says "Transactions →"',             str_contains($liaUI, 'Transactions →'));
_a('DepositDetail renders AccountTransactions in-Treasury', str_contains($depUI, 'AccountTransactions')
                                                          && str_contains($depUI, "type=\"deposit\""));
// Intentionally removed (2026-02): user feedback "literally nothing changed... no transactions"
// led the agent to drop the bouncy intermediate link. Transactions render inline now.
_a('DepositDetail no longer bounces to bank-rec workspace',  !str_contains($depUI, 'Open full reconciliation workspace'));

_a('Deposit list shows Bank balance column',                str_contains($depUI, '>Bank balance<')
                                                          && str_contains($depUI, 'treasury-deposit-bank-balance-'));
_a('Liability list shows Bank balance column',              str_contains($liaUI, '>Bank balance<')
                                                          && str_contains($liaUI, 'treasury-liability-bank-balance-'));

echo "\nReconnect adoption (no more duplicates) + dedupe endpoint\n";
_a('exchange has exact re-link match path',                 str_contains($bankLink, 'Exact re-link match'));
_a('exchange has adoption path keyed on bank+last4',        str_contains($bankLink, 'Adoption — same bank+last4 already linked'));
_a('exchange adoption updates plaid_account_id in place',   str_contains($bankLink, 'SET plaid_account_id = :pa,'));
_a('exchange re-activates closed rows on relink',           str_contains($bankLink, "SET status = 'active', updated_at = NOW()"));
_a('liability adoption keyed on institution+last4',         str_contains($bankLink, 'tla.institution_name = :inst'));

$dedupe = (string) file_get_contents(__DIR__ . '/../api/plaid_dedupe.php');
_a('plaid_dedupe.php exists',                               $dedupe !== '');
_a('GET previews deposit clusters',                         str_contains($dedupe, 'deposit_clusters'));
_a('GET previews liability clusters',                       str_contains($dedupe, 'liability_clusters'));
_a('POST?action=run hides extras + lifts plaid_account_id', str_contains($dedupe, "?action=run") || str_contains($dedupe, "'run'"));
_a('dedupe scoped to active rows only',                     str_contains($dedupe, "status = 'active'"));
_a('dedupe permission gated on accounting.bank.manage',     str_contains($dedupe, "rbac_legacy_require(\$ctx['user'], 'accounting.bank.manage')"));

echo "\nHuman formatting (dates + currency)\n";
$fmt = (string) file_get_contents(__DIR__ . '/../dashboard/src/lib/format.js');
_a('format.js exports fmtMoney',                            str_contains($fmt, 'export function fmtMoney'));
_a('format.js exports fmtDate',                             str_contains($fmt, 'export function fmtDate'));
_a('format.js exports fmtRelative',                         str_contains($fmt, 'export function fmtRelative'));
_a('fmtMoney uses currency style',                          str_contains($fmt, "style: 'currency'"));
_a('fmtDate avoids UTC midnight shift',                     str_contains($fmt, 'wall date'));

$br = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/BankReconciliation.jsx');
_a('BankRec imports shared formatters',                     str_contains($br, 'lib/format'));
_a('BankRec line uses fmtDate(line.posted_date)',           str_contains($br, 'fmtDate(line.posted_date)'));
_a('BankRec line uses fmtMoney(line.amount)',               str_contains($br, 'fmtMoney(line.amount)'));
_a('BankRec local fmtMoney duplicate removed',              !str_contains($br, "function fmtMoney(n)"));

$at = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/AccountTransactions.jsx');
_a('AccountTransactions imports shared formatters',         str_contains($at, 'lib/format'));
_a('AccountTransactions uses fmtDate on posted_date',       str_contains($at, 'fmtDate(r.posted_date)'));

_a('Deposit list humanises last_feed_synced_at',            str_contains($depUI, 'fmtRelative(r.last_feed_synced_at)'));
_a('Connected Institutions cleanup banner',                 str_contains($ov, 'treasury-dedupe-banner'));
_a('Connected Institutions cleanup button',                 str_contains($ov, 'treasury-dedupe-run-btn'));

echo "\n--- $assertCount assertions, $failCount failed ---\n";
exit($failCount === 0 ? 0 : 1);
