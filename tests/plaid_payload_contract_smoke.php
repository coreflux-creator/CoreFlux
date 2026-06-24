<?php
/**
 * Smoke — Plaid payload shape vs `spec/plaid_schema.json`.
 *
 * Plaid is read-mostly (Transfers + Item link + Account balances); it
 * has no chart-of-accounts and no procedural verifier. Charter
 * primitives #4 (mapping fallback) and #5 (verifyCreate) are scoped
 * narrower as a result — this contract smoke locks the payload field
 * set against the real call sites in:
 *   - core/payment_rails/plaid_transfer_driver.php (Transfer create)
 *   - api/plaid_exchange.php (Item public_token exchange)
 *   - api/plaid_items.php / core/plaid_service.php (Account balances)
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nPlaid payload contract smoke\n";
echo "=============================\n\n";

$spec = json_decode((string) file_get_contents('/app/spec/plaid_schema.json'), true);
check('spec/plaid_schema.json parses', is_array($spec));
foreach (['TransferCreate', 'TransferUserInRequest',
          'ItemPublicTokenExchange', 'AccountsBalanceGet'] as $d) {
    check("definitions[{$d}].writableProperties present",
        isset($spec['definitions'][$d]['writableProperties']));
}

// ── Transfer create call site ──
echo "\n── transfer_driver.php payload ──\n";
$driverPath = '/app/core/payment_rails/plaid_transfer_driver.php';
check('plaid_transfer_driver.php exists', is_file($driverPath));
$drv = (string) file_get_contents($driverPath);

// Plaid Transfer body keys actually emitted by the driver.
$transferKeys = ['access_token', 'account_id', 'type', 'amount', 'description'];
foreach ($transferKeys as $k) {
    check("driver references '{$k}'", str_contains($drv, "'{$k}'") || str_contains($drv, "\"{$k}\""));
}
$allowedTransfer = $spec['definitions']['TransferCreate']['writableProperties'];
foreach ($transferKeys as $k) {
    check("'{$k}' is in TransferCreate.writableProperties",
        in_array($k, $allowedTransfer, true));
}

// ── description.maxLength enforcement ──
check('TransferCreate.description.maxLength = 15',
    ($spec['definitions']['TransferCreate']['constraints']['description.maxLength'] ?? null) === 15);

// ── Item exchange call site ──
echo "\n── plaid_exchange.php payload ──\n";
$exPath = '/app/api/plaid_exchange.php';
check('plaid_exchange.php exists', is_file($exPath));
$ex = (string) file_get_contents($exPath);
check('exchange uses public_token field',
    str_contains($ex, 'public_token'));
$exAllowed = $spec['definitions']['ItemPublicTokenExchange']['writableProperties'];
check("'public_token' is in ItemPublicTokenExchange.writableProperties",
    in_array('public_token', $exAllowed, true));

// ── Webhook event taxonomy ──
echo "\n── webhook event types ──\n";
$wh = $spec['definitions']['TransfersWebhook']['events'] ?? [];
check('webhook events include TRANSFER_EVENTS_UPDATE', in_array('TRANSFER_EVENTS_UPDATE', $wh, true));

// ── Error code mapping completeness ──
echo "\n── error code mapping ──\n";
foreach (['INVALID_REQUEST','ITEM_LOGIN_REQUIRED','INSUFFICIENT_FUNDS',
          'RATE_LIMIT_EXCEEDED','INVALID_ACCESS_TOKEN'] as $code) {
    check("errorCodes[{$code}] mapped", isset($spec['errorCodes'][$code]));
}

echo "\nplaid_payload_contract smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
exit($failures ? 1 : 0);
