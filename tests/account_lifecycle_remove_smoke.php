<?php
/**
 * tests/account_lifecycle_remove_smoke.php
 *
 * Locks the 2026-02 account-lifecycle (delete + deactivate)
 * affordance.  Plaid (and other bank-link / import flows) seed one
 * `accounting_accounts` row per bank sub-account — operators
 * routinely end up with 10-20 bank-shaped GL rows they didn't intend
 * to keep in the chart.  This adds:
 *
 *   1) `core/accounting/account_lifecycle.php` with:
 *      - `accountingAccountDelete($tenantId, $accountId)` — hard delete
 *        only when zero posted journal lines + zero active bank-feed
 *        references.  Cascade-drops `accounting_account_mappings`.
 *      - `accountingAccountDeactivate(...)` — soft path (active=0).
 *      - `AccountingAccountDeleteBlockedException` carries the
 *        per-reason counts so the API can return them as 409 extras.
 *
 *   2) API endpoints in `api/accounting.php`:
 *      - POST `?action=account_delete`     → hard delete + 409 with reasons
 *      - POST `?action=account_deactivate` → soft archive (always OK)
 *      Both require `accounting.connection.manage` RBAC.
 *
 *   3) UI in JazSyncNowCard:
 *      - Per-row "Remove" button alongside the dropdown.
 *      - On 409 → confirm fallback offers "Deactivate instead".
 *      - Resolved-removed rows fade to opacity:0.5 with a "✓ Removed
 *        from CoA" / "✓ Deactivated" label.
 *
 * Run:
 *   php -d zend.assertions=1 tests/account_lifecycle_remove_smoke.php
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
function ok(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { echo "  \u{2713} {$label}\n"; $pass++; }
    else       { echo "  \u{2717} {$label}\n"; $fail++; }
}
function read(string $rel): string {
    $p = realpath(__DIR__ . '/../' . $rel);
    if (!$p || !is_readable($p)) { fwrite(STDERR, "missing: {$rel}\n"); exit(2); }
    return file_get_contents($p) ?: '';
}

echo "── core/accounting/account_lifecycle.php — service layer ──\n";
$life = read('core/accounting/account_lifecycle.php');
ok('Declares accountingAccountDelete with explicit tenantId scope',
   (bool) preg_match('/function\s+accountingAccountDelete\(int\s+\$tenantId,\s+int\s+\$accountId\)/', $life));
ok('Declares accountingAccountDeactivate',
   str_contains($life, 'function accountingAccountDeactivate'));
ok('Declares AccountingAccountDeleteBlockedException with reasons array',
   str_contains($life, 'class AccountingAccountDeleteBlockedException')
   && str_contains($life, 'public array $reasons'));
ok('Tenant-bound row lookup before reference checks',
   str_contains($life, "WHERE id = :id AND tenant_id = :t"));
ok('Reference check on accounting_journal_entry_lines.account_id (joined via journal entry for tenant scope)',
   str_contains($life, 'FROM accounting_journal_entry_lines ajel')
   && str_contains($life, 'JOIN accounting_journal_entries aje')
   && str_contains($life, 'aje.tenant_id = :t'));
ok('Reference check on accounting_bank_accounts.gl_account_code (tenant-scoped, status-aware)',
   str_contains($life, 'accounting_bank_accounts')
   && str_contains($life, 'gl_account_code = :c')
   && str_contains($life, "NOT IN ('archived', 'removed')"));
ok('Cascade-drops accounting_account_mappings before deleting row (tenant-scoped DELETE)',
   (bool) preg_match("/DELETE FROM accounting_account_mappings\s+WHERE coreflux_account_id = :id AND tenant_id = :t.*DELETE FROM accounting_accounts/s", $life));
ok('Returns deleted + mappings_removed counts',
   str_contains($life, "'deleted'           => (int) \$aDel->rowCount()")
   && str_contains($life, "'mappings_removed'  => \$mappingsRemoved"));
ok('Deactivate flips active=0 and stamps updated_at',
   str_contains($life, 'SET active = 0, updated_at = NOW()'));
ok('Blocked exception carries reasons array',
   str_contains($life, '$err->reasons = $reasons'));
ok('Tenant 0 / account 0 throws InvalidArgumentException',
   str_contains($life, "throw new \\InvalidArgumentException('tenant_id + account_id required')"));

echo "── api/accounting.php — endpoint surface ──\n";
$api = read('api/accounting.php');
ok('account_delete + account_deactivate share an in_array gate',
   (bool) preg_match("/in_array\(\\\$action,\s*\['account_delete',\s*'account_deactivate'\]/", $api));
ok('Both endpoints require accounting.connection.manage RBAC',
   (bool) preg_match("/in_array.*account_delete.*accounting.connection.manage/s", $api)
   || (str_contains($api, "in_array(\$action, ['account_delete'") && str_contains($api, "rbac_legacy_require(\$user, 'accounting.connection.manage')")));
ok('account_delete returns 409 with reasons array on blocked',
   (bool) preg_match("/AccountingAccountDeleteBlockedException.*api_error\(.*409.*'reasons'/s", $api));
ok('coreflux_account_id is the canonical body field (account_id accepted as alias)',
   str_contains($api, "(int) (\$body['coreflux_account_id'] ?? \$body['account_id'] ?? 0)"));
ok('account_lifecycle.php loaded via require_once',
   str_contains($api, "require_once __DIR__ . '/../core/accounting/account_lifecycle.php'"));

echo "── JazSyncNowCard — Remove button + 409 fallback ──\n";
$ui = read('dashboard/src/pages/JazIntegrationSettings.jsx');
ok('removedNow state tracks deleted/deactivated rows',
   str_contains($ui, 'setRemovedNow'));
ok('removedNow resets on every new runSync',
   (bool) preg_match("/setMappedNow\(\{\}\);\s*setRemovedNow\(\{\}\)/", $ui));
ok('removeAccount() helper exists',
   str_contains($ui, 'const removeAccount = async'));
ok('removeAccount POSTs to the right action endpoint',
   str_contains($ui, '`${ACCOUNTING_INTEGRATIONS_API}?action=' . '$' . '{action}&provider=jaz`'));
ok('Confirm prompt warns about journal lines + bank feed before hard delete',
   str_contains($ui, 'posted journal lines') && str_contains($ui, 'active bank feed'));
ok('On 409 → confirm fallback offers Deactivate instead',
   (bool) preg_match('/status === 409.*Deactivate instead/s', $ui));
ok('Per-row Remove button with testid jaz-sync-unmapped-remove-{i}',
   str_contains($ui, 'jaz-sync-unmapped-remove-${ui}'));
ok('Removed-row state shows "✓ Removed from CoA" / "✓ Deactivated"',
   str_contains($ui, '✓ Removed from CoA') && str_contains($ui, '✓ Deactivated'));
ok('Removed row fades to opacity 0.5 (visible but distinct)',
   str_contains($ui, 'opacity: gone ? 0.5 : 1'));
ok('Remove button is disabled while a save is in flight',
   (bool) preg_match('/disabled=\{saving\s*\|\|\s*!cfId\}/', $ui));
ok('Per-row mapped/select/remove testids cohabit a single row container',
   str_contains($ui, 'jaz-sync-unmapped-row-${ui}'));

echo "\n=========================================\n";
echo "Account lifecycle remove smoke: {$pass} \u{2713} / {$fail} \u{2717}\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
