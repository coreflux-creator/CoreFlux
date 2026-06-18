<?php
/**
 * Smoke — QBO CoA Jaz-parity (2026-02).
 *
 * Locks:
 *   - core/qbo/account_import.php — bucket-allocator importer + manual
 *     mapping helper + Classification→bucket map.
 *   - core/qbo/sync_accounts.php — third pass (import_unmapped opt),
 *     richer unmapped_samples envelope (classification + currency +
 *     payload + acct_num), import/import_errors/imported_codes fields.
 *   - api/qbo.php — sync_accounts.import_unmapped body flag + new
 *     account_map_manual action, RBAC-gated on integrations.qbo.manage.
 *   - dashboard/src/pages/QboSettings.jsx — Pull & import unmapped CTA,
 *     UnmappedQboAccountsCard with per-row Import / Map / Remove flows.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ──────────────────────────────────────────────────────────────────────
// 1) account_import.php — importer + manual mapping
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/qbo/account_import.php ──\n";
$imp = (string) file_get_contents('/app/core/qbo/account_import.php');
$a('file exists',                       $imp !== '');
$a('strict types',                      $c($imp, 'declare(strict_types=1);'));
$a('declares qboImportUnmappedAccounts', $c($imp, 'function qboImportUnmappedAccounts'));
$a('declares qboAccountCreateManualMapping', $c($imp, 'function qboAccountCreateManualMapping'));
$a('declares qboClassificationToBucket', $c($imp, 'function qboClassificationToBucket'));

// Classification map covers QBO's enum + the legacy "Income" alias.
$a('maps Asset → asset bucket',         $c($imp, "'asset'     => 'asset'"));
$a('maps Liability → liability bucket', $c($imp, "'liability' => 'liability'"));
$a('maps Equity → equity bucket',       $c($imp, "'equity'    => 'equity'"));
$a('maps Revenue → revenue bucket',     $c($imp, "'revenue'   => 'revenue'"));
$a('maps Income (legacy) → revenue',    $c($imp, "'income'    => 'revenue'"));
$a('maps Expense → expense bucket',     $c($imp, "'expense'   => 'expense'"));
$a('strtolower-trims classification',   $c($imp, 'strtolower(trim($classification))'));

// Bucket-allocator: same 1000/2000/3000/4000/5000 base + cursor start
// at +1 as the Jaz importer.  Identical strategy → identical CF rows.
$a('bucket base 1000 for asset',        $c($imp, "'asset'     => 1000"));
$a('bucket base 2000 for liability',    $c($imp, "'liability' => 2000"));
$a('bucket base 5000 for expense',      $c($imp, "'expense'   => 5000"));
$a('cursor starts at +1 (1001/2001/...)', $c($imp, 'cursor[$b] = 1;'));
$a('truncates provider code to 40 chars', $c($imp, 'substr($providerCode, 0, 40)'));
$a('caps bucket walk at 9999 attempts', $c($imp, '$tries++ > 9999'));

// Idempotency — defensive skip if mapping already exists.
$a('skips qbo_id if mapping already exists (idempotent)',
    $c($imp, 'mappingFindInternal($tenantId, QBO_SOURCE, \'account\', $qboId)'));

// Pre-loads taken codes in ONE query (no N round-trips).
$a('pre-loads taken codes in one query',
    $c($imp, 'SELECT code FROM accounting_accounts WHERE tenant_id = :t'));

// INSERT shape mirrors the Jaz importer (is_postable=1, normal_side
// driven by bucket, active=1).
$a('INSERT marks new rows postable + active',
    $c($imp, 'is_postable, currency,')
    && $c($imp, ':cur, 1, NOW(), NOW()'));

// Seeds external_entity_mappings so qboResolveAccountRef hits the cache.
$a('seeds external_entity_mappings via mappingUpsert',
    $c($imp, "mappingUpsert(\$tenantId, QBO_SOURCE, 'account'"));
$a('rolls back CF row if mapping save fails',
    $c($imp, 'DELETE FROM accounting_accounts WHERE id = :id'));

// One audit row per batch.
$a('writes one audit row import_qbo_accounts per batch',
    $c($imp, "'import_qbo_accounts'"));

// Manual-mapping helper
$a('manual mapping refuses empty qbo_id',  $c($imp, "throw new \\RuntimeException('qbo_id required')"));
$a('manual mapping refuses cf_account_id <= 0', $c($imp, "throw new \\RuntimeException('cf_account_id required')"));
$a('manual mapping refuses to overwrite existing mapping silently',
    $c($imp, 'already mapped to CF account'));
$a('manual mapping writes manual_account_map audit',
    $c($imp, "'manual_account_map'"));

// ──────────────────────────────────────────────────────────────────────
// 2) sync_accounts.php — third pass + richer envelope
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/qbo/sync_accounts.php — import pass ──\n";
$sync = (string) file_get_contents('/app/core/qbo/sync_accounts.php');
$a('reads opts.import_unmapped flag',     $c($sync, "!empty(\$opts['import_unmapped'])"));
$a('lazy-requires account_import on import path',
    $c($sync, "require_once __DIR__ . '/account_import.php'"));
$a('calls qboImportUnmappedAccounts when flag set',
    $c($sync, 'qboImportUnmappedAccounts($tenantId, $unmappedSamples'));
$a('subtracts imported from unmapped tally', $c($sync, '$unmapped = max(0, $unmapped - $imported)'));

// Richer sample shape — classification, account_type, currency, payload.
$a('unmapped sample carries classification',
    $c($sync, "'classification' => (string) (\$qbo['Classification']"));
$a('unmapped sample carries account_type',
    $c($sync, "'account_type'   => (string) (\$qbo['AccountType']"));
$a('unmapped sample carries currency',
    $c($sync, "'currency'       => (string) (\$qbo['CurrencyRef']['value']"));
$a('unmapped sample carries full QBO payload',
    $c($sync, "'payload'        => \$qbo"));

// Envelope additions.
$a("envelope adds 'imported' field",      $c($sync, "'imported'        => \$imported"));
$a("envelope adds 'import_errors' field", $c($sync, "'import_errors'   => \$importErrors"));
$a("envelope adds 'imported_codes' field", $c($sync, "'imported_codes'  => \$importedCodes"));
$a('audit detail mentions imported + import_errors counts',
    $c($sync, "'imported'      => \$imported")
    && $c($sync, "'import_errors' => count(\$importErrors)"));

// Regression: audit row still slices samples to the first 20.
$a('audit row still slices samples to first 20',
    $c($sync, "'samples' => array_slice(\$unmappedSamples, 0, 20)"));

// ──────────────────────────────────────────────────────────────────────
// 3) api/qbo.php — import_unmapped flag + account_map_manual
// ──────────────────────────────────────────────────────────────────────
echo "\n── api/qbo.php ──\n";
$api = (string) file_get_contents('/app/api/qbo.php');
$a('sync_accounts accepts import_unmapped body flag',
    $c($api, "isset(\$body['import_unmapped'])")
    && $c($api, "\$opts['import_unmapped'] = (bool) \$body['import_unmapped']"));
$a('account_map_manual action wired',
    $c($api, "case 'account_map_manual'"));
$a('account_map_manual RBAC-gated on integrations.qbo.manage',
    $c($api, "case 'account_map_manual'")
    && substr_count(substr($api, strpos($api, "case 'account_map_manual'"), 600),
        "rbac_legacy_require(\$user, 'integrations.qbo.manage')") >= 1);
$a('account_map_manual validates qbo_id + cf_account_id',
    $c($api, "api_error('qbo_id required', 422)")
    && $c($api, "api_error('cf_account_id required', 422)"));
$a('account_map_manual lazy-requires account_import.php',
    $c($api, "require_once __DIR__ . '/../core/qbo/account_import.php'"));
$a('account_map_manual maps RuntimeException → 409',
    substr_count(substr($api, strpos($api, "case 'account_map_manual'"), 1200),
        "api_error(\$e->getMessage(), 409)") >= 1);

// ──────────────────────────────────────────────────────────────────────
// 4) QboSettings.jsx — Pull & import CTA + UnmappedQboAccountsCard
// ──────────────────────────────────────────────────────────────────────
echo "\n── QboSettings.jsx ──\n";
$ui = (string) file_get_contents('/app/dashboard/src/pages/QboSettings.jsx');

// Pull-and-import CTA.
$a('Pull & import unmapped button present',
    $c($ui, 'data-testid="qbo-sync-accounts-import-btn"'));
$a('Pull & import calls handlePullMaster with import_unmapped: true',
    $c($ui, "handlePullMaster('accounts', { import_unmapped: true })"));
$a('handlePullMaster forwards opts into POST body',
    $c($ui, "const body = { limit: 1000, ...opts }"));
$a('handlePullMaster captures last pull result into state',
    $c($ui, 'setCoaPullResult(r)'));

// Manual mapping wiring.
$a('handleMapAccountManual POSTs to account_map_manual',
    $c($ui, '/api/qbo/account_map_manual.php?action=account_map_manual'));
$a('handleMapAccountManual sends qbo_id + cf_account_id',
    $c($ui, "qbo_id: sample.qbo_id")
    && $c($ui, 'cf_account_id: parseInt(cfAccountId, 10)'));
$a('handleImportOneAccount POSTs sync_accounts with import_unmapped',
    $c($ui, "const handleImportOneAccount = async")
    && substr_count(substr($ui, strpos($ui, 'const handleImportOneAccount'), 800),
        'import_unmapped: true') >= 1);
$a('handleRemoveCfAccount POSTs to /api/accounting.php?action=account_delete',
    $c($ui, '/api/accounting.php?action=account_delete'));
$a('handleRemoveCfAccount falls back to account_deactivate on 409',
    $c($ui, '/api/accounting.php?action=account_deactivate'));

// Card mounted + lookups + per-row testids.
$a('UnmappedQboAccountsCard component declared',
    $c($ui, 'function UnmappedQboAccountsCard'));
$a('Card uses CF accounts list endpoint',
    $c($ui, 'ACCOUNTING_ACCOUNTS_API') && $c($ui, '?active=1'));
$a('Card mounts only after a pull (coaPullResult)',
    preg_match('/UnmappedQboAccountsCard[\s\S]{0,300}result=\{coaPullResult\}/', $ui) === 1);
foreach ([
    'qbo-unmapped-accounts-card',
    'qbo-unmapped-accounts-title',
    'qbo-unmapped-import-errors',
    'qbo-unmapped-empty',
    'qbo-unmapped-table',
    'qbo-unmapped-remove-toggle',
    'qbo-unmapped-remove-select',
] as $tid) {
    $a("testid '{$tid}' present", $c($ui, "data-testid=\"{$tid}\""));
}
foreach ([
    'qbo-unmapped-row-${idx}',
    'qbo-unmapped-imported-${idx}',
    'qbo-unmapped-mapped-${idx}',
    'qbo-unmapped-map-select-${idx}',
    'qbo-unmapped-import-${idx}',
    'qbo-unmapped-import-error-${i}',
] as $template) {
    $a("template testid '{$template}' present", $c($ui, "data-testid={`{$template}`}"));
}

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "QBO Jaz-parity smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
