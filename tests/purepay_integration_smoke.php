<?php
/** Source-level end-to-end Pure//Pay rail wiring smoke. */
declare(strict_types=1);
$root=dirname(__DIR__);$pass=0;$fail=[];
$check=function(string $l,bool $ok)use(&$pass,&$fail){echo($ok?'  ✓ ':'  ✗ ').$l.PHP_EOL;if($ok)$pass++;else$fail[]=$l;};
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$migration=$read('core/migrations/146_purepay_rail.sql');
foreach(['purepay_connections','purepay_vendor_mappings','purepay_payment_links','purepay_webhook_events'] as $table)$check("migration creates {$table}",str_contains($migration,"CREATE TABLE IF NOT EXISTS {$table}"));
$check('API key ciphertext sized to 1024',str_contains($migration,'api_key_ct            VARBINARY(1024)'));
$check('durable source ref is tenant-unique',str_contains($migration,'uq_pppl_source (tenant_id, source_ref)'));
$driver=$read('core/payment_rails/purepay_driver.php');
$check('durable claim distinguishes new operation',str_contains($driver,'$row[\'__fresh\'] = $fresh'));
$check('retry fence reconciles remote payments before POST pay',str_contains($driver,'$paymentsBefore = purepayCollection'));
$check('bill create uses stable provider idempotency key',str_contains($driver,"purepayIdempotencyKey(\$tenantId, 'bill', \$sourceRef)"));
$check('wallet payment uses stable provider idempotency key',str_contains($driver,"purepayIdempotencyKey(\$tenantId, 'pay', \$sourceRef)"));
$check('driver rejects non-USD payouts',str_contains($driver,'only USD payouts are supported'));
$check('driver verifies vendor identity by detail before paying',str_contains($driver,'purepayGetVendor($apiKey, $vendorId)')&&str_contains($driver,'assertVendorIdentity'));

$registry=$read('core/payment_rails.php');
$check('registry resolves purepay driver',str_contains($registry,"case 'purepay'"));
$check('registry lists Pure//Pay',str_contains($registry,"'id'          => 'purepay'"));
$check('Pure//Pay limited to AP',str_contains($driver,"'supported_modules' => ['ap']"));

$api=$read('api/purepay_connection.php');
$check('connection endpoint RBAC gated',str_contains($api,'rbac_legacy_require($user, \'accounting.bank.manage\')'));
$check('connection endpoint supports probe',str_contains($api,"action === 'probe'"));
$check('connection endpoint masks key',str_contains($api,"'api_key_last4'"));
$webhook=$read('api/webhooks/purepay.php');
$check('webhook reads all published headers',str_contains($webhook,'x-webhook-id')&&str_contains($webhook,'x-webhook-timestamp')&&str_contains($webhook,'x-webhook-signature'));
$check('webhook rejects invalid signatures before persistence',str_contains($webhook,"if (!\$check['ok']) api_error('Invalid webhook signature', 401)"));

$payments=$read('modules/ap/api/payments.php');
$check('AP allows Pure//Pay override',str_contains($payments,"'nacha', 'plaid_transfer', 'purepay'"));
$check('AP skips bank decrypt for Pure//Pay',substr_count($payments,'if ($targetRail !== \'purepay\')')>=2);
$check('AP passes vendor identity/email',str_contains($payments,"'recipient_ref'")&&str_contains($payments,"'recipient_email'"));
$check('AP guards both single and batch Pure//Pay currency',str_contains($payments,'Pure//Pay wallet payouts require USD')&&str_contains($payments,'Pure//Pay wallet payouts require a USD payment'));
$check('batch totals include only accepted payouts',str_contains($payments,"'amount_total' => \$originatedAmount")&&str_contains($payments,"'failed_items' => \$failedItems"));
$apLib=$read('modules/ap/lib/ap.php');
$check('suggested runs reject mixed currencies and non-USD Pure//Pay bills',str_contains($apLib,'This vendor has bills in multiple currencies')&&str_contains($apLib,'Pure//Pay wallet payouts require USD bills'));
$check('created payment runs reject mixed currencies',str_contains($apLib,'create separate payments'));
$ui=$read('modules/treasury/ui/PurePaySettings.jsx');
$check('settings UI exists with connect control',str_contains($ui,'data-testid="purepay-connect"'));
$check('settings UI surfaces key scopes',str_contains($ui,'Read-only: connection and status')&&str_contains($ui,'Read-write: approved AP payouts'));
$hub=$read('dashboard/src/pages/IntegrationsHub.jsx');
$check('integration hub card wired',str_contains($hub,'integration-card-purepay'));
$routes=$read('dashboard/src/pages/AdminModule.jsx');
$check('admin route wired',str_contains($routes,'/integrations/purepay'));

$health=$read('api/admin/integrations_health.php');
$check('charter health provider wired',str_contains($health,"'id'        => 'purepay'"));
$triage=$read('api/admin/integration_triage.php');
$check('triage includes Pure//Pay failures',str_contains($triage,"'purepay-failed'"));
$check('liveness cron exists',is_file($root.'/cron/purepay_health_probe.php'));
$check('payment polling cron exists',is_file($root.'/cron/purepay_payment_sync.php'));
$webhookProjection=$read('core/purepay_webhooks.php');
$poller=$read('cron/purepay_payment_sync.php');
$check('settlement does not clear AP without a GL posting',!str_contains($webhookProjection,'cleared_at=')&&!str_contains($poller,'cleared_at='));
$check('provider failure reopens AP bills through canonical recalculation',str_contains($webhookProjection,'apRefreshReleasedPaymentBillsForPayment')&&str_contains($poller,'purepayApplyPaymentStatus'));
$check('post-settlement failure requires manual review',str_contains($webhookProjection,'settled_failed_review')&&str_contains($triage,'settled_failed_review'));
echo "Pure//Pay integration: {$pass} passed, ".count($fail)." failed\n";exit($fail?1:0);
