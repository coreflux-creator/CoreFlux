<?php
/**
 * Mercury Foundation smoke test (Slice 1).
 *
 * Coverage:
 *   - Migration 048 shape (3 tables, idempotency UNIQUEs, utf8mb4_unicode_ci)
 *   - core/mercury_adapter.php contract (mercuryApiBase env routing, mercuryCall
 *     transport seam, three list/get functions, MercuryApiException class)
 *   - core/mercury_service.php contract (5 stateful helpers + graceful degrade
 *     when migration not applied + account-list + transaction-sync upserts)
 *   - 3 API endpoints (RBAC gate, GET/POST routing, audit emission)
 *   - cron/mercury_transactions_sync.php contract
 *   - UI JSX (MercurySettings + TreasuryModule wiring)
 *   - Functional adapter round-trip via injected transport stub (no live HTTP)
 *
 * NEVER hits live Mercury — uses $GLOBALS['__mercury_transport'] stub.
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$c = fn (string $h, string $n): bool => strpos($h, $n) !== false;

// ----------------------------------------------------------------- Migration
echo "Migration 048_mercury_foundation.sql\n";
$migPath = __DIR__ . '/../core/migrations/048_mercury_foundation.sql';
$a('migration file exists', is_file($migPath));
$mig = (string) file_get_contents($migPath);
$a('mercury_connections table',           $c($mig, 'CREATE TABLE IF NOT EXISTS mercury_connections'));
$a('mercury_accounts table',              $c($mig, 'CREATE TABLE IF NOT EXISTS mercury_accounts'));
$a('mercury_transactions table',          $c($mig, 'CREATE TABLE IF NOT EXISTS mercury_transactions'));
$a('api_token_ct VARBINARY (encrypted)',  $c($mig, 'api_token_ct      VARBINARY(512) NOT NULL'));
$a('api_token_last4 masked column',       $c($mig, 'api_token_last4   VARCHAR(8) NOT NULL'));
$a('connection unique per tenant',        $c($mig, 'UNIQUE KEY uq_mcon_tenant (tenant_id)'));
$a('connection status enum (active/revoked/error)',
    $c($mig, "ENUM('active','revoked','error')"));
$a('accounts unique on (tenant, mercury_account_id)',
    $c($mig, 'UNIQUE KEY uq_mac_account (tenant_id, mercury_account_id)'));
$a('balances stored as cents (BIGINT)',
    $c($mig, 'available_balance_cents BIGINT') && $c($mig, 'current_balance_cents   BIGINT'));
$a('transactions unique on (tenant, mercury_txn_id)',
    $c($mig, 'UNIQUE KEY uq_mtx_id       (tenant_id, mercury_txn_id)'));
$a('transactions payload_json JSON',      $c($mig, 'payload_json      JSON'));
$a('transactions amount_cents (signed BIGINT)',
    $c($mig, 'amount_cents      BIGINT NOT NULL'));
$a('utf8mb4_unicode_ci (no 0900_ai_ci)',
    $c($mig, 'utf8mb4_unicode_ci') && stripos($mig, 'utf8mb4_0900_ai_ci') === false);

// ----------------------------------------------------------------- core/mercury_adapter.php
echo "\ncore/mercury_adapter.php\n";
$advPath = __DIR__ . '/../core/mercury_adapter.php';
$a('adapter file exists', is_file($advPath));
$adv = (string) file_get_contents($advPath);
$a('MercuryApiException class',           $c($adv, 'class MercuryApiException extends \RuntimeException'));
$a('mercuryApiBase() env-driven',         $c($adv, 'function mercuryApiBase'));
$a('production base URL',                 $c($adv, 'https://api.mercury.com/api/v1'));
$a('sandbox base URL',                    $c($adv, 'https://api.sandbox.mercury.com/api/v1'));
$a('MERCURY_API_BASE env override',       $c($adv, 'MERCURY_API_BASE'));
$a('mercuryCall() single chokepoint',     $c($adv, 'function mercuryCall'));
$a('Bearer token header',                 $c($adv, "'Authorization: Bearer ' . \$apiToken"));
$a('test transport seam',                 $c($adv, "\$GLOBALS['__mercury_transport']"));
$a('mercuryListAccounts()',               $c($adv, 'function mercuryListAccounts'));
$a('mercuryGetAccount()',                 $c($adv, 'function mercuryGetAccount'));
$a('mercuryListTransactions()',           $c($adv, 'function mercuryListTransactions'));
$a('transaction query string knobs',
    $c($adv, "['limit', 'offset', 'start', 'end', 'order', 'status']"));
$a('SSL verifypeer enabled by default',   $c($adv, 'CURLOPT_SSL_VERIFYPEER => true'));
$a('JSON-only response handling',         $c($adv, "'Accept: application/json'"));

// ----------------------------------------------------------------- core/mercury_service.php
echo "\ncore/mercury_service.php\n";
$svcPath = __DIR__ . '/../core/mercury_service.php';
$a('service file exists', is_file($svcPath));
$svc = (string) file_get_contents($svcPath);
$a('requires encryption.php',                    $c($svc, "require_once __DIR__ . '/encryption.php'"));
$a('requires mercury_adapter.php',               $c($svc, "require_once __DIR__ . '/mercury_adapter.php'"));
$a('mercuryGetConnection() exported',            $c($svc, 'function mercuryGetConnection(int $tenantId)'));
$a('mercuryStoreConnection() exported',          $c($svc, 'function mercuryStoreConnection'));
$a('mercuryRevokeConnection() exported',         $c($svc, 'function mercuryRevokeConnection(int $tenantId)'));
$a('mercuryFlagConnectionError() exported',      $c($svc, 'function mercuryFlagConnectionError'));
$a('mercurySyncAccounts() exported',             $c($svc, 'function mercurySyncAccounts(int $tenantId)'));
$a('mercurySyncAccountsFromList() exported',     $c($svc, 'function mercurySyncAccountsFromList'));
$a('mercurySyncAccountTransactions() exported',  $c($svc, 'function mercurySyncAccountTransactions'));
$a('encrypts token before storing',
    $c($svc, '$ct    = encryptField($apiToken)'));
$a('stores last4 separately for masking',
    $c($svc, "\$last4 = substr(\$apiToken, -4)"));
$a('probes BEFORE persisting',
    $c($svc, 'Probe BEFORE persisting') && $c($svc, 'mercuryListAccounts($apiToken)'));
$a('upsert via ON DUPLICATE KEY',                $c($svc, 'ON DUPLICATE KEY UPDATE'));
$a('soft-revoke preserves audit row',
    $c($svc, "SET status='revoked'") && !$c($svc, 'DELETE FROM mercury_connections'));
$a('accounts upsert idempotent',                 $c($svc, 'ON DUPLICATE KEY UPDATE') && $c($svc, 'mercury_accounts'));
$a('transactions insert IGNORE (idempotent)',
    $c($svc, 'INSERT IGNORE INTO mercury_transactions'));
$a('graceful degrade when migration not applied (try/catch)',
    $c($svc, 'Migration 048 may not be run yet'));
$a('flags connection error on adapter failure',
    $c($svc, 'mercuryFlagConnectionError($tenantId'));
$a('converts $ amounts to cents',
    $c($svc, '(int) round(((float) $a[\'availableBalance\']) * 100)'));

// ----------------------------------------------------------------- API: connection
echo "\napi/mercury_connection.php\n";
$cnPath = __DIR__ . '/../api/mercury_connection.php';
$a('connection API exists', is_file($cnPath));
$cn = (string) file_get_contents($cnPath);
$a('RBAC: accounting.bank.manage',
    $c($cn, "RBAC::requirePermission(\$user, 'accounting.bank.manage')"));
$a('GET ?action=status returns connected flag',
    $c($cn, "api_ok(['connected' => false])") && $c($cn, "'connected'        => \$row['status'] === 'active'"));
$a('POST default action probes + persists',
    $c($cn, 'mercuryStoreConnection($tenantId, $token'));
$a('POST validates token length',
    $c($cn, 'strlen($token) < 16'));
$a('POST disconnect calls revoke',
    $c($cn, "\$action === 'disconnect'") && $c($cn, 'mercuryRevokeConnection($tenantId)'));
$a('audit mercury.connection.connected',         $c($cn, 'mercury.connection.connected'));
$a('audit mercury.connection.disconnected',      $c($cn, 'mercury.connection.disconnected'));
$a('audit mercury.connection.probe_failed',      $c($cn, 'mercury.connection.probe_failed'));
$a('rejects non-GET/POST',                       $c($cn, "Method not allowed', 405"));

// ----------------------------------------------------------------- API: accounts
echo "\napi/mercury_accounts.php\n";
$acPath = __DIR__ . '/../api/mercury_accounts.php';
$ac = (string) file_get_contents($acPath);
$a('GET reads cached mercury_accounts',
    $c($ac, 'FROM mercury_accounts WHERE tenant_id'));
$a('POST ?action=sync calls mercurySyncAccounts',
    $c($ac, "\$action === 'sync'") && $c($ac, 'mercurySyncAccounts($tenantId)'));
$a('GET RBAC accepts bank.view OR bank.manage',
    $c($ac, "hasPermission(\$user, 'accounting.bank.view')") &&
    $c($ac, "hasPermission(\$user, 'accounting.bank.manage')"));
$a('POST RBAC: accounting.bank.manage',
    $c($ac, "RBAC::requirePermission(\$user, 'accounting.bank.manage')"));
$a('graceful degrade when migration missing',
    $c($ac, '$rows = []'));

// ----------------------------------------------------------------- API: transactions
echo "\napi/mercury_transactions.php\n";
$txPath = __DIR__ . '/../api/mercury_transactions.php';
$tx = (string) file_get_contents($txPath);
$a('GET filters by account_pk',                  $c($tx, '$accountPk = (int) ($_GET[\'account_pk\'] ?? 0)'));
$a('GET enforces limit cap (max 200)',           $c($tx, 'min(200,'));
$a('GET orders by posted_at DESC',
    $c($tx, 'ORDER BY COALESCE(posted_at, received_at) DESC'));
$a('POST ?action=sync calls mercurySyncAccountTransactions',
    $c($tx, "\$action === 'sync'") && $c($tx, 'mercurySyncAccountTransactions($tenantId, $accountPk'));
$a('POST requires account_pk',                   $c($tx, "'account_pk required'"));
$a('passes through limit/start/end opts',
    $c($tx, "['limit', 'start', 'end', 'order', 'status']"));

// ----------------------------------------------------------------- cron
echo "\ncron/mercury_transactions_sync.php\n";
$crPath = __DIR__ . '/../cron/mercury_transactions_sync.php';
$a('cron exists', is_file($crPath));
$cr = (string) file_get_contents($crPath);
$a('iterates active connections',
    $c($cr, "FROM mercury_connections WHERE status = 'active'"));
$a('refreshes accounts before transactions',
    $c($cr, 'mercurySyncAccounts($tid)'));
$a('per-account transaction sync',               $c($cr, 'mercurySyncAccountTransactions($tid, (int) $apk'));
$a('limits per account (200)',                   $c($cr, "'limit' => \$LIMIT_PER_ACCOUNT"));
$a('graceful skip when migration absent',
    $c($cr, 'migration 048 not applied yet'));
$a('per-tenant try/catch (one tenant fail ≠ abort cron)',
    $c($cr, '$tenantsFailed++'));
$a('exit code reflects failures',                $c($cr, 'exit($tenantsFailed > 0 ? 1 : 0)'));

// ----------------------------------------------------------------- UI: MercurySettings
echo "\nUI — MercurySettings.jsx\n";
$msPath = __DIR__ . '/../modules/treasury/ui/MercurySettings.jsx';
$a('UI file exists', is_file($msPath));
$ms = (string) file_get_contents($msPath);
$a('reads status via useApi',
    $c($ms, "useApi('/api/mercury_connection.php?action=status')"));
$a('reads accounts via useApi',                  $c($ms, "useApi('/api/mercury_accounts.php')"));
$a('POSTs to /api/mercury_connection.php',
    $c($ms, "api.post('/api/mercury_connection.php', {"));
$a('disconnect action wired',                    $c($ms, '/api/mercury_connection.php?action=disconnect'));
$a('sync accounts action wired',                 $c($ms, '/api/mercury_accounts.php?action=sync'));
$a('not-connected form testid',                  $c($ms, 'data-testid="mercury-connect-form"'));
$a('connected card testid',                      $c($ms, 'data-testid="mercury-connected"'));
$a('token input is type=password',               $c($ms, "type=\"password\""));
$a('token input testid',                         $c($ms, 'data-testid="mercury-token-input"'));
$a('connect button testid',                      $c($ms, 'data-testid="mercury-connect-btn"'));
$a('disconnect button testid',                   $c($ms, 'data-testid="mercury-disconnect-btn"'));
$a('sync-accounts button testid',                $c($ms, 'data-testid="mercury-sync-accounts-btn"'));
$a('accounts table testid',                      $c($ms, 'data-testid="mercury-accounts-table"'));
$a('empty state testid',                         $c($ms, 'data-testid="mercury-accounts-empty"'));
$a('confirm dialog before disconnect',           $c($ms, 'window.confirm'));
$a('token last4 masked display',
    $c($ms, '••••') && $c($ms, 'mercury-token-last4'));
$a('Mercury app token URL linked',
    $c($ms, 'https://app.mercury.com/settings/tokens'));
$a('shows last_probe_error when set',            $c($ms, 'data-testid="mercury-probe-error"'));

// ----------------------------------------------------------------- UI: TreasuryModule wiring
echo "\nUI — TreasuryModule.jsx wiring\n";
$tmPath = __DIR__ . '/../modules/treasury/ui/TreasuryModule.jsx';
$tm = (string) file_get_contents($tmPath);
$a('imports MercurySettings',                    $c($tm, "import MercurySettings from './MercurySettings'"));
$a('mounted inside payout-rails route alongside Plaid',
    $c($tm, '<MercurySettings />') && $c($tm, '<PlaidTransferSettings />'));

// ----------------------------------------------------------------- functional adapter round-trip via stub
echo "\nFunctional — adapter via injected transport stub\n";
require_once $advPath;
$captured = [];
$GLOBALS['__mercury_transport'] = function (string $method, string $url, array $headers, ?string $body) use (&$captured) {
    $captured[] = compact('method', 'url', 'headers', 'body');
    if (strpos($url, '/accounts') !== false && strpos($url, '/transactions') === false) {
        return ['status' => 200, 'body' => json_encode([
            'accounts' => [
                ['id' => 'acc_001', 'name' => 'Ops Checking', 'accountNumber' => '987654321',
                 'routingNumber' => '021000021', 'kind' => 'checking', 'status' => 'active',
                 'availableBalance' => 12500.50, 'currentBalance' => 12700.00, 'currency' => 'USD'],
            ],
        ])];
    }
    if (strpos($url, '/transactions') !== false) {
        return ['status' => 200, 'body' => json_encode([
            'transactions' => [
                ['id' => 'tx_001', 'amount' => -250.00, 'postedAt' => '2026-02-15T10:00:00Z',
                 'status' => 'sent', 'kind' => 'externalTransfer', 'counterpartyName' => 'ACME LLC'],
                ['id' => 'tx_002', 'amount' => 1500.00, 'postedAt' => '2026-02-14T09:00:00Z',
                 'status' => 'sent', 'kind' => 'incomingTransfer'],
            ],
        ])];
    }
    return ['status' => 404, 'body' => '{"error":"unknown stub path"}'];
};

$listResp = mercuryListAccounts('secret-token:abc12345...');
$a('mercuryListAccounts returns accounts array',
    is_array($listResp['accounts'] ?? null) && count($listResp['accounts']) === 1);
$a('transport seam captured Bearer header',
    !empty($captured) && in_array('Authorization: Bearer secret-token:abc12345...', $captured[0]['headers'] ?? [], true));
$a('transport seam hit /accounts URL',
    !empty($captured) && strpos((string) $captured[0]['url'], '/api/v1/accounts') !== false);

$txResp = mercuryListTransactions('secret-token:abc12345...', 'acc_001', ['limit' => 10]);
$a('mercuryListTransactions returns 2 transactions',
    is_array($txResp['transactions'] ?? null) && count($txResp['transactions']) === 2);
$a('transactions URL includes account id',
    strpos((string) end($captured)['url'], '/account/acc_001/transactions') !== false);
$a('transactions URL appends limit query',
    strpos((string) end($captured)['url'], 'limit=10') !== false);

// 4xx error path
$GLOBALS['__mercury_transport'] = function () {
    return ['status' => 401, 'body' => json_encode(['error' => 'invalid_token'])];
};
$threw = false; $errMsg = '';
try {
    mercuryListAccounts('bad-token');
} catch (MercuryApiException $e) {
    $threw = true; $errMsg = $e->getMessage();
}
$a('401 from Mercury raises MercuryApiException', $threw && strpos($errMsg, 'invalid_token') !== false);

// Invalid JSON path
$GLOBALS['__mercury_transport'] = function () {
    return ['status' => 200, 'body' => 'not json at all'];
};
$threw2 = false;
try { mercuryListAccounts('any'); } catch (MercuryApiException $e) { $threw2 = true; }
$a('malformed JSON raises MercuryApiException',  $threw2);

unset($GLOBALS['__mercury_transport']);

// ----------------------------------------------------------------- syntax sanity
echo "\nSyntax sanity (php -l)\n";
$phpFiles = [
    'core/mercury_adapter.php',
    'core/mercury_service.php',
    'api/mercury_connection.php',
    'api/mercury_accounts.php',
    'api/mercury_transactions.php',
    'cron/mercury_transactions_sync.php',
];
foreach ($phpFiles as $rel) {
    $p = __DIR__ . '/../' . $rel;
    $out = []; $rc = 0;
    @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
    $a("php -l {$rel}", $rc === 0);
}

echo "\n=========================================\n";
echo "Mercury Foundation smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
