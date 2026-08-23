<?php
declare(strict_types=1);

$passes = 0;
$failures = [];
$check = static function (string $label, bool $ok) use (&$passes, &$failures): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $passes++ : $failures[] = $label;
};

$script = (string) file_get_contents(__DIR__ . '/../scripts/qbo_sandbox_payment_smoke.php');
$workflow = (string) file_get_contents(__DIR__ . '/../.github/workflows/deploy-cloudways.yml');

$check('live smoke is CLI-only', str_contains($script, "PHP_SAPI !== 'cli'"));
$check('requires explicit one-dollar confirmation phrase', str_contains($script, "!== 'charge_one_dollar'"));
$check('hard-gates global environment to sandbox', str_contains($script, "qboEnvironment() !== 'sandbox'"));
$check('hard-gates selected connection to sandbox', str_contains($script, "connection['environment']") && str_contains($script, "!== 'sandbox'"));
$check('requires exact numeric realm id', str_contains($script, "preg_match('/^\\d{6,32}$/'"));
$check('duplicate realms require the fresh consumed OAuth tenant', str_contains($script, 'CURRENT_TIMESTAMP - INTERVAL 2 HOUR') && str_contains($script, 'ORDER BY consumed_at DESC, id DESC'));
$check('uses Intuit sandbox token endpoint', str_contains($script, "QBO_PAYMENTS_API_SANDBOX . '/quickbooks/v4/payments/tokens'"));
$check('charges exactly one dollar', str_contains($script, "'amount'          => 1.00"));
$check('persists a QBO shadow for retrieval verification', str_contains($script, 'qboRecordChargeShadow') && str_contains($script, 'qboGetCharge'));
$check('does not create or allocate a CoreFlux billing payment', !str_contains($script, 'qboApplyCapturedPayment') && !str_contains($script, 'billingAllocatePayment'));
$check('verifies invoice and payment links remain null', str_contains($script, "coreflux_invoice_id'] !== null") && str_contains($script, "coreflux_payment_id'] !== null"));
$check('workflow exposes an explicit charge choice', str_contains($workflow, 'qbo_sandbox_payment_smoke:') && str_contains($workflow, '- charge_one_dollar'));
$check('workflow uploads and invokes the guarded script', str_contains($workflow, 'scripts/qbo_sandbox_payment_smoke.php') && str_contains($workflow, 'php scripts/qbo_sandbox_payment_smoke.php'));

echo PHP_EOL . "QBO sandbox payment smoke guard: {$passes} ✓ / " . count($failures) . " ✗" . PHP_EOL;
exit($failures ? 1 : 0);
