<?php
/**
 * /api/plaid_diagnostics.php — tenant-scoped Plaid connection inspector.
 *
 * Returns everything the platform persisted for the current tenant after a
 * Plaid Link / exchange flow. Use this to debug "I connected my bank, where
 * did the account go?" reports.
 *
 *   GET /api/plaid_diagnostics.php
 *
 * Returns:
 *   {
 *     plaid_items:                  [{ id, item_id, institution_name, purpose, status, last_webhook_at, last_error_message, created_at }],
 *     plaid_accounts:               [{ id, plaid_item_pk, account_id, name, mask, type, subtype }],
 *     accounting_bank_accounts_for_plaid: [{ id, name, gl_account_code, plaid_account_id, feed_provider, status }],
 *     treasury_liability_accounts_for_plaid: [{ id, subtype, last4, plaid_account_id, account_id }],
 *     orphaned_plaid_accounts:      [...]   // Plaid accounts NOT mirrored anywhere
 *   }
 *
 * Permission: `accounting.bank.manage`. Read-only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($ctx['user'], 'accounting.bank.manage');
if (api_method() === 'POST' && (string) ($_GET['action'] ?? '') === 'backfill') {
    require_once __DIR__ . '/../core/plaid_service.php';

    $pdo = getDB();

    // Self-heal: add the liability column if it isn't there yet (mirror of
    // the same guard in plaid_bank_link.php).
    try {
        $colCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name   = 'treasury_liability_accounts'
                AND column_name  = 'plaid_account_id'"
        );
        $colCheck->execute();
        if ((int) $colCheck->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE treasury_liability_accounts
                          ADD COLUMN plaid_account_id VARCHAR(80) NULL");
        }
    } catch (\Throwable $e) { /* surfaces per-account below */ }

    $stmt = $pdo->prepare(
        "SELECT pa.id AS pa_id, pa.account_id, pa.name, pa.mask, pa.type, pa.subtype,
                pi.institution_name
           FROM plaid_accounts pa
           JOIN plaid_items    pi ON pi.id = pa.plaid_item_pk
          WHERE pa.tenant_id = :t
            AND pa.account_id NOT IN (
              SELECT plaid_account_id FROM accounting_bank_accounts
               WHERE tenant_id = :t2 AND plaid_account_id IS NOT NULL
            )"
    );
    $stmt->execute(['t' => $tenantId, 't2' => $tenantId]);
    $orphans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter out anything already in liabilities (separate query — UNION-friendly).
    try {
        $liabIds = $pdo->prepare(
            "SELECT plaid_account_id FROM treasury_liability_accounts
              WHERE tenant_id = :t AND plaid_account_id IS NOT NULL"
        );
        $liabIds->execute(['t' => $tenantId]);
        $alreadyLiab = array_flip(array_column($liabIds->fetchAll(PDO::FETCH_ASSOC), 'plaid_account_id'));
        $orphans = array_values(array_filter($orphans, fn ($o) => !isset($alreadyLiab[$o['account_id']])));
    } catch (\Throwable $e) { /* table may not exist; nothing to filter */ }

    $createdBank = []; $createdLiab = []; $skipped = []; $errors = [];

    foreach ($orphans as $a) {
        $accId   = (string) $a['account_id'];
        $name    = (string) $a['name'];
        $mask    = (string) ($a['mask'] ?? '') ?: null;
        $type    = (string) ($a['type'] ?? '');
        $subtype = (string) ($a['subtype'] ?? '');
        $instName= (string) ($a['institution_name'] ?? '');

        try {
            if ($type === 'depository') {
                $base = $subtype === 'savings' ? '1010' : '1000';
                $glCode = plaidAllocateBankGlCode($pdo, $tenantId, $base, $mask, $accId);
                $bankName = $instName !== '' ? $instName : ($name ?: 'Bank');
                $insName  = trim(($instName ? "{$instName} — " : '') . ($name ?: 'Account'));

                // Ensure the COMPANION COA row exists so the bank shows up
                // on Chart of Accounts. Bug fix 2026-06.
                $aaCheck = $pdo->prepare('SELECT id FROM accounting_accounts WHERE tenant_id = :t AND code = :c LIMIT 1');
                $aaCheck->execute(['t' => $tenantId, 'c' => $glCode]);
                if ((int) $aaCheck->fetchColumn() === 0) {
                    $glFull = $insName . ($mask ? " …{$mask}" : '');
                    $pdo->prepare(
                        "INSERT INTO accounting_accounts
                            (tenant_id, code, name, account_type, normal_side,
                             is_postable, parent_account_id, active, created_at)
                         VALUES (:t, :c, :n, 'asset', 'debit', 1, NULL, 1, NOW())"
                    )->execute(['t' => $tenantId, 'c' => $glCode, 'n' => $glFull ?: ($name ?: 'Bank account')]);
                }

                $pdo->prepare(
                    'INSERT INTO accounting_bank_accounts
                        (tenant_id, name, gl_account_code, bank_name, last4, currency,
                         feed_provider, status, plaid_account_id, last_feed_synced_at,
                         created_at)
                     VALUES (:t, :nm, :gl, :bk, :l4, :c, "plaid_transactions", "active", :pa, NULL, NOW())'
                )->execute([
                    't'  => $tenantId, 'nm' => $insName, 'gl' => $glCode,
                    'bk' => $bankName, 'l4' => $mask, 'c'  => 'USD', 'pa' => $accId,
                ]);
                $createdBank[] = (int) $pdo->lastInsertId();
                continue;
            }

            if ($type === 'credit' || $type === 'loan') {
                $base = $type === 'loan' ? '2200' : '2100';
                $defName = $type === 'loan' ? 'Notes Payable' : 'Credit Card Payable';
                $glCode = plaidAllocateBankGlCode($pdo, $tenantId, $base, $mask, $accId);
                $treasurySubtype = match (true) {
                    $subtype === 'credit card'                  => 'credit_card',
                    in_array($subtype, ['line of credit'], true)=> 'line_of_credit',
                    $type    === 'loan'                         => 'loan',
                    default                                     => 'other_liability',
                };

                $aaCheck = $pdo->prepare('SELECT id FROM accounting_accounts WHERE tenant_id = :t AND code = :c LIMIT 1');
                $aaCheck->execute(['t' => $tenantId, 'c' => $glCode]);
                $aaId = (int) $aaCheck->fetchColumn();
                if ($aaId === 0) {
                    $glLabel = $name !== '' ? "{$defName} — {$name}" . ($mask ? " …{$mask}" : '') : $defName;
                    $pdo->prepare(
                        "INSERT INTO accounting_accounts
                            (tenant_id, code, name, account_type, normal_side, active, created_at)
                         VALUES (:t, :c, :n, 'liability', 'credit', 1, NOW())"
                    )->execute(['t' => $tenantId, 'c' => $glCode, 'n' => $glLabel]);
                    $aaId = (int) $pdo->lastInsertId();
                }

                $pdo->prepare(
                    'INSERT INTO treasury_liability_accounts
                        (tenant_id, account_id, subtype, institution_name, last4,
                         plaid_account_id, created_at)
                     VALUES (:t, :aid, :st, :inst, :l4, :pa, NOW())'
                )->execute([
                    't'   => $tenantId, 'aid' => $aaId, 'st'  => $treasurySubtype,
                    'inst'=> $instName !== '' ? $instName : null,
                    'l4'  => $mask, 'pa'  => $accId,
                ]);
                $createdLiab[] = (int) $pdo->lastInsertId();
                continue;
            }

            $skipped[] = "{$type}/{$subtype} '{$name}' (not a deposit or liability)";
        } catch (\Throwable $e) {
            $errors[] = "'{$name}' (...{$mask}, type={$type}/{$subtype}): " . $e->getMessage();
        }
    }

    if (function_exists('plaidAudit')) {
        plaidAudit('payment_rails.plaid.backfill', [
            'bank_accounts_created'      => $createdBank,
            'liability_accounts_created' => $createdLiab,
            'skipped'                    => $skipped,
            'errors'                     => $errors,
        ], null, [
            'tenant_id' => $tenantId,
            'actor_user_id' => (int) ($ctx['user']['id'] ?? 0),
            'source' => 'plaid_diagnostics',
        ]);
    }

    api_ok([
        'orphans_processed'          => count($orphans),
        'bank_accounts_created'      => $createdBank,
        'liability_accounts_created' => $createdLiab,
        'skipped'                    => $skipped,
        'errors'                     => $errors,
    ]);
}

if (api_method() === 'POST' && (string) ($_GET['action'] ?? '') === 'backfill_gl_for_banks') {
    // Backfill missing accounting_accounts (COA) rows for banks that
    // already have rows in accounting_bank_accounts (legacy data before
    // the Plaid → CoA wiring was completed). Idempotent — only creates
    // GL rows for codes that don't already exist.
    $pdo = getDB();
    $rows = $pdo->prepare(
        "SELECT ba.id, ba.name, ba.gl_account_code, ba.bank_name, ba.last4
           FROM accounting_bank_accounts ba
      LEFT JOIN accounting_accounts aa
                 ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
          WHERE ba.tenant_id = :t
            AND ba.gl_account_code IS NOT NULL
            AND aa.id IS NULL"
    );
    $rows->execute(['t' => $tenantId]);
    $missing = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $created = [];
    $errs    = [];
    foreach ($missing as $row) {
        $code   = (string) $row['gl_account_code'];
        $label  = trim((string) ($row['name'] ?? ($row['bank_name'] ?? '')));
        $last4  = (string) ($row['last4'] ?? '');
        $glName = $label !== '' ? $label : ($row['bank_name'] ?? 'Bank account');
        if ($last4 !== '' && stripos($glName, $last4) === false) $glName .= " …{$last4}";
        try {
            $pdo->prepare(
                "INSERT INTO accounting_accounts
                    (tenant_id, code, name, account_type, normal_side,
                     is_postable, parent_account_id, active, created_at)
                 VALUES (:t, :c, :n, 'asset', 'debit', 1, NULL, 1, NOW())"
            )->execute(['t' => $tenantId, 'c' => $code, 'n' => $glName]);
            $created[] = ['bank_account_id' => (int) $row['id'], 'code' => $code, 'name' => $glName];
        } catch (\Throwable $e) {
            $errs[] = "bank '{$label}' code {$code}: " . $e->getMessage();
        }
    }
    if (function_exists('plaidAudit')) {
        plaidAudit('accounting.coa.bank_backfill', [
            'created'  => $created, 'errors' => $errs,
        ], null, [
            'tenant_id' => $tenantId,
            'actor_user_id' => (int) ($ctx['user']['id'] ?? 0),
            'source' => 'plaid_diagnostics',
        ]);
    }
    api_ok([
        'missing_count'   => count($missing),
        'created'         => $created,
        'errors'          => $errs,
    ]);
}

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$pdo = getDB();

$items = $pdo->prepare(
    "SELECT id, item_id, institution_id, institution_name, products_json,
            purpose, status, last_webhook_at, last_error_code, last_error_message,
            created_at, updated_at
       FROM plaid_items WHERE tenant_id = :t ORDER BY id DESC"
);
$items->execute(['t' => $tenantId]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$accounts = $pdo->prepare(
    "SELECT id, plaid_item_pk, account_id, name, official_name, mask, type, subtype,
            created_at, updated_at
       FROM plaid_accounts WHERE tenant_id = :t ORDER BY plaid_item_pk DESC, id"
);
$accounts->execute(['t' => $tenantId]);
$accounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

$bankRows = $pdo->prepare(
    "SELECT id, name, gl_account_code, bank_name, last4, currency, feed_provider,
            status, plaid_account_id, last_feed_synced_at, created_at
       FROM accounting_bank_accounts
      WHERE tenant_id = :t AND plaid_account_id IS NOT NULL"
);
$bankRows->execute(['t' => $tenantId]);
$bankRows = $bankRows->fetchAll(PDO::FETCH_ASSOC);

// treasury_liability_accounts may not have plaid_account_id yet (migration 002
// pending); guard the query.
$liabRows = [];
try {
    $col = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name   = 'treasury_liability_accounts'
            AND column_name  = 'plaid_account_id'"
    );
    $col->execute();
    if ((int) $col->fetchColumn() > 0) {
        $stmt = $pdo->prepare(
            "SELECT id, account_id, subtype, institution_name, last4, plaid_account_id, created_at
               FROM treasury_liability_accounts
              WHERE tenant_id = :t AND plaid_account_id IS NOT NULL"
        );
        $stmt->execute(['t' => $tenantId]);
        $liabRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) { /* table may not exist on a fresh tenant */ }

// Compute orphans: plaid_accounts whose account_id isn't mirrored.
$mirroredAccIds = [];
foreach ($bankRows as $r)  $mirroredAccIds[$r['plaid_account_id']] = true;
foreach ($liabRows as $r)  $mirroredAccIds[$r['plaid_account_id']] = true;
$orphans = array_values(array_filter($accounts, fn ($a) => !isset($mirroredAccIds[$a['account_id']])));

api_ok([
    'plaid_items'                              => $items,
    'plaid_accounts'                           => $accounts,
    'accounting_bank_accounts_for_plaid'       => $bankRows,
    'treasury_liability_accounts_for_plaid'    => $liabRows,
    'orphaned_plaid_accounts'                  => $orphans,
    'tenant_id'                                => $tenantId,
    'as_of'                                    => date('c'),
]);
