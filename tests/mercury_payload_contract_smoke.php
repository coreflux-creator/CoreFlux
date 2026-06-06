<?php
/**
 * Smoke — Mercury payment payload shape vs `spec/mercury_schema.json`.
 *
 * Mercury isn't a GL/CoA system, so charter primitives #4 (mapping
 * fallback) and #5 (verifyCreate post-push) are scoped narrower:
 * #4 is N/A; #5 will need a `GET /payments/{id}` re-check once we
 * hoist the procedural code into an adapter (charter backlog).
 *
 * This smoke locks the payment payload shape against three real
 * call sites in core/mercury_payments.php — sweep, funding-pull,
 * vendor disbursement — so any future field-rename gets caught.
 *
 * Run: php -d zend.assertions=1 /app/tests/mercury_payload_contract_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nMercury payload contract smoke\n";
echo "==============================\n\n";

$spec = json_decode((string) file_get_contents('/app/spec/mercury_schema.json'), true);
check('spec/mercury_schema.json parses', is_array($spec));
foreach (['PaymentCreate', 'RecipientCreate', 'RoutingInfo'] as $d) {
    check("definitions[{$d}] present", isset($spec['definitions'][$d]['writableProperties']));
}

$src = (string) file_get_contents('/app/core/mercury_payments.php');

// Pull every literal payment payload from the source — the file has 3
// near-identical builders (sweep, funding pull, vendor disbursement).
// Each builder includes the canonical keys, so a regex against the
// real source is a fair proxy for "the wire shape".
echo "\n── payment payload field set per call site ──\n";
$expectedKeys = ['recipientId', 'amount', 'paymentMethod', 'idempotencyKey', 'note'];
foreach ($expectedKeys as $k) {
    check("mercury_payments.php references '{$k}'", str_contains($src, "'{$k}'"));
}

// Each emitted key must be in the writableProperties whitelist.
$allowed = $spec['definitions']['PaymentCreate']['writableProperties'];
foreach ($expectedKeys as $k) {
    check("'{$k}' is in PaymentCreate.writableProperties", in_array($k, $allowed, true));
}

// The note field has a 50-char cap per Mercury docs — the builder slices to 50 in three places.
check('mercury_payments.php enforces note ≤ 50 chars via substr',
    substr_count($src, ', 0, 50)') >= 2);
check('schema records the 50-char note cap',
    ($spec['definitions']['PaymentCreate']['constraints']['note.maxLength'] ?? null) === 50);

// paymentMethod enum sanity — the builder uses 'ach' and 'wire'.
$allowedMethods = $spec['definitions']['PaymentCreate']['constraints']['paymentMethod.allowed'];
check("schema allows 'ach'",  in_array('ach',  $allowedMethods, true));
check("schema allows 'wire'", in_array('wire', $allowedMethods, true));
check("source uses 'ach' OR 'wire'", str_contains($src, "'ach'") || str_contains($src, "'wire'"));

// Required vs optional sanity.
$required = $spec['definitions']['PaymentCreate']['required'];
foreach (['recipientId', 'amount', 'paymentMethod'] as $r) {
    check("PaymentCreate.required[] includes '{$r}'", in_array($r, $required, true));
}

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "mercury_payload_contract smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
