<?php
/** Pure//Pay adapter, payload, verification, and error-surface contract. */

declare(strict_types=1);

$root = dirname(__DIR__);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once $root . '/core/purepay_adapter.php';
require_once $root . '/core/purepay_webhooks.php';

$pass=0; $fail=[];
$check = function(string $label, bool $ok) use (&$pass,&$fail): void {
    echo ($ok?'  ✓ ':'  ✗ ') . $label . PHP_EOL;
    if ($ok) $pass++; else $fail[]=$label;
};

echo "Pure//Pay payload contract\n=========================\n";
$spec = json_decode((string) file_get_contents($root . '/spec/purepay_schema.json'), true);
$check('schema parses', is_array($spec));
foreach (['VendorCreate','BillCreate','BillPay','Webhook'] as $definition) {
    $check("{$definition} definition exists", isset($spec['definitions'][$definition]));
}

$requests=[];
$GLOBALS['__purepay_transport'] = function(string $method,string $url,array $headers,?string $body) use (&$requests): array {
    $requests[] = compact('method','url','headers','body');
    if ($method === 'POST' && str_ends_with($url, '/vendors')) return ['status'=>201,'body'=>'{"vendor":{"id":"ven_1"}}'];
    if ($method === 'POST' && str_ends_with($url, '/bills')) return ['status'=>201,'body'=>'{"bill":{"id":"bill_1"}}'];
    if ($method === 'POST' && str_ends_with($url, '/bills/bill_1/pay')) return ['status'=>200,'body'=>'{"payment":{"id":"pmt_1","bill_id":"bill_1","status":"processing"}}'];
    if ($method === 'GET' && str_ends_with($url, '/vendors')) return ['status'=>200,'body'=>'{"vendors":[{"id":"ven_1"}]}'];
    if ($method === 'GET' && str_ends_with($url, '/bills')) return ['status'=>200,'body'=>'{"bills":[{"id":"bill_1"}]}'];
    if ($method === 'GET' && str_ends_with($url, '/payments')) return ['status'=>200,'body'=>'{"payments":[{"id":"pmt_1","bill_id":"bill_1"}]}'];
    if ($method === 'GET' && str_ends_with($url, '/wallet')) return ['status'=>200,'body'=>'{"balance_cents":5000}'];
    return ['status'=>404,'body'=>'{"detail":"not found"}'];
};

$vendorPayload=['legal_name'=>'Acme','email'=>'pay@acme.test','relationship'=>'vendor'];
$billPayload=['vendor_id'=>'ven_1','amount'=>12.50,'bill_date'=>'2026-08-23','due_date'=>'2026-08-23','invoice_number'=>'INV-1'];
$payPayload=['pay_via'=>'wallet'];
$check('vendor create id extracted', purepayResourceId(purepayCreateVendor('pk_test', $vendorPayload), ['vendor']) === 'ven_1');
$check('bill create id extracted', purepayResourceId(purepayCreateBill('pk_test', $billPayload), ['bill']) === 'bill_1');
$check('pay create id extracted', purepayResourceId(purepayPayBill('pk_test','bill_1',$payPayload), ['payment']) === 'pmt_1');
$check('vendor list normalizes', count(purepayCollection(purepayListVendors('pk_test'), ['vendors'])) === 1);
$check('bill list normalizes', count(purepayCollection(purepayListBills('pk_test'), ['bills'])) === 1);
$check('payment list normalizes', count(purepayCollection(purepayListPayments('pk_test'), ['payments'])) === 1);
$check('wallet endpoint callable', purepayGetWallet('pk_test')['balance_cents'] === 5000);

foreach ([['VendorCreate',$vendorPayload],['BillCreate',$billPayload],['BillPay',$payPayload]] as [$name,$payload]) {
    $allowed=$spec['definitions'][$name]['writableProperties'];
    $check("{$name} emits only documented fields", array_diff(array_keys($payload),$allowed) === []);
    $required=$spec['definitions'][$name]['required'];
    $check("{$name} includes required fields", array_diff($required,array_keys($payload)) === []);
}
$check('every request uses Bearer auth', !array_filter($requests, static fn(array $r): bool => !in_array('Authorization: Bearer pk_test',$r['headers'],true)));
$check('adapter base is the published v1 URL', purepayApiBase() === 'https://purepay.online/api/v1');

$driver=(string) file_get_contents($root . '/core/payment_rails/purepay_driver.php');
$check('driver re-GETs bills after create', str_contains($driver,'purepayListBills($apiKey)'));
$check('driver re-GETs payments after pay', str_contains($driver,'purepayListPayments($apiKey)'));
$check('driver uses wallet pay_via', str_contains($driver,"['pay_via' => 'wallet']"));
$check('driver never transmits bank fields', !str_contains($driver,"'account_routing'") && !str_contains($driver,"'account_number'"));
$check('driver has durable request fingerprint', str_contains($driver,'request_fingerprint'));
$check('driver fingerprints full vendor identity', str_contains($driver,'$recipientName') && str_contains($driver,'$recipientEmail'));
$check('driver distinguishes a fresh durable claim', str_contains($driver,'$row[\'__fresh\'] = $fresh'));
$check('driver checks existing remote payments before release', str_contains($driver,'$paymentsBefore = purepayCollection'));

$GLOBALS['__purepay_transport'] = static fn(): array => ['status'=>422,'body'=>'{"code":"vendor_not_ready","detail":"bank setup required"}'];
try {
    purepayCreateBill('pk_test',$billPayload);
    $check('typed error thrown',false);
} catch (PurePayApiException $e) {
    $check('typed error thrown',true);
    $check('typed error keeps HTTP status',$e->httpStatus===422);
    $check('typed error keeps vendor code',$e->errorCode==='vendor_not_ready');
    $check('typed error keeps raw body',($e->raw['detail']??'')==='bank setup required');
}
$GLOBALS['__purepay_transport'] = static fn(): array => ['status'=>503,'body'=>'{"detail":"temporarily unavailable"}'];
try {
    purepayPayBill('pk_test','bill_1',$payPayload);
    $check('non-GET 5xx is treated as outcome-uncertain',false);
} catch (PurePayApiException $e) {
    $check('non-GET 5xx is treated as outcome-uncertain',$e->outcomeUncertain === true);
}
unset($GLOBALS['__purepay_transport']);
putenv('COREFLUX_DISABLE_DATABASE');

$secret='whsec_test'; $ts=(string)time(); $raw='{"id":"evt_1","type":"payment.settled"}';
$sig='sha256='.hash_hmac('sha256',$ts.'.'.$raw,$secret);
$check('published webhook HMAC verifies', purepayWebhookVerify($secret,$ts,$raw,$sig)['ok'] === true);
$check('tampered webhook is rejected', purepayWebhookVerify($secret,$ts,$raw.'x',$sig)['ok'] === false);
$check('stale webhook is rejected', purepayWebhookVerify($secret,(string)(time()-1000),$raw,$sig)['error'] === 'timestamp_stale');

echo "Pure//Pay payload contract: {$pass} passed, " . count($fail) . " failed\n";
exit($fail ? 1 : 0);
