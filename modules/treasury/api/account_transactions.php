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

if (api_method() === 'POST' && (string) ($_GET['action'] ?? '') === 'sync') {
    require_once __DIR__ . '/../../../core/plaid_service.php';

    $body   = api_json_body();
    $itemPk = (int) ($body['plaid_item_pk'] ?? 0);
    if ($itemPk <= 0) api_error('plaid_item_pk required', 422);

    $item = scopedFind(
        'SELECT * FROM plaid_items WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $itemPk]
    );
    if (!$item) api_error('Plaid item not found', 404);

    // Inline-call the existing sync endpoint logic by setting up a sub-request
    // — simpler than a network call, identical behaviour, gets the same auth.
    $_GET    = [];
    $_POST   = [];
    $bodyJson = json_encode(['item_id' => $item['item_id']]);
    file_put_contents('php://temp', $bodyJson);
    // Reset the api_json_body cache by re-passing the body via a global.
    $GLOBALS['__plaid_sync_inline_body'] = $bodyJson;

    // Direct include — re-uses auth context already established.
    // Use ob_start to swallow the api_ok JSON and capture the result.
    ob_start();
    try {
        // The endpoint reads api_json_body() which reads php://input.
        // Rewriting php://input is tricky, so we instead duplicate the
        // sync logic here at a high level: just hand off to the same code
        // by re-defining api_json_body before include. Cleanest path: shell
        // out via cURL to localhost would require auth cookies... so we do
        // a minimal direct call.
        $apiUrl = (function () {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
            return "{$proto}://{$host}/api/plaid_sync_transactions.php";
        })();

        $cookieHeader = '';
        foreach ($_COOKIE as $k => $v) $cookieHeader .= urlencode($k) . '=' . urlencode($v) . '; ';

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bodyJson,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_COOKIE         => rtrim($cookieHeader, '; '),
            CURLOPT_TIMEOUT        => 60,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        ob_end_clean();

        $data = $raw ? json_decode($raw, true) : null;
        if ($code >= 400) api_error($data['error'] ?? 'Sync failed', $code, $data ?: null);
        api_ok($data ?: []);
    } catch (\Throwable $e) {
        ob_end_clean();
        api_error('Sync failed: ' . $e->getMessage(), 500);
    }
}

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

// Locate the Plaid item pk for the "Sync now" button (if this account is Plaid-linked).
$plaidItemPk = null;
$plaidAccountId = null;
if ($type === 'deposit') {
    $row = $pdo->prepare(
        'SELECT pa.plaid_item_pk, pa.account_id
           FROM accounting_bank_accounts ba
           JOIN plaid_accounts pa
             ON pa.tenant_id = ba.tenant_id AND pa.account_id = ba.plaid_account_id
          WHERE ba.tenant_id = :t AND ba.id = :id LIMIT 1'
    );
    $row->execute(['t' => $tenantId, 'id' => $accountId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r) { $plaidItemPk = (int) $r['plaid_item_pk']; $plaidAccountId = (string) $r['account_id']; }
} else {
    try {
        $row = $pdo->prepare(
            'SELECT pa.plaid_item_pk, pa.account_id
               FROM treasury_liability_accounts tla
               JOIN plaid_accounts pa
                 ON pa.tenant_id = tla.tenant_id AND pa.account_id = tla.plaid_account_id
              WHERE tla.tenant_id = :t AND tla.account_id = :id LIMIT 1'
        );
        $row->execute(['t' => $tenantId, 'id' => $accountId]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if ($r) { $plaidItemPk = (int) $r['plaid_item_pk']; $plaidAccountId = (string) $r['account_id']; }
    } catch (\Throwable $_) {}
}

api_ok([
    'rows'             => $rows,
    'count'            => $count,
    'inflow_total'     => round($inflow, 2),
    'outflow_total'    => round($outflow, 2),
    'plaid_item_pk'    => $plaidItemPk,
    'plaid_account_id' => $plaidAccountId,
]);
