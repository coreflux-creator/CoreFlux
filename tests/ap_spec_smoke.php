<?php
/**
 * AP Module — Phase A0 contract smoke tests.
 * Static + library + parse checks. No live DB.
 */
declare(strict_types=1);
require_once __DIR__ . '/../modules/ap/lib/ap.php';

$pass = 0; $fail = 0;
$assert = function ($n, $c) use (&$pass, &$fail) { if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; } };

echo "Migration SQL\n";
$sql = (string) file_get_contents(__DIR__ . '/../modules/ap/migrations/001_init.sql');
$assert('migration exists',                    strlen($sql) > 0);
$assert('utf8mb4_unicode_ci used',             strpos($sql, 'utf8mb4_unicode_ci') !== false);
$assert('NOT 0900_ai_ci',                      strpos($sql, 'utf8mb4_0900_ai_ci') === false);
foreach ([
    'ap_vendors_index','ap_bills','ap_bill_lines','ap_payments','ap_payment_allocations',
    'ap_expense_reports','ap_expense_report_lines','ap_1099_ledger'
] as $t) {
    $assert("table {$t}",                       strpos($sql, "CREATE TABLE IF NOT EXISTS {$t}") !== false);
}
$assert('bill status has 8 states',            strpos($sql, "ENUM('inbox','pending_review','pending_approval','approved','partially_paid','paid','void','disputed')") !== false);
$assert('bill source enum full',               strpos($sql, "ENUM('mail_inbox','manual','time_bundle','recurring','expense_report','referral')") !== false);
$assert('vendor type enum',                    strpos($sql, "ENUM('1099_individual','c2c_corp','w9_business','utility','other')") !== false);
$assert('payment method includes plaid',       strpos($sql, "'plaid'") !== false);
$assert('payment status 6 states',             strpos($sql, "ENUM('draft','queued','sent','cleared','failed','void')") !== false);
$assert('expense status 5 states',             strpos($sql, "ENUM('draft','submitted','approved','rejected','paid')") !== false);
$assert('UNIQUE bill internal_ref per tenant', strpos($sql, 'uq_apb_tenant_internal') !== false);
$assert('UNIQUE vendor per tenant',            strpos($sql, 'uq_apv_tenant_name') !== false);
$assert('UNIQUE 1099 per vendor+year',         strpos($sql, 'uq_ap1099_tenant_year_vendor') !== false);
$assert('bill_lines FK to bills',              strpos($sql, 'fk_apbl_bill') !== false);
$assert('payment allocations FK',              strpos($sql, 'fk_appa_payment') !== false && strpos($sql, 'fk_appa_bill') !== false);
$assert('expense lines FK',                    strpos($sql, 'fk_aperl_report') !== false);
$assert('tax_id_full_ct VARBINARY 512',        strpos($sql, 'tax_id_full_ct VARBINARY(512)') !== false);
$assert('idempotent ALTER tenants',            substr_count($sql, 'information_schema.COLUMNS') >= 4);
foreach (['ap_bill_prefix','ap_next_bill_seq','ap_default_terms','ap_1099_threshold'] as $col) {
    $assert("ALTER adds {$col}",                strpos($sql, $col) !== false);
}

echo "\nLibrary contract\n";
foreach (['apNextInternalRef','apBuildDraftFromBundle','apComputeTotals','apBillTransitionAllowed','apPaymentTransitionAllowed','apAllocatePayment','apComputeAging','apBuild1099Ledger','apPlaidConfigured','apAudit'] as $f) {
    $assert("fn: {$f}",                         function_exists($f));
}

echo "\napComputeTotals math\n";
$lines = [
    ['quantity' => 10, 'unit_price' => 100],
    ['quantity' =>  5, 'unit_price' =>  20],
];
$res = apComputeTotals($lines, 0);
$assert('subtotal = 1100',                     abs($res['subtotal'] - 1100) < 0.01);
$assert('tax = 0 (AP typically no tax)',       $res['tax_total'] === 0.0);
$assert('total = 1100',                        abs($res['total'] - 1100) < 0.01);
$resT = apComputeTotals($lines, 7.5);
$assert('with tax: subtotal = 1100',           abs($resT['subtotal'] - 1100) < 0.01);
$assert('with tax: tax = 82.50',               abs($resT['tax_total'] - 82.50) < 0.01);
$assert('with tax: total = 1182.50',           abs($resT['total'] - 1182.50) < 0.01);
$assert('per-line subtotal computed',          abs($resT['lines'][0]['subtotal'] - 1000) < 0.01);
$assert('per-line tax computed',               abs($resT['lines'][0]['tax_amount'] - 75) < 0.01);
$assert('per-line total computed',             abs($resT['lines'][0]['total'] - 1075) < 0.01);

echo "\napBillTransitionAllowed matrix\n";
$assert('inbox → pending_review',              apBillTransitionAllowed('inbox', 'pending_review'));
$assert('inbox → void',                        apBillTransitionAllowed('inbox', 'void'));
$assert('inbox → approved (NO)',               !apBillTransitionAllowed('inbox', 'approved'));
$assert('pending_review → pending_approval',   apBillTransitionAllowed('pending_review', 'pending_approval'));
$assert('pending_review → disputed',           apBillTransitionAllowed('pending_review', 'disputed'));
$assert('pending_approval → approved',         apBillTransitionAllowed('pending_approval', 'approved'));
$assert('pending_approval → void',             apBillTransitionAllowed('pending_approval', 'void'));
$assert('pending_approval → paid (NO)',        !apBillTransitionAllowed('pending_approval', 'paid'));
$assert('approved → partially_paid',           apBillTransitionAllowed('approved', 'partially_paid'));
$assert('approved → paid',                     apBillTransitionAllowed('approved', 'paid'));
$assert('approved → disputed',                 apBillTransitionAllowed('approved', 'disputed'));
$assert('approved → pending_review (NO)',      !apBillTransitionAllowed('approved', 'pending_review'));
$assert('partially_paid → paid',               apBillTransitionAllowed('partially_paid', 'paid'));
$assert('paid → void',                         apBillTransitionAllowed('paid', 'void'));
$assert('paid → approved (NO)',                !apBillTransitionAllowed('paid', 'approved'));
$assert('disputed → pending_approval',         apBillTransitionAllowed('disputed', 'pending_approval'));
$assert('void terminal',                       !apBillTransitionAllowed('void', 'approved') && !apBillTransitionAllowed('void', 'pending_review'));

echo "\napPaymentTransitionAllowed matrix\n";
$assert('draft → queued',                      apPaymentTransitionAllowed('draft', 'queued'));
$assert('draft → void',                        apPaymentTransitionAllowed('draft', 'void'));
$assert('queued → sent',                       apPaymentTransitionAllowed('queued', 'sent'));
$assert('sent → cleared',                      apPaymentTransitionAllowed('sent', 'cleared'));
$assert('sent → failed',                       apPaymentTransitionAllowed('sent', 'failed'));
$assert('failed → queued (retry)',             apPaymentTransitionAllowed('failed', 'queued'));
$assert('cleared → void (reversal)',           apPaymentTransitionAllowed('cleared', 'void'));
$assert('sent → draft (NO)',                   !apPaymentTransitionAllowed('sent', 'draft'));
$assert('void terminal',                       !apPaymentTransitionAllowed('void', 'sent'));

echo "\napPlaidConfigured env probe\n";
$origId  = getenv('PLAID_CLIENT_ID');
$origSec = getenv('PLAID_SECRET_SANDBOX');
putenv('PLAID_CLIENT_ID');
putenv('PLAID_SECRET_SANDBOX');
putenv('PLAID_SECRET');
$assert('not configured when env empty',       apPlaidConfigured() === false);
putenv('PLAID_CLIENT_ID=test_id');
putenv('PLAID_SECRET_SANDBOX=test_secret');
$assert('configured when both env set',        apPlaidConfigured() === true);
// restore
if ($origId  !== false) putenv('PLAID_CLIENT_ID=' . $origId);  else putenv('PLAID_CLIENT_ID');
if ($origSec !== false) putenv('PLAID_SECRET_SANDBOX=' . $origSec); else putenv('PLAID_SECRET_SANDBOX');

echo "\nAPI files parse\n";
foreach (['bills.php','payments.php','vendors.php','expenses.php','aging.php','1099.php'] as $f) {
    $p = __DIR__ . "/../modules/ap/api/{$f}";
    $assert("api/{$f} exists",                  is_file($p));
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    $assert("api/{$f} parses",                  $rc === 0);
}

echo "\nAPI endpoint actions wired\n";
$bills = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bills.php');
foreach (['from-time-bundle','approve','void','dispute','post'] as $a) {
    $assert("bills has action={$a}",            strpos($bills, "action === '{$a}'") !== false);
}
$assert('two-eye approve guard',               strpos($bills, 'cannot approve your own bill') !== false);
$assert('approve refuses zero-total lines',    strpos($bills, 'All bill lines must have total > 0') !== false);
$assert('void releases bundles when no pmts',  strpos($bills, 'consumed_by_module = NULL') !== false);
$assert('approve checks transition allowed',   strpos($bills, "apBillTransitionAllowed(\$row['status'], 'approved')") !== false);
$assert('from-time-bundle marks bundles consumed', strpos($bills, 'status = "consumed"') !== false);
$assert('from-time-bundle upserts vendors_index', strpos($bills, 'INSERT INTO ap_vendors_index') !== false);
$assert('post integrates with Accounting GL',  strpos($bills, "require_once __DIR__ . '/../../accounting/lib/accounting.php'") !== false
                                                && strpos($bills, 'accountingPostJe') !== false);

$pay = (string) file_get_contents(__DIR__ . '/../modules/ap/api/payments.php');
$assert('payments has action=allocate',        strpos($pay, "action === 'allocate'") !== false);
$assert('payments has action=send',            strpos($pay, "action === 'send'") !== false);
$assert('payments has action=clear',           strpos($pay, "action === 'clear'") !== false);
$assert('payments has action=void',            strpos($pay, "action === 'void'") !== false);
$assert('payments auto-allocate',              strpos($pay, 'auto_allocate') !== false);
$assert('send requires ap.payment.send',       strpos($pay, "requirePermission(\$user, 'ap.payment.send')") !== false);
$assert('send SoD guard',                      strpos($pay, 'cannot release your own payment') !== false);
$assert('send refuses disputed bills',         strpos($pay, 'disputed","void"') !== false || strpos($pay, "disputed\",\"void") !== false);
$assert('void reverses allocations',           strpos($pay, 'ap_bills b') !== false && strpos($pay, 'amount_paid = COALESCE') !== false);

$vend = (string) file_get_contents(__DIR__ . '/../modules/ap/api/vendors.php');
$assert('vendors typeahead (q filter)',        strpos($vend, "!empty(\$_GET['q'])") !== false);
$assert('vendors encrypts tax_id',             strpos($vend, 'encryptField') !== false);
$assert('vendors reveals with ap.vendor.view_pii', strpos($vend, "'ap.vendor.view_pii'") !== false);
$assert('vendors audits tax_id view',          strpos($vend, 'ap.vendor.tax_id_viewed') !== false);

$exp = (string) file_get_contents(__DIR__ . '/../modules/ap/api/expenses.php');
foreach (['submit','approve','reject'] as $a) {
    $assert("expenses has action={$a}",         strpos($exp, "action === '{$a}'") !== false);
}
$assert('expense approve converts to bill',    strpos($exp, "INSERT INTO ap_bills") === false); // uses scopedInsert
$assert('expense approve creates bill',        strpos($exp, "scopedInsert('ap_bills'") !== false);
$assert('expense approve two-eye',             strpos($exp, 'Two-eye') !== false);

$aging = (string) file_get_contents(__DIR__ . '/../modules/ap/api/aging.php');
$assert('aging validates as_of date format',   strpos($aging, '\d{4}-\d{2}-\d{2}') !== false);
$assert('aging requires ap.reports.view',      strpos($aging, "'ap.reports.view'") !== false);

$l1099 = (string) file_get_contents(__DIR__ . '/../modules/ap/api/1099.php');
$assert('1099 has rebuild action',             strpos($l1099, "action === 'rebuild'") !== false);
$assert('1099 validates tax_year',             strpos($l1099, 'invalid tax_year') !== false);

echo "\nManifest\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/ap/manifest.php');
foreach (['ap.view','ap.bill.create','ap.bill.approve','ap.bill.void','ap.bill.post','ap.payment.create','ap.payment.send','ap.payment.allocate','ap.expense.submit','ap.expense.approve','ap.vendor.view_pii','ap.1099.view','ap.1099.generate','ap.reports.view'] as $p) {
    $assert("perm {$p}",                        strpos($man, "'{$p}'") !== false);
}
$assert('depends_on placements + time',        strpos($man, "['placements', 'time']") !== false);
$assert('does NOT depend on accounting yet',   strpos($man, "'accounting'") === false);
$assert('audit events declared',               strpos($man, 'ap.bill.approved') !== false && strpos($man, 'ap.1099.ledger_built') !== false);

echo "\nLibrary source sanity\n";
$libSrc = (string) file_get_contents(__DIR__ . '/../modules/ap/lib/ap.php');
$assert('aging includes 5 buckets',            strpos($libSrc, 'bucket_current') !== false && strpos($libSrc, 'bucket_91_plus') !== false);
$assert('aging filters unpaid statuses',       strpos($libSrc, "status IN (\"approved\",\"partially_paid\",\"pending_approval\")") !== false);
$assert('1099 joins payments + allocations',   strpos($libSrc, 'FROM ap_payments p') !== false && strpos($libSrc, 'ap_payment_allocations a') !== false);
$assert('1099 only counts cleared',            strpos($libSrc, 'p.status = "cleared"') !== false);
$assert('1099 threshold is 600 default',       strpos($libSrc, '600.0') !== false);
$assert('build-from-bundle refuses non-ap',    strpos($libSrc, 'bundle_type = "ap"') !== false);
$assert('build-from-bundle requires ready',    strpos($libSrc, "!== 'ready'") !== false);
$assert('per_vendor aggregation default',      strpos($libSrc, "'per_vendor'") !== false);
$assert('per_placement aggregation allowed',   strpos($libSrc, "'per_placement'") !== false);
$assert('1099 individuals marked eligible',    strpos($libSrc, "'1099_individual'") !== false);
$assert('c2c corp detection via engagement',   strpos($libSrc, "engagement_type") !== false);

echo "\nReact UI wiring\n";
$app = (string) file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');
$assert('App.jsx imports APModule',            strpos($app, "import APModule from '../../modules/ap/ui/APModule'") !== false);
$assert('App.jsx routes /modules/ap/*',        strpos($app, '/modules/ap/*') !== false);
$assert('App.jsx maps APModule to route',      strpos($app, 'element={<APModule') !== false);

foreach ([
    'APModule.jsx','BillsList.jsx','BillDetail.jsx','BillFromTimeBundleModal.jsx',
    'PaymentsList.jsx','VendorsList.jsx','ExpensesList.jsx','ExpenseCreate.jsx',
    'AgingTable.jsx','Ledger1099.jsx'
] as $c) {
    $assert("ui/{$c} exists",                   is_file(__DIR__ . "/../modules/ap/ui/{$c}"));
}
$am = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/APModule.jsx');
foreach (['BillsList','PaymentsList','VendorsList','ExpensesList','AgingTable','Ledger1099'] as $child) {
    $assert("APModule renders {$child}",        strpos($am, "<{$child}") !== false);
}
$bl = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillsList.jsx');
$assert('bills list new-from-time-bundle btn', strpos($bl, 'ap-new-from-time-bundle') !== false);
$bd = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillDetail.jsx');
$assert('detail has approve testid',           strpos($bd, 'ap-bill-approve') !== false);
$assert('detail has void testid',              strpos($bd, 'ap-bill-void') !== false);
$assert('detail has dispute testid',           strpos($bd, 'ap-bill-dispute') !== false);
$assert('detail has post-to-GL testid',        strpos($bd, 'ap-bill-post') !== false);
$bfm = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillFromTimeBundleModal.jsx');
$assert('modal hits feed.php for ap bundles', strpos($bfm, 'bundle_type=ap') !== false);
$assert('modal posts to from-time-bundle',    strpos($bfm, 'action=from-time-bundle') !== false);
$assert('modal supports per_vendor agg',      strpos($bfm, 'ap-from-time-agg-vendor') !== false);
$assert('modal supports per_placement agg',   strpos($bfm, 'ap-from-time-agg-placement') !== false);
$pl = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/PaymentsList.jsx');
$assert('payments list record-payment btn',    strpos($pl, 'ap-record-payment') !== false);
$assert('payments list allocate modal',        strpos($pl, 'ap-allocate-modal') !== false);
$assert('payments list supports auto-FIFO',    strpos($pl, 'ap-allocate-fifo') !== false);
$assert('payments list shows plaid gating',    strpos($pl, 'plaid_enabled') !== false);
$vl = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/VendorsList.jsx');
$assert('vendors list search',                 strpos($vl, 'ap-vendors-search') !== false);
$assert('vendors list create new',             strpos($vl, 'ap-vendor-new') !== false);
$el = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/ExpensesList.jsx');
$assert('expenses list new btn',               strpos($el, 'ap-expense-new') !== false);
$assert('expenses list submit action',         strpos($el, 'ap-expense-submit-') !== false);
$assert('expenses list approve action',        strpos($el, 'ap-expense-approve-') !== false);
$ec = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/ExpenseCreate.jsx');
$assert('expense create has lines',            strpos($ec, 'ap-expense-add-line') !== false);
$at = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/AgingTable.jsx');
$assert('aging table as-of picker',            strpos($at, 'ap-aging-asof') !== false);
$l1 = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/Ledger1099.jsx');
$assert('1099 ledger rebuild btn',             strpos($l1, 'ap-1099-rebuild') !== false);
$assert('1099 ledger year picker',             strpos($l1, 'ap-1099-year') !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
