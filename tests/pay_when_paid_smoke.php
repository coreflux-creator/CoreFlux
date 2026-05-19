<?php
/**
 * Smoke: AP Pay-When-Paid (PWP) feature.
 *
 * Static contract checks only — no live DB. Verifies:
 *   - migration 017_pay_when_paid.sql adds the 4 columns + index + vendor flag
 *   - modules/ap/lib/pwp.php exposes the documented functions
 *   - apPwpParseTerms() correctly classifies NET / PWP / PWP_NET<N>
 *   - billingAllocatePayment() releases PWP bills after AR is fully paid
 *   - billing's from-time-bundle auto-links matching AP bills
 *   - modules/ap/api/pwp.php exposes the 5 actions with correct RBAC
 *   - modules/billing/ui/InvoiceDetail.jsx gained the Preview/Download PDF buttons
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

echo "Migration: 017_pay_when_paid.sql\n";
$migPath = __DIR__ . '/../modules/ap/migrations/017_pay_when_paid.sql';
$mig = (string) file_get_contents($migPath);
$a('migration exists',                          is_file($migPath));
$a('adds payment_terms column to ap_bills',     str_contains($mig, "TABLE_NAME='ap_bills' AND COLUMN_NAME='payment_terms'"));
$a('adds linked_ar_invoice_id column',          str_contains($mig, "COLUMN_NAME='linked_ar_invoice_id'"));
$a('adds pwp_status ENUM column',               str_contains($mig, "COLUMN_NAME='pwp_status'") && str_contains($mig, "ENUM('not_pwp','awaiting_ar','triggered','partial_triggered')"));
$a('adds pwp_released_at column',               str_contains($mig, "COLUMN_NAME='pwp_released_at'"));
$a('adds idx_apb_pwp_linked index',             str_contains($mig, 'idx_apb_pwp_linked'));
$a('adds default_pwp flag on vendors_index',    str_contains($mig, "TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='default_pwp'"));
$a('migration is idempotent (info_schema)',     substr_count($mig, 'information_schema.COLUMNS') >= 5);

echo "\nLibrary: modules/ap/lib/pwp.php\n";
$libPath = __DIR__ . '/../modules/ap/lib/pwp.php';
$a('pwp.php exists',                            is_file($libPath));
$a('pwp.php parses',                            (int) shell_exec('php -l ' . escapeshellarg($libPath) . ' >/dev/null 2>&1; echo $?') === 0);
require_once $libPath;
foreach (['apPwpParseTerms','apPwpAutoLinkForArInvoice','apPwpSetLink','apPwpClearLink','apPwpReleaseForArInvoice'] as $fn) {
    $a("fn: {$fn}",                             function_exists($fn));
}

echo "\napPwpParseTerms() classifier\n";
$r = apPwpParseTerms('NET30');
$a("NET30 → not pwp, 30 days",                  $r['is_pwp'] === false && $r['net_days'] === 30);
$r = apPwpParseTerms('PWP');
$a("PWP → pwp, 0 days",                         $r['is_pwp'] === true  && $r['net_days'] === 0);
$r = apPwpParseTerms('PWP_NET10');
$a("PWP_NET10 → pwp, 10 days",                  $r['is_pwp'] === true  && $r['net_days'] === 10);
$r = apPwpParseTerms('pwp_net45');
$a("lower-case parsed",                         $r['is_pwp'] === true  && $r['net_days'] === 45);
$r = apPwpParseTerms('NET60');
$a("NET60 → not pwp, 60 days",                  $r['is_pwp'] === false && $r['net_days'] === 60);
$r = apPwpParseTerms('garbage');
$a("garbage → not pwp, default 30",             $r['is_pwp'] === false && $r['net_days'] === 30);
$r = apPwpParseTerms(null);
$a("null → not pwp, default 30",                $r['is_pwp'] === false && $r['net_days'] === 30);

echo "\nbillingAllocatePayment() triggers PWP\n";
$billingLib = (string) file_get_contents(__DIR__ . '/../modules/billing/lib/billing.php');
$a('lib loads ap/lib/pwp.php on demand',        str_contains($billingLib, "@require_once __DIR__ . '/../../ap/lib/pwp.php'"));
$a('release runs AFTER commit (durable AR)',    preg_match('/\$pdo->commit\(\);\s*\n\s*\/\/ Pay-When-Paid trigger/s', $billingLib) === 1);
$a('release only for newly-paid invoices',      str_contains($billingLib, "(\$a['new_status'] ?? null) !== 'paid'"));
$a('release calls apPwpReleaseForArInvoice',    str_contains($billingLib, 'apPwpReleaseForArInvoice($tenantId, (int) $a[\'invoice_id\'], $actorUserId)'));
$a('release errors are non-fatal (logged)',     str_contains($billingLib, "[billingAllocatePayment] PWP release failed"));
$a('response includes pwp results',             str_contains($billingLib, "'pwp' => \$pwpResults"));

echo "\nfrom-time-bundle auto-link\n";
$invSrc = (string) file_get_contents(__DIR__ . '/../modules/billing/api/invoices.php');
$a('requires ap/lib/pwp.php',                   str_contains($invSrc, "require_once __DIR__ . '/../../ap/lib/pwp.php'"));
$a('auto-link after commit',                    str_contains($invSrc, 'apPwpAutoLinkForArInvoice($tid, (int) $c[\'id\']'));
$a('auto-link errors non-fatal',                str_contains($invSrc, "[billing.invoices.from-time-bundle] PWP auto-link failed"));

echo "\nAPI: modules/ap/api/pwp.php\n";
$apiPath = __DIR__ . '/../modules/ap/api/pwp.php';
$apiSrc  = (string) file_get_contents($apiPath);
$a('pwp.php exists',                            is_file($apiPath));
$a('pwp.php parses',                            (int) shell_exec('php -l ' . escapeshellarg($apiPath) . ' >/dev/null 2>&1; echo $?') === 0);
$a('uses api_bootstrap',                        str_contains($apiSrc, "require_once __DIR__ . '/../../../core/api_bootstrap.php'"));
$a('uses RBAC',                                 str_contains($apiSrc, "rbac_legacy_require"));
$a("GET ?action=preview",                       str_contains($apiSrc, "\$method === 'GET' && \$action === 'preview'"));
$a("POST ?action=auto_link",                    str_contains($apiSrc, "\$method === 'POST' && \$action === 'auto_link'"));
$a("POST ?action=link",                         str_contains($apiSrc, "\$method === 'POST' && \$action === 'link'"));
$a("POST ?action=unlink",                       str_contains($apiSrc, "\$method === 'POST' && \$action === 'unlink'"));
$a("POST ?action=release_for_invoice",          str_contains($apiSrc, "\$method === 'POST' && \$action === 'release_for_invoice'"));
$a('link guarded by ap.bill.create',            preg_match("/action === 'link'[\s\S]{0,300}'ap\.bill\.create'/", $apiSrc) === 1);
$a('release guarded by ap.bill.approve',        preg_match("/action === 'release_for_invoice'[\s\S]{0,300}'ap\.bill\.approve'/", $apiSrc) === 1);
$a('preview guarded by ap.bill.view',           preg_match("/action === 'preview'[\s\S]{0,300}'ap\.bill\.view'/", $apiSrc) === 1);

echo "\nReact: InvoiceDetail.jsx Preview/Download PDF buttons\n";
$jsxSrc = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/InvoiceDetail.jsx');
$a('has Preview PDF button',                    str_contains($jsxSrc, 'billing-invoice-preview-pdf'));
$a('has Download PDF button',                   str_contains($jsxSrc, 'billing-invoice-download-pdf'));
$a('preview opens new tab',                     str_contains($jsxSrc, "window.open(`/modules/billing/api/invoices.php?action=pdf&id=") && str_contains($jsxSrc, "_blank"));
$a('download forces ?download=1',               str_contains($jsxSrc, "action=pdf&id=\${id}&download=1"));
$a('send-modal warns on pdf_attached=false',    str_contains($jsxSrc, 'res.pdf_attached === false'));

echo "\nReact: PaymentsList.jsx surfaces PWP toast after allocation\n";
$payJsx = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/PaymentsList.jsx');
$a('PaymentsList captures pwp array from API',       str_contains($payJsx, 'res?.pwp') && str_contains($payJsx, 'auto_allocation?.pwp'));
$a('renders dismissible billing-pwp-toast',          str_contains($payJsx, 'data-testid="billing-pwp-toast"') && str_contains($payJsx, 'billing-pwp-toast-dismiss'));
$a('toast lists per-bill release details',          str_contains($payJsx, 'billing-pwp-released-${r.bill_id}'));
$a('AllocateModal returns API result',              substr_count($payJsx, 'onSaved?.(res)') >= 2);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
