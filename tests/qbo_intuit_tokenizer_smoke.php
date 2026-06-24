<?php
/**
 * Smoke — Intuit hosted tokenizer (Step 6 Phase 5).
 *
 * Locks:
 *   - QboPaymentsCollectModal exposes mode picker (live / paste).
 *   - Loads https://js.intuit.com/v1/intuit-js once per session.
 *   - Calls intuit.ipp.payments.tokenize() with card or bankAccount payload.
 *   - Submits the resulting token to /api/admin/qbo/payments_charge.php.
 *   - Falls back to paste mode when window.__INTUIT_PAYMENTS_KEY is unset.
 *   - spa.php exposes INTUIT_PAYMENTS_PUBLISHABLE_KEY → window.__INTUIT_PAYMENTS_KEY.
 *   - Bundle build picked up the modal (data-testids present in JS output).
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nQBO Intuit hosted tokenizer smoke (Step 6 Phase 5)\n";
echo "===================================================\n\n";

// ─── Modal source ───
echo "── QboPaymentsCollectModal.jsx ──\n";
$src = (string) file_get_contents('/app/modules/billing/ui/QboPaymentsCollectModal.jsx');
check('reads window.__INTUIT_PAYMENTS_KEY',     str_contains($src, '__INTUIT_PAYMENTS_KEY'));
check('reads window.__INTUIT_PAYMENTS_ENV (sandbox/prod)',
    str_contains($src, '__INTUIT_PAYMENTS_ENV'));
check('loads https://js.intuit.com/v1/intuit-js',
    str_contains($src, "'https://js.intuit.com/v1/intuit-js'"));
check('defaults mode=live when publishable key is set',
    str_contains($src, "useState(liveAvailable ? 'live' : 'paste')"));
check('exposes mode radio: live + paste',
    str_contains($src, 'data-testid="qbo-payments-mode-live"') &&
    str_contains($src, 'data-testid="qbo-payments-mode-paste"'));
check('calls intuit.ipp.payments.init(publishableKey)',
    str_contains($src, 'window.intuit.ipp.payments.init(publishableKey'));
check('invokes intuit.ipp.payments.tokenize',
    str_contains($src, 'window.intuit.ipp.payments.tokenize('));
check('card tokenize payload includes number/expMonth/expYear/cvc',
    str_contains($src, 'number:') &&
    str_contains($src, 'expMonth:') &&
    str_contains($src, 'expYear:') &&
    str_contains($src, 'cvc:'));
check('bank tokenize payload uses routingNumber/accountNumber',
    str_contains($src, 'routingNumber:') &&
    str_contains($src, 'accountNumber:') &&
    str_contains($src, "accountType:   'CHECKING'"));
check('error path surfaces resp.errors[0].message',
    str_contains($src, "resp?.errors?.[0]?.message"));
check('SDK-loading state disables submit button',
    str_contains($src, "(mode === 'live' && !sdkReady)"));
check('paste mode still posts to charge endpoint',
    str_contains($src, "api.post('/api/admin/qbo/payments_charge.php'"));
check('gracefully degrades when publishable key absent',
    str_contains($src, "data-testid=\"qbo-payments-live-unavailable\""));
check('SDK error renders qbo-payments-sdk-error testid',
    str_contains($src, "data-testid=\"qbo-payments-sdk-error\""));
check('SDK loading hint testid present',
    str_contains($src, "data-testid=\"qbo-payments-sdk-loading\""));
check('only one tokenize callback per submit (no infinite loop)',
    substr_count($src, 'window.intuit.ipp.payments.tokenize(') === 1);
check('loads SDK only once via ref guard',
    str_contains($src, 'sdkLoaded.current = true'));

// ─── spa.php bridge ───
echo "\n── spa.php config bridge ──\n";
$spa = (string) file_get_contents('/app/spa.php');
check('spa.php injects window.__INTUIT_PAYMENTS_KEY from env',
    str_contains($spa, "window.__INTUIT_PAYMENTS_KEY = <?php echo json_encode((string) (getenv('INTUIT_PAYMENTS_PUBLISHABLE_KEY')"));
check('spa.php injects window.__INTUIT_PAYMENTS_ENV from env',
    str_contains($spa, "window.__INTUIT_PAYMENTS_ENV = <?php echo json_encode((string) (getenv('INTUIT_PAYMENTS_ENV')"));
check('defaults env to sandbox',                str_contains($spa, ": 'sandbox'"));

// ─── Built bundle picked up the changes ───
echo "\n── Vite bundle ──\n";
// Use .deploy-version as the canonical pointer (sync_bundle.sh updates
// it on every build). filemtime-based picking is unreliable when stale
// bundles linger in /app/spa-assets — exactly the rake the deploy
// version stamp was introduced to step over.
$deployVer = (string) file_get_contents('/app/.deploy-version');
if (preg_match('/^- spa-assets\/(index-[A-Za-z0-9_\-]+\.js)/m', $deployVer, $m)) {
    $jsBundle = $m[1];
} else {
    $jsBundle = '';
}
if ($jsBundle === '' || !is_file('/app/spa-assets/' . $jsBundle)) {
    check('Vite bundle present', false);
} else {
    check('Vite bundle present',  true);
    $bundle = (string) file_get_contents('/app/spa-assets/' . $jsBundle);
    check('bundle includes qbo-payments-modal testid',
        str_contains($bundle, 'qbo-payments-modal'));
    check('bundle includes Intuit SDK URL',
        str_contains($bundle, 'https://js.intuit.com/v1/intuit-js'));
}

echo "\nqbo_intuit_tokenizer smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
