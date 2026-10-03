<?php
/** Contract for the canonical AP clearance simulation and audit. */
declare(strict_types=1);

$root = dirname(__DIR__);
$runner = (string) file_get_contents($root . '/sim/runner.php');
$invariants = (string) file_get_contents($root . '/sim/lib/invariants.php');
$audit = (string) file_get_contents($root . '/sim/audit_ap_payment_lineage.php');
$scenario = json_decode((string) file_get_contents(
    $root . '/sim/scenarios/ap_payment_canonical_lifecycle.json'
), true);
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$check('scenario has an AP bill and canonical payment step', is_array($scenario)
    && $scenario['name'] === 'ap_payment_canonical_lifecycle'
    && in_array('create_ap_bill', array_column($scenario['steps'], 'action'), true)
    && in_array('clear_ap_payment', array_column($scenario['steps'], 'action'), true));
$check('scenario requires source lineage and deterministic replay', is_array($scenario)
    && in_array('ap_payment_canonical_lineage', $scenario['invariants'], true)
    && in_array('replay_reproducible', $scenario['invariants'], true));
$check('runner routes payment to the shared AP clearing service',
    str_contains($runner, "case 'clear_ap_payment':")
    && str_contains($runner, 'apAllocatePayment($paymentId')
    && str_contains($runner, 'apClearPayment($tenantId, $paymentId'));
$check('payment reservation preserves an outer transaction',
    str_contains($runner, '$ownsTransaction = !$pdo->inTransaction()')
    && str_contains($runner, 'if ($ownsTransaction) $pdo->commit()')
    && str_contains($runner, 'if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack()'));
$check('invariant verifies correction-grade source lineage',
    str_contains($invariants, 'function simInvariantCanonicalApPayment(')
    && str_contains($invariants, 'apInspectClearedManualPayment($pdo, $tenantId, $payment)'));
$check('audit is staging-only and read-only by construction',
    str_contains($audit, "getenv('COREFLUX_ENV') !== 'staging'")
    && str_contains($audit, "'read_only' => true")
    && !str_contains($audit, 'INSERT INTO ')
    && !str_contains($audit, 'UPDATE ap_')
    && !str_contains($audit, 'DELETE FROM '));

echo "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
