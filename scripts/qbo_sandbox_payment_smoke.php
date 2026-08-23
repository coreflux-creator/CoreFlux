<?php
/**
 * One-dollar live smoke for the QuickBooks Payments sandbox.
 *
 * This is deliberately CLI-only and guarded by both an explicit execution
 * phrase and QBO_ENV=sandbox. It creates an Intuit sandbox charge and records
 * the QBO shadow/audit trail, but it never creates a CoreFlux invoice,
 * billing payment, or allocation.
 *
 * Usage (normally through deploy-cloudways.yml):
 *   COREFLUX_QBO_SANDBOX_SMOKE=charge_one_dollar \
 *     php scripts/qbo_sandbox_payment_smoke.php <realm-id>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This smoke can only run from the CLI.\n");
    exit(64);
}

require_once __DIR__ . '/../core/qbo/payments_client.php';

$confirmation = (string) getenv('COREFLUX_QBO_SANDBOX_SMOKE');
$realmId = trim((string) ($argv[1] ?? ''));

if ($confirmation !== 'charge_one_dollar') {
    fwrite(STDERR, "Refusing to charge: set COREFLUX_QBO_SANDBOX_SMOKE=charge_one_dollar.\n");
    exit(64);
}
if (qboEnvironment() !== 'sandbox' || qboPaymentsBaseUrl() !== QBO_PAYMENTS_API_SANDBOX) {
    fwrite(STDERR, "Refusing to charge: QuickBooks is not configured for sandbox.\n");
    exit(64);
}
if (!preg_match('/^\d{6,32}$/', $realmId)) {
    fwrite(STDERR, "Refusing to charge: provide the exact numeric sandbox realm ID.\n");
    exit(64);
}

$stmt = getDB()->prepare(
    'SELECT tenant_id, realm_id, company_name, environment, scope, status
       FROM qbo_connections
      WHERE realm_id = :realm_id AND status = "active"'
);
$stmt->execute(['realm_id' => $realmId]);
$connections = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($connections) > 1) {
    // A developer sandbox company may be connected to more than one CoreFlux
    // tenant. In that case, select only the tenant from the most recently
    // completed OAuth handoff, and only while that handoff is still fresh.
    $recentState = getDB()->prepare(
        'SELECT tenant_id
           FROM qbo_oauth_state
          WHERE consumed_at IS NOT NULL
            AND created_at >= CURRENT_TIMESTAMP - INTERVAL 2 HOUR
          ORDER BY consumed_at DESC, id DESC
          LIMIT 1'
    );
    $recentState->execute();
    $recentTenantId = (int) ($recentState->fetchColumn() ?: 0);
    $connections = array_values(array_filter(
        $connections,
        static fn(array $row): bool => (int) ($row['tenant_id'] ?? 0) === $recentTenantId
    ));
}
if (count($connections) !== 1) {
    fwrite(STDERR, "Refusing to charge: could not uniquely match this realm to the fresh OAuth tenant.\n");
    exit(64);
}

$connection = $connections[0];
$tenantId = (int) $connection['tenant_id'];
if (strtolower((string) ($connection['environment'] ?? '')) !== 'sandbox') {
    fwrite(STDERR, "Refusing to charge: the selected connection is not a sandbox connection.\n");
    exit(64);
}
if (!qboPaymentsConfigured($tenantId)) {
    fwrite(STDERR, "Refusing to charge: the selected connection lacks the Payments scope.\n");
    exit(64);
}

// Intuit's sandbox token endpoint receives the synthetic card directly.
// CoreFlux never persists or logs the token or the synthetic PAN.
$tokenRequestId = 'cf-sandbox-token-' . bin2hex(random_bytes(8));
$tokenResponse = qboRawRequest(
    'POST',
    QBO_PAYMENTS_API_SANDBOX . '/quickbooks/v4/payments/tokens',
    json_encode([
        'card' => [
            'number'   => '4111111111111111',
            'expMonth' => '12',
            'expYear'  => '2030',
            'cvc'      => '123',
            'name'     => 'CoreFlux Sandbox Smoke',
            'address'  => ['postalCode' => '94043'],
        ],
    ], JSON_THROW_ON_ERROR),
    [
        'Accept: application/json',
        'Content-Type: application/json',
        'Request-Id: ' . $tokenRequestId,
    ]
);
$tokenBody = is_array($tokenResponse['body'] ?? null) ? $tokenResponse['body'] : [];
$token = trim((string) ($tokenBody['value'] ?? ''));
if ((int) ($tokenResponse['status'] ?? 0) >= 400 || $token === '') {
    $firstError = is_array($tokenBody['errors'][0] ?? null) ? $tokenBody['errors'][0] : [];
    $message = substr((string) ($firstError['message'] ?? 'Intuit tokenization failed'), 0, 240);
    fwrite(STDERR, "Sandbox tokenization failed: {$message}\n");
    exit(1);
}
unset($tokenBody, $tokenResponse);

$idempotencyKey = 'cf-sandbox-smoke-' . bin2hex(random_bytes(10));
$charge = qboCreateCharge($tenantId, [
    'amount'          => 1.00,
    'currency'        => 'USD',
    'token'           => $token,
    'capture'         => true,
    'description'     => 'CoreFlux QuickBooks sandbox integration smoke test',
    'context'         => ['source' => 'coreflux_sandbox_smoke'],
    'idempotency_key' => $idempotencyKey,
]);
unset($token);

$chargeId = trim((string) ($charge['id'] ?? ''));
if ($chargeId === '') {
    fwrite(STDERR, "Sandbox charge response did not include a charge ID.\n");
    exit(1);
}

$shadowId = qboRecordChargeShadow($tenantId, $charge, [
    'charge_type'  => 'card',
    'context_token'=> $idempotencyKey,
]);
$retrieved = qboGetCharge($tenantId, $chargeId);
qboRecordChargeShadow($tenantId, $retrieved, [
    'charge_type'  => 'card',
    'context_token'=> $idempotencyKey,
]);

$shadow = getDB()->prepare(
    'SELECT status, amount_cents, coreflux_invoice_id, coreflux_payment_id
       FROM qbo_payment_charges
      WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
);
$shadow->execute(['id' => $shadowId, 'tenant_id' => $tenantId]);
$shadowRow = $shadow->fetch(PDO::FETCH_ASSOC) ?: [];
$status = strtoupper((string) ($retrieved['status'] ?? $charge['status'] ?? ''));
if (!in_array($status, ['CAPTURED', 'SETTLED'], true)) {
    fwrite(STDERR, "Sandbox charge was created but did not capture; status={$status}.\n");
    exit(1);
}
if ((int) ($shadowRow['amount_cents'] ?? 0) !== 100
    || $shadowRow['coreflux_invoice_id'] !== null
    || $shadowRow['coreflux_payment_id'] !== null) {
    fwrite(STDERR, "Sandbox shadow verification failed.\n");
    exit(1);
}

echo json_encode([
    'ok'                   => true,
    'environment'          => 'sandbox',
    'tenant_id'            => $tenantId,
    'realm_id'             => $realmId,
    'company_name'         => (string) ($connection['company_name'] ?? ''),
    'charge_id'            => $chargeId,
    'status'               => $status,
    'amount'               => '1.00',
    'currency'             => strtoupper((string) ($retrieved['currency'] ?? $charge['currency'] ?? 'USD')),
    'shadow_id'            => $shadowId,
    'coreflux_invoice_id'  => null,
    'coreflux_payment_id'  => null,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
