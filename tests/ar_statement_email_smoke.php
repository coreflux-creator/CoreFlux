<?php
/**
 * Smoke: Billing — AR statement (Email statement from Aging table).
 *
 * Reuses billing_client_contacts (no new migration). Verifies:
 *   - lib/statement.php helpers (bucketing, render)
 *   - api/send_statement.php (preview + send + RBAC + idempotency key)
 *   - AgingTable.jsx wiring (Email statement button + preview modal)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$parses = fn (string $p): bool => is_file($p)
    && (int) shell_exec('php -l ' . escapeshellarg($p) . ' >/dev/null 2>&1; echo $?') === 0;

echo "Library: modules/billing/lib/statement.php\n";
$libPath = __DIR__ . '/../modules/billing/lib/statement.php';
$a('parses', $parses($libPath));
require_once $libPath;
foreach (['billingStatementOpenInvoices','billingStatementBucket','billingStatementResolveRecipients','billingStatementRenderEmail'] as $fn) {
    $a("fn: {$fn}", function_exists($fn));
}

echo "\nbillingStatementBucket() — aging bucket math matches AR Aging page\n";
$today = '2026-02-10';
$inv = [
    ['amount_due' => 100, 'days_overdue' => 0],   // current
    ['amount_due' => 200, 'days_overdue' => 1],   // 1-30
    ['amount_due' => 50,  'days_overdue' => 30],  // 1-30 boundary
    ['amount_due' => 300, 'days_overdue' => 45],  // 31-60
    ['amount_due' => 75,  'days_overdue' => 80],  // 61-90
    ['amount_due' => 400, 'days_overdue' => 100], // 91+
];
$b = billingStatementBucket($inv);
$a('current bucket = 100', abs($b['current'] - 100.0) < 0.001);
$a('1-30 bucket = 250',    abs($b['1_30']    - 250.0) < 0.001);
$a('31-60 bucket = 300',   abs($b['31_60']   - 300.0) < 0.001);
$a('61-90 bucket = 75',    abs($b['61_90']   - 75.0) < 0.001);
$a('91+ bucket = 400',     abs($b['91_plus'] - 400.0) < 0.001);
$a('total = 1125',         abs($b['total']   - 1125.0) < 0.001);
$bz = billingStatementBucket([]);
$a('empty input → all zeros', $bz['total'] === 0.0 && $bz['current'] === 0.0);

echo "\nbillingStatementRenderEmail() — content shape\n";
$inv2 = [
    ['id' => 11, 'invoice_number' => 'INV-001', 'due_date' => '2026-01-01', 'amount_due' => 1500, 'currency' => 'USD', 'days_overdue' => 40],
    ['id' => 12, 'invoice_number' => 'INV-002', 'due_date' => '2026-02-15', 'amount_due' => 500,  'currency' => 'USD', 'days_overdue' => 0],
];
$buckets = billingStatementBucket($inv2);
$e = billingStatementRenderEmail('Acme Staffing', 'Globex', $inv2, $buckets, $today);
$a('subject mentions count',        str_contains($e['subject'], '2 open invoice'));
$a('subject mentions total',        str_contains($e['subject'], '2,000.00'));
$a('html includes client name',     str_contains($e['html'], 'Globex'));
$a('html lists INV-001',            str_contains($e['html'], 'INV-001'));
$a('html lists INV-002',            str_contains($e['html'], 'INV-002'));
$a('html shows 40d past',           str_contains($e['html'], '40d'));
$a('html includes tenant name in footer',   str_contains($e['html'], 'Acme Staffing'));
$a('html escapes <script>',         str_contains(billingStatementRenderEmail('a<b>c', 'd', [], billingStatementBucket([]), $today)['html'], 'a&lt;b&gt;c'));
$a('text has heading + total',      str_contains($e['text'], 'Statement of account') && str_contains($e['text'], 'TOTAL DUE:'));
$a('singular when 1 invoice',       str_contains(billingStatementRenderEmail('T','C',[$inv2[0]],billingStatementBucket([$inv2[0]]),$today)['subject'], '1 open invoice '));

echo "\nAPI: modules/billing/api/send_statement.php\n";
$apiPath = __DIR__ . '/../modules/billing/api/send_statement.php';
$api     = (string) file_get_contents($apiPath);
$a('parses',                                          $parses($apiPath));
$a('GET preview: requires billing.view',              str_contains($api, "RBAC::requirePermission(\$user, \$dryRun ? \$RBAC_PREVIEW : \$RBAC_SEND)"));
$a('preview perm constant is billing.view',           str_contains($api, "\$RBAC_PREVIEW = 'billing.view'"));
$a('send perm constant is billing.invoice.create',    str_contains($api, "\$RBAC_SEND    = 'billing.invoice.create'"));
$a('GET treated as dry-run',                          str_contains($api, "\$dryRun     = \$method === 'GET' || !empty(\$body['dry_run'])"));
$a('validates client_name required',                  str_contains($api, "api_error('client_name required', 422)"));
$a('validates as_of format',                          str_contains($api, "api_error('as_of must be YYYY-MM-DD', 422)"));
$a('409 when nothing outstanding',                    str_contains($api, 'Nothing outstanding for'));
$a('422 when no AR contact resolved',                 str_contains($api, "No AR contact on file for this client. Add one in Client contacts and retry."));
$a('uses cf_mail_bootstrap',                          str_contains($api, '$svc    = cf_mail_bootstrap();'));
$a('uses cf_tenant_mail_sender(tid, billing)',        str_contains($api, "cf_tenant_mail_sender(\$tid, 'billing')"));
$a('CC line includes escalation_email',               str_contains($api, "'cc'        => \$recipients['cc']"));
$a("template_key = 'ar_statement'",                   str_contains($api, "'ar_statement'"));
$a('idempotency keyed by tenant+client+date',         str_contains($api, "\"statement-{\$tid}-{\$slug}-\" . date('Y-m-d')"));
$a('slug sanitises non-alnum to dashes',              str_contains($api, "preg_replace('/[^a-z0-9]+/', '-', strtolower(\$clientName))"));
$a('writes audit event on success',                   str_contains($api, "billingAudit('billing.statement.sent'"));
$a('send returns sent_to + cc + count + total_due',   str_contains($api, "'sent_to'") && str_contains($api, "'cc'") && str_contains($api, "'count'") && str_contains($api, "'total_due'"));
$a('preview returns email + buckets + recipients',    str_contains($api, "'preview'    => true") && str_contains($api, "'email'      => \$email"));

echo "\nUI: modules/billing/ui/AgingTable.jsx\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/AgingTable.jsx');
foreach (['billing-aging','billing-aging-email-statement-${i}','billing-aging-statement-modal','billing-aging-statement-to','billing-aging-statement-html','billing-aging-statement-send','billing-aging-statement-cancel'] as $tid) {
    $a("testid: {$tid}",                              str_contains($ui, $tid));
}
$a('testid: billing-aging-statement-sent/error (dynamic)', str_contains($ui, "`billing-aging-statement-\${toast.kind === 'ok' ? 'sent' : 'error'}`"));
$a('preview calls GET ?client_name=',                 str_contains($ui, 'api.get(`/modules/billing/api/send_statement.php?client_name=${encodeURIComponent(clientName)}&as_of=${asOf}`)'));
$a('send calls POST send_statement.php',              str_contains($ui, "api.post('/modules/billing/api/send_statement.php',"));
$a('disables Send button when no AR contact',         str_contains($ui, 'disabled={busy || !to}'));
$a('shows no-contact warning state',                  str_contains($ui, 'billing-aging-statement-no-contact'));
$a('shows preview email body',                        str_contains($ui, 'dangerouslySetInnerHTML={{ __html: preview?.email?.html || \'\' }}'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
