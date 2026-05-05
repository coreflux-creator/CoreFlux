<?php
/**
 * Treasury — Account Transactions API.
 *
 *   GET ?account_id=N&type=deposit|liability[&limit=100]
 *
 * Returns the flat list of statement / Plaid-fed lines for either a deposit
 * (accounting_bank_accounts) or liability (accounting_accounts where
 * type='liability') account, newest first. Used by the deposit / liability
 * detail drawers in Treasury so users can see the actual feed data.
 *
 *   POST ?action=sync (body: { plaid_item_pk: int })
 *
 * Convenience trigger that calls /api/plaid_sync_transactions.php for the
 * given Plaid item PK so users can refresh from the same place they're
 * viewing the data.
 *
 * Permission: `accounting.bank.manage`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
RBAC::requirePermission($ctx['user'], 'accounting.bank.manage');
$pdo = getDB();

if (api_method() === 'POST') api_error(
    'POST not supported here. Sync via /api/plaid_sync_transactions.php with '
    . '{ item_id: <plaid_item_external_id> } — exposed in this endpoint\'s GET response.',
    405
);

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$accountId = (int) ($_GET['account_id'] ?? 0);
$type      = (string) ($_GET['type']     ?? 'deposit');
$limit     = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
if ($accountId <= 0) api_error('account_id required', 422);
if (!in_array($type, ['deposit', 'liability'], true)) {
    api_error("type must be 'deposit' or 'liability'", 422);
}

if ($type === 'deposit') {
    $stmt = $pdo->prepare(
        'SELECT id, posted_date, description, amount, bank_reference, fitid,
                match_status, matched_je_id, created_at,
                NULL AS merchant_name, NULL AS category
           FROM accounting_bank_statement_lines
          WHERE tenant_id = :t AND bank_account_id = :a
          ORDER BY posted_date DESC, id DESC
          LIMIT ' . $limit
    );
} else {
    // Auto-create the table if a tenant hasn't run migration 003 yet —
    // mirrors the sync-endpoint guard so the first GET on a fresh deploy
    // doesn't 500 with "table not found".
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS treasury_liability_statement_lines (
                id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id             INT UNSIGNED NOT NULL,
                liability_account_id  BIGINT UNSIGNED NOT NULL,
                posted_date           DATE NOT NULL,
                description           VARCHAR(255) NULL,
                amount                DECIMAL(18,2) NOT NULL,
                merchant_name         VARCHAR(255) NULL,
                category              VARCHAR(120) NULL,
                bank_reference        VARCHAR(120) NULL,
                fitid                 VARCHAR(120) NULL,
                match_status          ENUM('unmatched','matched','ignored') NOT NULL DEFAULT 'unmatched',
                created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tlsl_fitid (tenant_id, liability_account_id, fitid),
                INDEX idx_tlsl_acct_date (tenant_id, liability_account_id, posted_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (\Throwable $_) {}

    $stmt = $pdo->prepare(
        'SELECT id, posted_date, description, amount, bank_reference, fitid,
                merchant_name, category, match_status, NULL AS matched_je_id, created_at
           FROM treasury_liability_statement_lines
          WHERE tenant_id = :t AND liability_account_id = :a
          ORDER BY posted_date DESC, id DESC
          LIMIT ' . $limit
    );
}
$stmt->execute(['t' => $tenantId, 'a' => $accountId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary so the UI can render headline stats without re-summing client-side.
$count   = count($rows);
$inflow  = 0.0;
$outflow = 0.0;
foreach ($rows as $r) {
    $a = (float) $r['amount'];
    if ($a >= 0) $inflow  += $a;
    else         $outflow += abs($a);
}

// Locate the Plaid item for the "Sync from Plaid" button (if linked).
// Returns the Plaid string item_id so the UI can call /api/plaid_sync_transactions.php
// directly — no localhost proxy, no curl-back, no cookie round-trip.
$plaidItemPk        = null;
$plaidItemExternalId = null;
$plaidAccountId     = null;
if ($type === 'deposit') {
    $row = $pdo->prepare(
        'SELECT pi.id AS pk, pi.item_id AS external_id, pa.account_id
           FROM accounting_bank_accounts ba
           JOIN plaid_accounts pa
             ON pa.tenant_id = ba.tenant_id AND pa.account_id = ba.plaid_account_id
           JOIN plaid_items   pi
             ON pi.id = pa.plaid_item_pk AND pi.tenant_id = pa.tenant_id
          WHERE ba.tenant_id = :t AND ba.id = :id LIMIT 1'
    );
    $row->execute(['t' => $tenantId, 'id' => $accountId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $plaidItemPk         = (int) $r['pk'];
        $plaidItemExternalId = (string) $r['external_id'];
        $plaidAccountId      = (string) $r['account_id'];
    }
} else {
    try {
        $row = $pdo->prepare(
            'SELECT pi.id AS pk, pi.item_id AS external_id, pa.account_id
               FROM treasury_liability_accounts tla
               JOIN plaid_accounts pa
                 ON pa.tenant_id = tla.tenant_id AND pa.account_id = tla.plaid_account_id
               JOIN plaid_items   pi
                 ON pi.id = pa.plaid_item_pk AND pi.tenant_id = pa.tenant_id
              WHERE tla.tenant_id = :t AND tla.account_id = :id LIMIT 1'
        );
        $row->execute(['t' => $tenantId, 'id' => $accountId]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $plaidItemPk         = (int) $r['pk'];
            $plaidItemExternalId = (string) $r['external_id'];
            $plaidAccountId      = (string) $r['account_id'];
        }
    } catch (\Throwable $_) {}
}

api_ok([
    'rows'                  => $rows,
    'count'                 => $count,
    'inflow_total'          => round($inflow, 2),
    'outflow_total'         => round($outflow, 2),
    'plaid_item_pk'         => $plaidItemPk,
    'plaid_item_external_id'=> $plaidItemExternalId,
    'plaid_account_id'      => $plaidAccountId,
]);
