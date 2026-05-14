<?php
/**
 * Billing Module — Phase A0 contract smoke tests.
 * Static + library + parse checks. No live DB.
 */
declare(strict_types=1);
require_once __DIR__ . '/../modules/billing/lib/billing.php';

$pass = 0; $fail = 0;
$assert = function ($n, $c) use (&$pass, &$fail) { if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; } };

echo "Migration SQL\n";
$sql = (string) file_get_contents(__DIR__ . '/../modules/billing/migrations/001_init.sql');
$assert('migration exists',                    strlen($sql) > 0);
$assert('utf8mb4_unicode_ci used',             strpos($sql, 'utf8mb4_unicode_ci') !== false);
$assert('NOT 0900_ai_ci',                      strpos($sql, 'utf8mb4_0900_ai_ci') === false);
foreach (['billing_invoices','billing_invoice_lines','billing_payments','billing_payment_allocations','billing_invoice_tokens'] as $t) {
    $assert("table {$t}",                       strpos($sql, "CREATE TABLE IF NOT EXISTS {$t}") !== false);
}
$assert('status enum has 6 values',            strpos($sql, "ENUM('draft','approved','sent','partially_paid','paid','void')") !== false);
$assert('aggregation enum',                    strpos($sql, "ENUM('per_placement','per_client')") !== false);
$assert('UNIQUE invoice number per tenant',    strpos($sql, 'UNIQUE KEY uq_bi_tenant_number') !== false);
$assert('lines FK to invoices',                strpos($sql, 'fk_bil_invoice') !== false);
$assert('payment allocations FK',              strpos($sql, 'fk_bpa_payment') !== false && strpos($sql, 'fk_bpa_invoice') !== false);
$assert('idempotent ALTER tenants',            substr_count($sql, 'information_schema.COLUMNS') >= 5);
foreach (['billing_tax_rate_pct','billing_invoice_prefix','billing_next_invoice_seq','billing_invoice_terms','billing_payment_instructions'] as $col) {
    $assert("ALTER adds {$col}",                strpos($sql, $col) !== false);
}

echo "\nLibrary contract\n";
foreach (['billingNextInvoiceNumber','billingBuildDraftFromBundle','billingComputeTax','billingTransitionAllowed','billingIssueViewToken','billingTokenFindByRaw','billingAllocatePayment','billingComputeAging','billingAudit'] as $f) {
    $assert("fn: {$f}",                         function_exists($f));
}

echo "\nbillingComputeTax math\n";
$lines = [
    ['quantity' => 10, 'unit_price' => 100],
    ['quantity' =>  5, 'unit_price' =>  20],
];
$res = billingComputeTax($lines, 7.5);
$assert('subtotal = 1100',                     abs($res['subtotal'] - 1100) < 0.01);
$assert('tax = 82.50',                         abs($res['tax_total'] - 82.50) < 0.01);
$assert('total = 1182.50',                     abs($res['total'] - 1182.50) < 0.01);
$assert('per-line subtotal computed',          abs($res['lines'][0]['subtotal'] - 1000) < 0.01);
$assert('per-line tax computed',               abs($res['lines'][0]['tax_amount'] - 75) < 0.01);
$assert('per-line total computed',             abs($res['lines'][0]['total'] - 1075) < 0.01);

$res0 = billingComputeTax([['quantity' => 1, 'unit_price' => 100]], 0);
$assert('zero tax rate works',                 $res0['tax_total'] === 0.0 && $res0['total'] === 100.0);

echo "\nbillingTransitionAllowed matrix\n";
$assert('draft → approved',                    billingTransitionAllowed('draft', 'approved'));
$assert('draft → void',                        billingTransitionAllowed('draft', 'void'));
$assert('draft → sent (NO)',                   !billingTransitionAllowed('draft', 'sent'));
$assert('approved → sent',                     billingTransitionAllowed('approved', 'sent'));
$assert('approved → draft (NO)',               !billingTransitionAllowed('approved', 'draft'));
$assert('sent → partially_paid',               billingTransitionAllowed('sent', 'partially_paid'));
$assert('sent → paid',                         billingTransitionAllowed('sent', 'paid'));
$assert('sent → approved (NO)',                !billingTransitionAllowed('sent', 'approved'));
$assert('partially_paid → paid',               billingTransitionAllowed('partially_paid', 'paid'));
$assert('paid → void',                         billingTransitionAllowed('paid', 'void'));
$assert('paid → sent (NO)',                    !billingTransitionAllowed('paid', 'sent'));
$assert('void terminal',                       !billingTransitionAllowed('void', 'draft') && !billingTransitionAllowed('void', 'approved'));

echo "\nAPI files parse\n";
foreach (['invoices.php','payments.php','aging.php'] as $f) {
    $p = __DIR__ . "/../modules/billing/api/{$f}";
    $assert("api/{$f} exists",                  is_file($p));
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    $assert("api/{$f} parses",                  $rc === 0);
}
$pub = __DIR__ . '/../billing/invoice.php';
$assert('public view page exists',             is_file($pub));
$o = []; $rc = 0; @exec('php -l ' . escapeshellarg($pub) . ' 2>&1', $o, $rc);
$assert('public view page parses',             $rc === 0);
$pubSrc = (string) file_get_contents($pub);
$assert('public page noindex',                 strpos($pubSrc, 'noindex') !== false);
$assert('public page validates token format',  strpos($pubSrc, '[a-f0-9]{64}') !== false || strpos($pubSrc, 'billingTokenFindByRaw') !== false);
$assert('public page bumps view counter',      strpos($pubSrc, 'view_count = view_count + 1') !== false);
$assert('public page has print button',        strpos($pubSrc, 'window.print()') !== false);

echo "\nAPI endpoint actions wired\n";
$inv = (string) file_get_contents(__DIR__ . '/../modules/billing/api/invoices.php');
foreach (['from-time-bundle','approve','send','void'] as $a) {
    $assert("invoices has action={$a}",         strpos($inv, "action === '{$a}'") !== false);
}
$assert('two-eye approve guard',               strpos($inv, 'cannot approve your own draft') !== false);
$assert('void releases bundles when no pmts',  strpos($inv, 'consumed_by_module = NULL') !== false);
$assert('approve checks transition allowed',   strpos($inv, "billingTransitionAllowed(\$row['status'], 'approved')") !== false);
$assert('send issues token + emails',          strpos($inv, 'billingIssueViewToken') !== false && strpos($inv, "cf_mail_bootstrap") !== false);
$assert('send uses tenant mail sender',        strpos($inv, "cf_tenant_mail_sender(\$tid, 'billing')") !== false);
$assert('from-time-bundle marks bundles consumed', strpos($inv, 'status = "consumed"') !== false);

$pay = (string) file_get_contents(__DIR__ . '/../modules/billing/api/payments.php');
$assert('payments has action=allocate',        strpos($pay, "action === 'allocate'") !== false);
$assert('payments supports auto-allocate',     strpos($pay, "auto_allocate") !== false);

$aging = (string) file_get_contents(__DIR__ . '/../modules/billing/api/aging.php');
$assert('aging validates as_of date format',   strpos($aging, '\d{4}-\d{2}-\d{2}') !== false);

echo "\nManifest\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/billing/manifest.php');
foreach (['billing.view','billing.invoice.draft','billing.invoice.approve','billing.invoice.send','billing.invoice.void','billing.payments.record'] as $p) {
    $assert("perm {$p}",                        strpos($man, "'{$p}'") !== false);
}
$assert('depends_on placements + time',        strpos($man, "['placements', 'time']") !== false);
$assert('does NOT depend on accounting yet',   strpos($man, "'accounting'") === false);

echo "\nReact UI wiring\n";
$app = (string) file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');
$assert('App.jsx imports BillingModule',       strpos($app, "import BillingModule from '../../modules/billing/ui/BillingModule'") !== false);
$assert('App.jsx routes /modules/billing/*',   strpos($app, '/modules/billing/*') !== false);

foreach (['BillingModule.jsx','InvoicesList.jsx','InvoiceDetail.jsx','InvoiceFromTimeBundleModal.jsx','PaymentsList.jsx','AgingTable.jsx'] as $c) {
    $assert("ui/{$c} exists",                   is_file(__DIR__ . "/../modules/billing/ui/{$c}"));
}
$bm = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/BillingModule.jsx');
$assert('BillingModule renders InvoicesList',  strpos($bm, '<InvoicesList') !== false);
$assert('BillingModule renders PaymentsList',  strpos($bm, '<PaymentsList') !== false);
$assert('BillingModule renders AgingTable',    strpos($bm, '<AgingTable') !== false);
$il = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/InvoicesList.jsx');
$assert('list has new-from-time-bundle btn',   strpos($il, 'billing-new-from-time-bundle') !== false);
$id = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/InvoiceDetail.jsx');
$assert('detail has approve button testid',    strpos($id, 'billing-invoice-approve') !== false);
$assert('detail has send button testid',       strpos($id, 'billing-invoice-send-open') !== false);
$assert('detail has void button testid',       strpos($id, 'billing-invoice-void') !== false);
$ifm = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/InvoiceFromTimeBundleModal.jsx');
$assert('modal hits feed.php for ar bundles',  strpos($ifm, 'bundle_type=ar') !== false);
$assert('modal posts to from-time-bundle',     strpos($ifm, 'action=from-time-bundle') !== false);
$assert('modal supports per_client agg',       strpos($ifm, "billing-from-time-agg-client") !== false);
$pl = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/PaymentsList.jsx');
$assert('payments list has record-payment',    strpos($pl, 'billing-record-payment') !== false);
$assert('payments list has allocate modal',    strpos($pl, 'billing-allocate-modal') !== false);
$assert('payments list supports auto-FIFO',    strpos($pl, 'billing-allocate-fifo') !== false);

echo "\nAging SQL math sanity (computed via SQL — schema-only test here)\n";
$libSrc = (string) file_get_contents(__DIR__ . '/../modules/billing/lib/billing.php');
$assert('aging includes 5 buckets',            strpos($libSrc, 'bucket_current') !== false && strpos($libSrc, 'bucket_91_plus') !== false);
$assert('aging filters unpaid statuses',       strpos($libSrc, "status IN (\"sent\",\"partially_paid\",\"approved\",\"overdue\")") !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
