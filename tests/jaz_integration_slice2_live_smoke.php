<?php
/**
 * jaz_integration_slice2_live_smoke.php
 *
 * Slice 2 — Phase 1 (reads) + Phase 3 (writes) live wiring.
 *
 * Approach: install a $GLOBALS['__jaz_transport'] stub so we can
 * exercise the real adapter code paths (URL construction, header
 * shape, body shape, response normalisation, error mapping) without
 * touching the network. The same test seam Mercury uses.
 *
 * Coverage:
 *   • jaz_http.php — base URL, auth header, error mapping
 *   • validateConnection — GET /organization, parses
 *     resourceId/name/baseCurrency from common response shapes,
 *     persists provider_org_id (smoke checks the SQL only)
 *   • all 7 reads — exact path, exact body shape, response → canonical
 *   • all 3 writes — exact path, payload merges saveAsDraft=true
 *   • postObject — POST /draft/convert-to-active with bulk shape
 *   • normalizeProviderError — full HTTP code matrix
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);
$ROOT = dirname(__DIR__);

echo "Jaz Integration Slice 2 — live wiring smoke\n";
echo "===========================================\n\n";

// ---- file shape -----------------------------------------------
echo "core/accounting/jaz_http.php\n";
$h = $read("{$ROOT}/core/accounting/jaz_http.php");
$a('declares strict_types',                       str_contains($h, 'declare(strict_types=1);'));
$a('JazApiException class',                       str_contains($h, 'class JazApiException extends \\RuntimeException'));
$a('  httpStatus property',                       str_contains($h, 'public int $httpStatus = 0;'));
$a('  raw property',                              str_contains($h, 'public array $raw = [];'));
$a('jazApiBase default getjaz.com',               str_contains($h, "return 'https://api.getjaz.com/api/v1';"));
$a('jazApiBase honors JAZ_API_BASE env',          str_contains($h, "getenv('JAZ_API_BASE')"));
$a('jazCall sets Authorization: Bearer header',   str_contains($h, "'Authorization: Bearer ' . \$apiKey,"));
$a('jazCall sets Accept + Content-Type JSON',     str_contains($h, "'Accept: application/json',")
                                               && str_contains($h, "'Content-Type: application/json',"));
$a('jazCall honors transport test seam',          str_contains($h, "isset(\$GLOBALS['__jaz_transport'])"));
$a('jazCall verifies SSL peer + host',            str_contains($h, 'CURLOPT_SSL_VERIFYPEER => true,')
                                               && str_contains($h, 'CURLOPT_SSL_VERIFYHOST => 2,'));
$a('jazCall throws on non-2xx',                   str_contains($h, '$status < 200 || $status >= 300'));
$a('jazCall throws on missing api key',           str_contains($h, "Jaz: api key required"));

echo "\ncore/accounting/jaz_adapter.php (live shape)\n";
$ja = $read("{$ROOT}/core/accounting/jaz_adapter.php");
$a('requires jaz_http.php',                       str_contains($ja, "require_once __DIR__ . '/jaz_http.php';"));
$a('validateConnection probes GET /organization', str_contains($ja, "jazCall(\$key, 'GET', 'organization')"));
$a('  401/403 mapped → status=failed',            str_contains($ja, "\$e->httpStatus === 401 || \$e->httpStatus === 403"));
$a('  persists provider_org_id via UPDATE',       str_contains($ja, 'UPDATE accounting_provider_connections')
                                               && str_contains($ja, 'provider_org_id = COALESCE'));
$a('getChartOfAccounts paginates page/pageSize',  str_contains($ja, "['page' => \$page, 'pageSize' => \$pageSize]"));
$a('  caps at maxPages=20',                       str_contains($ja, '$maxPages = 20'));
$a('getTrialBalance POSTs /reports/trial-balance',str_contains($ja, "jazCall(\$key, 'POST', 'reports/trial-balance', ['endDate' => \$asOf])"));
$a('getGeneralLedger filters out null body keys', str_contains($ja, "array_filter(\$body)"));
$a('  passes account filter as accountResourceId',str_contains($ja, "\$body['accountResourceId'] = \$filters['account'];"));
$a('getProfitAndLoss /reports/profit-and-loss',   str_contains($ja, "jazCall(\$key, 'POST', 'reports/profit-and-loss'"));
$a('getBalanceSheet /reports/balance-sheet',      str_contains($ja, "jazCall(\$key, 'POST', 'reports/balance-sheet'"));
$a('getArAging /reports/ar-report',               str_contains($ja, "jazCall(\$key, 'POST', 'reports/ar-report'"));
$a('getApAging /reports/ap-report',               str_contains($ja, "jazCall(\$key, 'POST', 'reports/ap-report'"));

$a('createDraftBill saveAsDraft=true',            str_contains($ja, "'saveAsDraft' => true,"));
$a('createDraftBill submitForApproval=false',     str_contains($ja, "'submitForApproval' => false,"));
$a('postObject sends resourceIds + BTtype',       str_contains($ja, "'resourceIds' => [\$providerObjectId],"));

$a('normalizeProviderError handles JazApiException',
    str_contains($ja, 'if ($e instanceof JazApiException)'));
$a('  401 → auth_invalid',                        str_contains($ja, "case 401: \$code = 'auth_invalid';"));
$a('  403 → auth_forbidden',                      str_contains($ja, "case 403: \$code = 'auth_forbidden';"));
$a('  422 → provider_validation',                 str_contains($ja, "case 422: \$code = 'provider_validation';"));
$a('  429 → rate_limited',                        str_contains($ja, "case 429: \$code = 'rate_limited';"));
$a('  5xx → provider_unavailable',                str_contains($ja, 'provider_unavailable'));

// ---- FUNCTIONAL transport-stubbed exercises -------------------
echo "\nFunctional adapter calls (stubbed transport)\n";
require_once "{$ROOT}/core/accounting/provider_adapter.php";
require_once "{$ROOT}/core/accounting/jaz_http.php";
require_once "{$ROOT}/core/accounting/jaz_adapter.php";

// Stub jazCall by hijacking the transport global.
$captured = [];
$nextResp = null;
$GLOBALS['__jaz_transport'] = function ($method, $url, $headers, $body) use (&$captured, &$nextResp) {
    $captured[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    return $nextResp ?? ['status' => 200, 'body' => json_encode([])];
};

// Bypass the DB credential resolver via a subclass override.
class _StubJazAdapter extends JazAccountingAdapter
{
    public string $stubKey = 'jaz_test_key_minimum_24_chars_xyz';
    protected function resolveCredential(int $t, int $st): ?string { return $this->stubKey; }
}
$ad = new _StubJazAdapter();

// validateConnection
$captured = []; $nextResp = ['status' => 200, 'body' => json_encode([
    'organization' => [
        'resourceId' => 'org_abc123',
        'name'       => 'Acme Inc.',
        'baseCurrency' => ['code' => 'USD'],
    ],
])];
$v = $ad->validateConnection(1, 1);
$a('validateConnection returns ok=true on 200',   ($v['ok'] ?? false) === true);
$a('  status=active',                              ($v['status'] ?? '') === 'active');
$a('  org.id from resourceId',                     ($v['org']['id']   ?? '') === 'org_abc123');
$a('  org.name from name',                         ($v['org']['name'] ?? '') === 'Acme Inc.');
$a('  org.base_currency from baseCurrency.code',   ($v['org']['base_currency'] ?? '') === 'USD');
$a('  captured method=GET, path=organization',
    $captured[0]['method'] === 'GET'
    && str_ends_with($captured[0]['url'], '/api/v1/organization'));
$a('  Authorization header present + bearer',
    in_array('Authorization: Bearer jaz_test_key_minimum_24_chars_xyz', $captured[0]['headers'], true));

// validateConnection — bad key (401)
$captured = []; $nextResp = ['status' => 401, 'body' => json_encode(['message' => 'invalid_token'])];
$v = $ad->validateConnection(1, 1);
$a('validateConnection ok=false on 401',          ($v['ok'] ?? true) === false);
$a('  status=failed on 401',                       ($v['status'] ?? '') === 'failed');

// Regression — Jaz sometimes returns `{message: [...]}` or `{error: {...}}`.
// Older code stringified the array to literal "Array", surfacing useless
// "Validation failed: Jaz GET organization: Array" toasts to admins. The
// jaz_http error extractor must json_encode nested structures so the real
// payload reaches the admin.
$nextResp = ['status' => 422, 'body' => json_encode(['message' => ['First reason', 'Second reason']])];
$v = $ad->validateConnection(1, 1);
$a('array message NOT stringified to "Array"',     !str_contains((string) ($v['error'] ?? ''), ': Array'));
$a('  array message JSON-encoded in error',        str_contains((string) ($v['error'] ?? ''), 'First reason'));

$nextResp = ['status' => 500, 'body' => json_encode(['error' => ['code' => 'srv', 'detail' => 'db down']])];
$v = $ad->validateConnection(1, 1);
$a('nested error object NOT stringified to "Array"', !str_contains((string) ($v['error'] ?? ''), ': Array'));
$a('  nested error JSON-encoded into message',     str_contains((string) ($v['error'] ?? ''), 'db down'));

// getChartOfAccounts — single page (no hasMore)
$captured = []; $nextResp = ['status' => 200, 'body' => json_encode([
    'data' => [
        ['resourceId' => 'acc_1', 'accountCode' => '1000', 'accountName' => 'Cash',  'accountType' => 'ASSET',    'currency' => ['code' => 'USD'], 'isActive' => true],
        ['resourceId' => 'acc_2', 'accountCode' => '4000', 'accountName' => 'Sales', 'accountType' => 'REVENUE',  'currency' => 'USD',             'isActive' => true],
    ],
    'hasMore' => false,
])];
$coa = $ad->getChartOfAccounts(1, 1);
$a('COA returns 2 accounts',                       count($coa['accounts']) === 2);
$a('  normalised code/name/type/currency',
    $coa['accounts'][0]['code'] === '1000'
    && $coa['accounts'][0]['name'] === 'Cash'
    && $coa['accounts'][0]['type'] === 'asset'
    && $coa['accounts'][0]['currency'] === 'USD');
$a('  currency falls back to string when not object',
    $coa['accounts'][1]['currency'] === 'USD');
$a('  active flag preserved',                      $coa['accounts'][0]['active'] === true);
$a('  jaz_resource_id captured',                   $coa['accounts'][0]['jaz_resource_id'] === 'acc_1');
$a('  query string carries page=1&pageSize=200',   str_contains($captured[0]['url'], 'page=1&pageSize=200'));

// getTrialBalance — amount→cents
$captured = []; $nextResp = ['status' => 200, 'body' => json_encode([
    'currency' => 'USD',
    'lines' => [
        ['accountCode' => '1000', 'accountName' => 'Cash',  'debit'  => 1234.56, 'credit' => 0],
        ['accountCode' => '4000', 'accountName' => 'Sales', 'debit'  => 0,       'credit' => 1234.56],
    ],
])];
$tb = $ad->getTrialBalance(1, 1, ['asOf' => '2026-01-31']);
$a('TB total_debit_cents = 123456',                $tb['total_debit_cents']  === 123456);
$a('TB total_credit_cents = 123456',               $tb['total_credit_cents'] === 123456);
$a('TB lines[0] debit_cents = 123456',             $tb['lines'][0]['debit_cents'] === 123456);
$a('TB body includes endDate=2026-01-31',          str_contains($captured[0]['body'], '"endDate":"2026-01-31"'));
$a('TB hits /reports/trial-balance',               str_ends_with($captured[0]['url'], '/reports/trial-balance'));

// getGeneralLedger — passes account filter, omits nulls
$captured = []; $nextResp = ['status' => 200, 'body' => json_encode(['lines' => []])];
$ad->getGeneralLedger(1, 1, ['from' => '2026-01-01', 'to' => '2026-01-31', 'account' => 'acc_xyz']);
$a('GL body has startDate + endDate + accountResourceId',
    str_contains($captured[0]['body'], '"startDate":"2026-01-01"')
    && str_contains($captured[0]['body'], '"endDate":"2026-01-31"')
    && str_contains($captured[0]['body'], '"accountResourceId":"acc_xyz"'));

// GL — null account is dropped from body
$captured = []; $nextResp = ['status' => 200, 'body' => json_encode(['lines' => []])];
$ad->getGeneralLedger(1, 1, ['from' => '2026-01-01', 'to' => '2026-01-31']);
$a('GL omits accountResourceId when filter absent',
    !str_contains($captured[0]['body'], 'accountResourceId'));

// createDraftBill — saveAsDraft injected, payload merged, captured response
$captured = []; $nextResp = ['status' => 201, 'body' => json_encode([
    'bill' => ['resourceId' => 'bill_999', 'status' => 'DRAFT'],
])];
$res = $ad->createDraftBill(1, 1, [
    'reference'         => 'PI-42',
    'contactResourceId' => 'cnt_abc',
    'lineItems'         => [['amount' => 100]],
], 'idem-bill-42');
$a('createDraftBill hits /bills',                  str_ends_with($captured[0]['url'], '/api/v1/bills'));
$a('  body has saveAsDraft=true',                  str_contains($captured[0]['body'], '"saveAsDraft":true'));
$a('  body has submitForApproval=false',           str_contains($captured[0]['body'], '"submitForApproval":false'));
$a('  body preserves caller-supplied reference',   str_contains($captured[0]['body'], '"reference":"PI-42"'));
$a('  result provider_object_type=bill',           $res['provider_object_type'] === 'bill');
$a('  result provider_object_id from resourceId',  $res['provider_object_id'] === 'bill_999');
$a('  result idempotency_key preserved',           $res['idempotency_key'] === 'idem-bill-42');
$a('  result status=draft',                        $res['status'] === 'draft');

// createDraftInvoice
$captured = []; $nextResp = ['status' => 201, 'body' => json_encode(['invoice' => ['resourceId' => 'inv_77']])];
$res = $ad->createDraftInvoice(1, 1, ['reference' => 'AR-1'], 'idem-inv-1');
$a('createDraftInvoice hits /invoices',            str_ends_with($captured[0]['url'], '/api/v1/invoices'));
$a('  result provider_object_id from invoice.resourceId', $res['provider_object_id'] === 'inv_77');

// createDraftJournal
$captured = []; $nextResp = ['status' => 201, 'body' => json_encode(['journal' => ['resourceId' => 'jrnl_5']])];
$res = $ad->createDraftJournal(1, 1, ['lineItems' => []], 'idem-jrnl-1');
$a('createDraftJournal hits /journals',            str_ends_with($captured[0]['url'], '/api/v1/journals'));
$a('  result provider_object_id from journal.resourceId', $res['provider_object_id'] === 'jrnl_5');

// postObject — bulk convert
$captured = []; $nextResp = ['status' => 200, 'body' => json_encode(['jobId' => 'job_42'])];
$res = $ad->postObject(1, 1, 'bill', 'bill_999');
$a('postObject hits /draft/convert-to-active',     str_ends_with($captured[0]['url'], '/draft/convert-to-active'));
$a('  body has resourceIds:[bill_999]',            str_contains($captured[0]['body'], '"resourceIds":["bill_999"]'));
$a('  body has businessTransactionType=BILL',      str_contains($captured[0]['body'], '"businessTransactionType":"BILL"'));
$a('  result status=posted',                        $res['status'] === 'posted');

// postObject empty id → AccountingAdapterValidationException
$threw = false;
try { $ad->postObject(1, 1, 'bill', ''); }
catch (AccountingAdapterValidationException $e) { $threw = true; }
$a('postObject rejects empty resource_id',         $threw);

// normalizeProviderError matrix
$mk = function (int $st) { $e = new JazApiException('m'); $e->httpStatus = $st; return $e; };
$a('normalize 401 → auth_invalid',                 $ad->normalizeProviderError($mk(401))['code'] === 'auth_invalid');
$a('normalize 403 → auth_forbidden',               $ad->normalizeProviderError($mk(403))['code'] === 'auth_forbidden');
$a('normalize 404 → not_found',                    $ad->normalizeProviderError($mk(404))['code'] === 'not_found');
$a('normalize 409 → conflict',                     $ad->normalizeProviderError($mk(409))['code'] === 'conflict');
$a('normalize 422 → provider_validation',          $ad->normalizeProviderError($mk(422))['code'] === 'provider_validation');
$a('normalize 429 → rate_limited',                 $ad->normalizeProviderError($mk(429))['code'] === 'rate_limited');
$a('normalize 500 → provider_unavailable',         $ad->normalizeProviderError($mk(500))['code'] === 'provider_unavailable');
$a('normalize 503 → provider_unavailable',         $ad->normalizeProviderError($mk(503))['code'] === 'provider_unavailable');
$a('normalize 999 → provider_error (fallthru)',    $ad->normalizeProviderError($mk(999))['code'] === 'provider_error');
$a('normalize Validation → provider_validation',
    $ad->normalizeProviderError(new AccountingAdapterValidationException('boom'))['code'] === 'provider_validation');

unset($GLOBALS['__jaz_transport']);

// ---- syntax ---------------------------------------------------
echo "\nPHP syntax\n";
foreach (['core/accounting/jaz_http.php', 'core/accounting/jaz_adapter.php'] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses", is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Jaz Slice 2 live wiring: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);
