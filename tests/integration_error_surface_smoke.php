<?php
/**
 * Smoke — Charter Primitive #6 (full vendor error surface) for Zoho + Mercury.
 *
 * Charter primitive #6 says: when a vendor rejects a write, CoreFlux must
 * capture the raw response body so an operator can see exactly what they
 * sent. Without this, "Invalid request body" is unactionable.
 *
 * Locks:
 *   - core/zoho_books/client.php declares ZohoBooksApiException with
 *     httpStatus / errorCode / raw fields and throws it on >= 400.
 *   - core/mercury_adapter.php already declares MercuryApiException with
 *     httpStatus / errorCode / raw fields.
 *   - All Zoho sync drivers (sync_je/bills/invoices) catch the typed
 *     exception and persist {vendor_http_status, vendor_error_code,
 *     vendor_raw} into both the result row and the audit log.
 *   - All Mercury originate sites (internal transfer / funding / payout)
 *     persist {vendor_error_code, vendor_raw} into the mp_event detail.
 *
 * Live exercise: drive each client through a stubbed transport that
 * returns a 4xx and confirm the exception carries the raw body verbatim
 * (truncated to charter-mandated 600 chars).
 *
 * Run: php -d zend.assertions=1 /app/tests/integration_error_surface_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nverify error_surface (charter primitive #6) smoke\n";
echo "==================================================\n\n";

// ──────────────────────────── 1. Exception class declarations ──
echo "── exception classes ──\n";
$zSrc = (string) file_get_contents('/app/core/zoho_books/client.php');
$mSrc = (string) file_get_contents('/app/core/mercury_adapter.php');
check('ZohoBooksApiException declared',  str_contains($zSrc, 'class ZohoBooksApiException'));
check('ZohoBooksApiException ->httpStatus', preg_match('/class ZohoBooksApiException.*public \?int\s+\$httpStatus/s', $zSrc) === 1);
check('ZohoBooksApiException ->errorCode',  preg_match('/class ZohoBooksApiException.*public \?string\s+\$errorCode/s', $zSrc) === 1);
check('ZohoBooksApiException ->raw',        preg_match('/class ZohoBooksApiException.*public \?array\s+\$raw/s', $zSrc) === 1);
check('MercuryApiException ->raw',          preg_match('/class MercuryApiException.*public \?array\s+\$raw/s', $mSrc) === 1);
check('MercuryApiException ->errorCode',    preg_match('/class MercuryApiException.*public \?string\s+\$errorCode/s', $mSrc) === 1);

// ──────────────────────────── 2. Throw-site wiring ──
echo "\n── throw sites ──\n";
check('zohoBooksCall throws ZohoBooksApiException on >= 400',
    preg_match('/\$resp\[.status.\] >= 400.*new ZohoBooksApiException/s', $zSrc) === 1);
check('zohoBooksCall stamps httpStatus on the exception',
    str_contains($zSrc, '$ex->httpStatus'));
check('zohoBooksCall stamps raw[body] (truncated)',
    str_contains($zSrc, "'body' => substr(\$rawBody, 0, 600)"));
check('mercuryCall stamps raw on >= 400',
    preg_match('/status.*>= 400.*new MercuryApiException.*raw\s*=/s', $mSrc) === 1);

// ──────────────────────────── 3. Catch-site capture into audit ──
echo "\n── Zoho sync-driver catch sites ──\n";
foreach (['sync_je', 'sync_bills', 'sync_invoices'] as $f) {
    $src = (string) file_get_contents('/app/core/zoho_books/' . $f . '.php');
    check("{$f}.php inspects ZohoBooksApiException::\$raw",
        str_contains($src, 'instanceof ZohoBooksApiException'));
    check("{$f}.php persists vendor_raw to audit detail",
        str_contains($src, "'vendor_raw'"));
    check("{$f}.php persists vendor_http_status to audit detail",
        str_contains($src, "'vendor_http_status'"));
    check("{$f}.php surfaces vendor on result row",
        str_contains($src, "'vendor'"));
}

echo "\n── Mercury catch sites ──\n";
$mp = (string) file_get_contents('/app/core/mercury_payments.php');
check('internal-transfer catch surfaces vendor_raw',
    preg_match('/internal transfer originate failed.*vendor_raw/s', $mp) === 1);
check('funding-originate catch surfaces vendor_raw',
    preg_match('/funding originate failed.*vendor_raw/s', $mp) === 1);
check('payout-originate catch surfaces vendor_raw',
    preg_match('/payout originate failed.*vendor_raw/s', $mp) === 1);
check('Mercury catch sites also persist vendor_error_code',
    substr_count($mp, "'vendor_error_code'") >= 3);

// ──────────────────────────── 4. Live shape via stubbed transport ──
echo "\n── live exception shape (stubbed transports) ──\n";
require_once '/app/core/zoho_books/client.php';
require_once '/app/core/mercury_adapter.php';

$GLOBALS['pdo'] = new \PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo = $GLOBALS['pdo'];

// Zoho — minimal connection row + transport that returns 422.
$pdo->exec("CREATE TABLE zoho_books_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, sub_tenant_id INT, organization_id TEXT, organization_name TEXT, dc TEXT, access_token_ct TEXT, refresh_token_ct TEXT, access_token_exp TEXT, scope TEXT, status TEXT, sync_config TEXT, last_probe_at TEXT, last_probe_error TEXT, connected_by_user_id INT, created_at TEXT, updated_at TEXT)");
$encAt = encryptField('z-access-token');
$encRt = encryptField('z-refresh-token');
$pdo->prepare("INSERT INTO zoho_books_connections (tenant_id, sub_tenant_id, organization_id, organization_name, dc, access_token_ct, refresh_token_ct, access_token_exp, scope, status, sync_config, connected_by_user_id, created_at, updated_at) VALUES (9999, 9999, 'org-1', 'Stub Co', 'com', ?, ?, ?, 'ZohoBooks.fullaccess.all', 'active', '{}', 1, ?, ?)")
    ->execute([$encAt, $encRt, date('Y-m-d H:i:s', time()+3600), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);

$rawBody = '{"code":4001,"message":"Required field invoice_date is missing","details":[{"field":"invoice_date"}]}';
$GLOBALS['__zoho_books_transport'] = function ($method, $url, $headers, $body) use ($rawBody) {
    return ['status' => 422, 'body' => json_decode($rawBody, true), 'headers' => []];
};

$caughtZ = null;
try {
    zohoBooksCall(9999, 'POST', '/books/v3/invoices', ['line_items' => []]);
} catch (ZohoBooksApiException $e) {
    $caughtZ = $e;
}
check('zohoBooksCall throws ZohoBooksApiException on 422', $caughtZ instanceof ZohoBooksApiException);
check('exception ->httpStatus === 422',                    $caughtZ && $caughtZ->httpStatus === 422);
check('exception ->errorCode carries Zoho code',           $caughtZ && $caughtZ->errorCode === '4001');
check('exception ->raw[body] carries the full body',
    $caughtZ && is_array($caughtZ->raw) && str_contains((string) $caughtZ->raw['body'], 'Required field invoice_date is missing'));
check('exception ->raw[body] truncated to 600 chars',
    $caughtZ && strlen((string) $caughtZ->raw['body']) <= 600);
unset($GLOBALS['__zoho_books_transport']);

// Mercury — transport that returns 400.
$mrawBody = '{"code":"invalid_recipient","message":"Recipient does not exist","details":{"recipientId":"rec_bad"}}';
$GLOBALS['__mercury_transport'] = function ($method, $url, $headers, $body) use ($mrawBody) {
    return ['status' => 400, 'body' => $mrawBody, 'headers' => []];
};

$caughtM = null;
try {
    mercuryCall('tok-1', 'POST', '/accounts/acct-1/transactions', ['amount' => '10.00']);
} catch (MercuryApiException $e) {
    $caughtM = $e;
}
check('mercuryCall throws MercuryApiException on 400',     $caughtM instanceof MercuryApiException);
check('exception ->httpStatus === 400',                    $caughtM && $caughtM->httpStatus === 400);
check('exception ->errorCode carries Mercury code',        $caughtM && $caughtM->errorCode === 'invalid_recipient');
check('exception ->raw carries the parsed body',
    $caughtM && is_array($caughtM->raw) && ($caughtM->raw['message'] ?? '') === 'Recipient does not exist');
unset($GLOBALS['__mercury_transport']);

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "error_surface smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
