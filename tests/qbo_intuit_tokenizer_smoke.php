<?php
/**
 * Smoke — direct Intuit Payments tokenization.
 *
 * Locks:
 *   - Card/bank details go from the browser straight to Intuit's
 *     documented /quickbooks/v4/payments/tokens endpoint.
 *   - CoreFlux receives only the opaque value token.
 *   - The endpoint environment comes from the authenticated QBO status.
 *   - No dependency remains on the retired/non-resolving intuit-js host.
 *   - Paste-token mode remains available for sandbox/support diagnostics.
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nQBO direct Intuit tokenizer smoke\n";
echo "===================================================\n\n";

echo "── QboPaymentsCollectModal.jsx ──\n";
$src = (string) file_get_contents('/app/modules/billing/ui/QboPaymentsCollectModal.jsx');
check('defaults the QBO environment to sandbox',
    str_contains($src, "environment = 'sandbox'"));
check('allowlists production and otherwise uses sandbox',
    str_contains($src, "environment === 'production' ? 'production' : 'sandbox'"));
check('uses Intuit production token host',
    str_contains($src, 'https://api.intuit.com'));
check('uses Intuit sandbox token host',
    str_contains($src, 'https://sandbox.api.intuit.com'));
check('uses documented Payments token path',
    str_contains($src, '/quickbooks/v4/payments/tokens'));
check('POSTs payment details directly to token endpoint',
    str_contains($src, 'fetch(tokenEndpoint, {')
    && str_contains($src, "method: 'POST'"));
check('token request carries JSON and Request-Id headers',
    str_contains($src, "'Content-Type': 'application/json'")
    && str_contains($src, "'Request-Id': requestId"));
check('token request does not carry an OAuth bearer token',
    !str_contains($src, 'Authorization:')
    && !str_contains($src, "'Authorization'"));
check('card token payload includes number/expiry/cvc',
    str_contains($src, 'number:')
    && str_contains($src, 'expMonth:')
    && str_contains($src, 'expYear:')
    && str_contains($src, 'cvc:'));
check('bank token payload uses Intuit bank-account fields',
    str_contains($src, 'routingNumber:')
    && str_contains($src, 'accountNumber:')
    && str_contains($src, "accountType:   'PERSONAL_CHECKING'"));
check('bank-account name is the holder, not the bank name',
    str_contains($src, 'name:          holder.trim()')
    && str_contains($src, 'bankName:      bankName.trim()'));
check('extracts only the opaque value token',
    str_contains($src, 'return data.value;'));
check('surfaces Intuit error envelope messages',
    str_contains($src, 'data.errors[0]')
    && str_contains($src, 'firstError?.message'));
check('defaults to direct tokenization',
    str_contains($src, "useState('direct')"));
check('exposes direct and paste modes',
    str_contains($src, 'data-testid="qbo-payments-mode-direct"')
    && str_contains($src, 'data-testid="qbo-payments-mode-paste"'));
check('shows the selected Intuit environment',
    str_contains($src, 'data-testid="qbo-payments-token-environment"'));
check('posts opaque token to CoreFlux charge endpoint',
    str_contains($src, "api.post('/api/admin/qbo/payments_charge.php'")
    && str_contains($src, 'token:      tok'));
check('retries reuse one Request-Id per immutable payment intent',
    str_contains($src, 'idempotencyRef.current.intent !== intent')
    && str_contains($src, 'idempotency_key: idempotencyRef.current.key'));
check('does not reference nonexistent intuit-js SDK or publishable key',
    !str_contains($src, 'js.intuit.com')
    && !str_contains($src, '__INTUIT_PAYMENTS_KEY')
    && !str_contains($src, 'intuit.ipp.payments'));

echo "\n── Environment source ──\n";
$list = (string) file_get_contents('/app/modules/billing/ui/InvoicesList.jsx');
check('modal receives environment from authenticated QBO status',
    str_contains($list, 'environment={qboStatus.data?.environment}'));
$spa = (string) file_get_contents('/app/spa.php');
check('SPA does not expose obsolete Payments keys',
    !str_contains($spa, '__INTUIT_PAYMENTS_KEY')
    && !str_contains($spa, 'INTUIT_PAYMENTS_PUBLISHABLE_KEY'));

echo "\n── Server e-check shape ──\n";
$payments = (string) file_get_contents('/app/core/qbo/payments_client.php');
check('e-check debit declares WEB payment mode',
    str_contains($payments, "'paymentMode' => 'WEB'"));
check('e-check debit supplies a stable check number',
    str_contains($payments, "'checkNumber' => substr(str_pad("));

echo "\n── Vite bundle ──\n";
$deployVer = (string) file_get_contents('/app/.deploy-version');
if (preg_match('/^- spa-assets\/(index-[A-Za-z0-9_\-]+\.js)/m', $deployVer, $m)) {
    $jsBundle = $m[1];
} else {
    $jsBundle = '';
}
if ($jsBundle === '' || !is_file('/app/spa-assets/' . $jsBundle)) {
    check('Vite bundle present', false);
} else {
    check('Vite bundle present', true);
    $bundle = (string) file_get_contents('/app/spa-assets/' . $jsBundle);
    check('bundle includes payment modal and direct token path',
        str_contains($bundle, 'qbo-payments-modal')
        && str_contains($bundle, '/quickbooks/v4/payments/tokens'));
    check('bundle excludes nonexistent Intuit JS SDK',
        !str_contains($bundle, 'https://js.intuit.com/v1/intuit-js'));
    check('bundle includes durable payment-intent Request-Id',
        str_contains($bundle, 'idempotency_key') && str_contains($bundle, 'cf-qbo-'));
    check('bundle includes Payments OAuth status and re-consent UI',
        str_contains($bundle, 'qbo-payments-scope-status')
        && str_contains($bundle, 'qbo-reconsent-btn'));
}

echo "\nqbo direct Intuit tokenizer smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
