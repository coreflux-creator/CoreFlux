<?php
/**
 * Smoke — Charter primitive #5 (verifyCreate) for QBO, Zoho Books, Mercury.
 *
 * Locks the contract:
 *   - core/integrations/verify_create.php exposes the three helpers with
 *     the canonical return shape {verified, downstream_status,
 *     expected_status, reason, fetched_at}.
 *   - sync_je/sync_bills/sync_invoices for QBO and Zoho call the helper
 *     after every successful POST and stamp the per-item result with
 *     `pushed` (verified) or `pushed_unverified` (post-create GET
 *     mismatch / failure).
 *   - Mercury's mpOriginateInternalTransfer / Funding / Payout all call
 *     mercuryVerifyCreate after mercuryCreatePayment and surface the
 *     verify shape on the audit/event payload.
 *
 * Run: php -d zend.assertions=1 /app/tests/integration_verify_create_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nverifyCreate (charter primitive #5) smoke\n";
echo "==========================================\n\n";

// ───────────────────────────── 1. Module presence & contract shape ──
echo "── helper module ──\n";
$verifyPath = '/app/core/integrations/verify_create.php';
check('core/integrations/verify_create.php exists', file_exists($verifyPath));
$src = file_exists($verifyPath) ? (string) file_get_contents($verifyPath) : '';
check('declares qboVerifyCreate()',         str_contains($src, 'function qboVerifyCreate('));
check('declares zohoBooksVerifyCreate()',   str_contains($src, 'function zohoBooksVerifyCreate('));
check('declares mercuryVerifyCreate()',     str_contains($src, 'function mercuryVerifyCreate('));
check('uses canonical return keys',
    str_contains($src, "'verified'") && str_contains($src, "'downstream_status'") &&
    str_contains($src, "'expected_status'") && str_contains($src, "'reason'") &&
    str_contains($src, "'fetched_at'"));

// ──────────────────────────── 2. Wiring into procedural sync paths ──
echo "\n── QBO sync wiring ──\n";
$qje  = (string) file_get_contents('/app/core/qbo/sync_je.php');
$qbi  = (string) file_get_contents('/app/core/qbo/sync_bills.php');
$qiv  = (string) file_get_contents('/app/core/qbo/sync_invoices.php');
check('sync_je.php requires verify_create.php',     str_contains($qje, "verify_create.php"));
check('sync_je.php calls qboVerifyCreate',          str_contains($qje, "qboVerifyCreate(\$tenantId, 'journal_entry'"));
check('sync_je.php stamps pushed_unverified',       str_contains($qje, "'pushed_unverified'"));
check('sync_bills.php calls qboVerifyCreate',       str_contains($qbi, "qboVerifyCreate(\$tenantId, 'bill'"));
check('sync_bills.php stamps pushed_unverified',    str_contains($qbi, "'pushed_unverified'"));
check('sync_invoices.php calls qboVerifyCreate',    str_contains($qiv, "qboVerifyCreate(\$tenantId, 'invoice'"));
check('sync_invoices.php stamps pushed_unverified', str_contains($qiv, "'pushed_unverified'"));
check('audit detail carries verify payload',        str_contains($qje, "'verify' => \$verify"));

echo "\n── Zoho Books sync wiring ──\n";
$zje  = (string) file_get_contents('/app/core/zoho_books/sync_je.php');
$zbi  = (string) file_get_contents('/app/core/zoho_books/sync_bills.php');
$ziv  = (string) file_get_contents('/app/core/zoho_books/sync_invoices.php');
check('sync_je.php requires verify_create.php',        str_contains($zje, "verify_create.php"));
check('sync_je.php calls zohoBooksVerifyCreate',       str_contains($zje, "zohoBooksVerifyCreate(\$tenantId, 'journal_entry'"));
check('sync_je.php stamps pushed_unverified',          str_contains($zje, "'pushed_unverified'"));
check('sync_bills.php calls zohoBooksVerifyCreate',    str_contains($zbi, "zohoBooksVerifyCreate(\$tenantId, 'bill'"));
check('sync_invoices.php calls zohoBooksVerifyCreate', str_contains($ziv, "zohoBooksVerifyCreate(\$tenantId, 'invoice'"));

echo "\n── Mercury wiring ──\n";
$mp = (string) file_get_contents('/app/core/mercury_payments.php');
check('mercury_payments.php requires verify_create.php', str_contains($mp, 'integrations/verify_create.php'));
check('mercury internal-transfer site calls mercuryVerifyCreate',
    preg_match('/internal transfer originated.*mercuryVerifyCreate/s', $mp) === 1);
check('mercury funding site calls mercuryVerifyCreate',
    preg_match('/funding originated.*mercuryVerifyCreate/s', $mp) === 1);
check('mercury payout site calls mercuryVerifyCreate',
    preg_match('/payout originated.*mercuryVerifyCreate/s', $mp) === 1);

// ─────────────────────── 3. Live shape exercise (stubbed transports) ──
echo "\n── helper return-shape exercise ──\n";

require_once $verifyPath;
$GLOBALS['pdo'] = new \PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo = $GLOBALS['pdo'];

// ─ QBO: not_connected when no row.
$pdo->exec("CREATE TABLE qbo_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, realm_id TEXT, company_name TEXT, environment TEXT, access_token_ct TEXT, refresh_token_ct TEXT, access_token_exp TEXT, refresh_token_exp TEXT, scope TEXT, status TEXT, sync_config TEXT, last_probe_at TEXT, last_probe_error TEXT, connected_by_user_id INT, created_at TEXT, updated_at TEXT)");
$r = qboVerifyCreate(9999, 'journal_entry', 'JE-42', 'active');
check('qboVerifyCreate not_connected when no row',     $r['downstream_status'] === 'not_connected');
check('qboVerifyCreate not_connected verified=false',  $r['verified'] === false);
check('qboVerifyCreate not_connected carries reason',  !empty($r['reason']));

// Insert an active row + stub transport for the GET path. Use real
// encryptField so qboAccessToken's decryptField step succeeds.
$encAt = encryptField('access-token-plain');
$encRt = encryptField('refresh-token-plain');
$pdo->prepare("INSERT INTO qbo_connections (tenant_id,realm_id,company_name,environment,access_token_ct,refresh_token_ct,access_token_exp,refresh_token_exp,scope,status,sync_config,connected_by_user_id,created_at,updated_at) VALUES (9999,'realm-xyz','Stub Co','sandbox',?,?,?,?,'com.intuit.quickbooks.accounting','active','{}',1,?,?)")
    ->execute([$encAt, $encRt, date('Y-m-d H:i:s', time()+3600), date('Y-m-d H:i:s', time()+86400), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);

$GLOBALS['__qbo_transport'] = function ($method, $url, $headers, $body) {
    return ['status' => 200, 'body' => ['JournalEntry' => ['Id' => 'JE-42']], 'headers' => []];
};
$r = qboVerifyCreate(9999, 'journal_entry', 'JE-42', 'active');
check('qboVerifyCreate happy path verified=true',   $r['verified'] === true);
check('qboVerifyCreate downstream_status=recorded', $r['downstream_status'] === 'recorded');
check('qboVerifyCreate carries fetched_at',         !empty($r['fetched_at']));

$GLOBALS['__qbo_transport'] = function ($method, $url, $headers, $body) {
    return ['status' => 200, 'body' => ['JournalEntry' => ['Id' => 'OTHER']], 'headers' => []];
};
$r = qboVerifyCreate(9999, 'journal_entry', 'JE-42', 'active');
check('qboVerifyCreate id-mismatch verified=false', $r['verified'] === false);
check('qboVerifyCreate id-mismatch reason set',     !empty($r['reason']));

$GLOBALS['__qbo_transport'] = function ($method, $url, $headers, $body) {
    return ['status' => 500, 'body' => 'boom', 'headers' => []];
};
$r = qboVerifyCreate(9999, 'journal_entry', 'JE-42', 'active');
check('qboVerifyCreate fetch_failed on 500',         $r['downstream_status'] === 'fetch_failed');
check('qboVerifyCreate fetch_failed verified=false', $r['verified'] === false);
unset($GLOBALS['__qbo_transport']);

// ─ Mercury (no encryption layer — cleanest end-to-end test).
//   Mercury's transport seam expects `body` to be the raw JSON string.
$GLOBALS['__mercury_transport'] = function ($method, $url, $headers, $body) {
    return ['status' => 200, 'body' => json_encode(['id' => 'pmt-1', 'status' => 'pending']), 'headers' => []];
};
$r = mercuryVerifyCreate('tok', 'acct-1', 'pmt-1', 'pending');
check('mercuryVerifyCreate happy path verified=true', $r['verified'] === true);
check('mercuryVerifyCreate downstream=pending',       $r['downstream_status'] === 'pending');

$GLOBALS['__mercury_transport'] = function ($method, $url, $headers, $body) {
    return ['status' => 200, 'body' => json_encode(['id' => 'pmt-1', 'status' => 'failed']), 'headers' => []];
};
$r = mercuryVerifyCreate('tok', 'acct-1', 'pmt-1', 'pending');
check('mercuryVerifyCreate failure-status verified=false', $r['verified'] === false);
check('mercuryVerifyCreate downstream=failed',             $r['downstream_status'] === 'failed');

$r = mercuryVerifyCreate('', '', '', 'pending');
check('mercuryVerifyCreate invalid inputs guarded',  $r['downstream_status'] === 'invalid_inputs');
unset($GLOBALS['__mercury_transport']);

// ─ Zoho Books: not_connected path is enough to exercise the contract
//   (the live GET path requires the Zoho token-refresh dance which is
//   out of scope for a smoke test).
$pdo->exec("CREATE TABLE zoho_books_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, sub_tenant_id INT, organization_id TEXT, organization_name TEXT, dc TEXT, access_token_ct TEXT, refresh_token_ct TEXT, access_token_exp TEXT, scope TEXT, status TEXT, sync_config TEXT, last_probe_at TEXT, last_probe_error TEXT, connected_by_user_id INT, created_at TEXT, updated_at TEXT)");
$r = zohoBooksVerifyCreate(9999, 'journal_entry', 'zo-1', 'active');
check('zohoBooksVerifyCreate not_connected when no row',    $r['downstream_status'] === 'not_connected');
check('zohoBooksVerifyCreate not_connected verified=false', $r['verified'] === false);
check('zohoBooksVerifyCreate not_connected carries reason', !empty($r['reason']));

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "verify_create smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
