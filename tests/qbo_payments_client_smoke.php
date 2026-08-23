<?php
/**
 * Smoke — QBO Payments API client (Step 6 Phase 1).
 *
 * Locks:
 *   - Migration 116 declares qbo_payment_charges with the required
 *     fields, unique key (tenant_id, qbo_charge_id), and lifecycle
 *     status column.
 *   - core/qbo/payments_client.php exports the public surface and
 *     gates on the payment scope.
 *   - Operator endpoint /api/admin/qbo/payments_charge.php enforces
 *     auth, validates invoice scope, captures audit trail, and refuses
 *     calls when the tenant lacks the payment scope.
 *   - Live exercise: stub the transport via $GLOBALS['__qbo_transport'],
 *     drive qboCreateCharge end-to-end, verify the shadow upsert is
 *     idempotent and the error envelope is parsed correctly.
 *
 * Run: php -d zend.assertions=1 /app/tests/qbo_payments_client_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nQBO Payments client smoke (Step 6 Phase 1)\n";
echo "============================================\n\n";

// ─────── 1. Migration ───────
echo "── migration 116 ──\n";
$migPath = $root . '/core/migrations/116_qbo_payments_api.sql';
check('migration file exists', file_exists($migPath));
$mig = (string) file_get_contents($migPath);
check('migration declares qbo_payment_charges',
    str_contains($mig, 'CREATE TABLE IF NOT EXISTS qbo_payment_charges'));
check('shadow has qbo_charge_id + unique (tenant_id, qbo_charge_id)',
    str_contains($mig, 'qbo_charge_id') && str_contains($mig, 'uniq_tenant_qbo_charge (tenant_id, qbo_charge_id)'));
check('shadow has charge_type ENUM card | echeck',
    str_contains($mig, "ENUM('card','echeck')"));
check('shadow has status column (lifecycle)',  str_contains($mig, 'status'));
check('shadow tracks card_last4 + bank fields',
    str_contains($mig, 'card_last4') && str_contains($mig, 'account_last4'));
check('shadow links to coreflux_invoice_id + coreflux_payment_id',
    str_contains($mig, 'coreflux_invoice_id') && str_contains($mig, 'coreflux_payment_id'));
check('shadow surfaces error_code + error_message',
    str_contains($mig, 'error_code') && str_contains($mig, 'error_message'));
check('shadow stores raw_payload',              str_contains($mig, 'raw_payload'));
$idemMigPath = $root . '/core/migrations/129_qbo_payments_idempotency.sql';
$idemMig = (string) @file_get_contents($idemMigPath);
check('durable idempotency migration exists', $idemMig !== '');
check('durable idempotency is tenant-scoped and unique',
    str_contains($idemMig, 'UNIQUE KEY uq_qbo_payment_context (tenant_id, context_token)'));

// ─────── 2. Client module shape ───────
echo "\n── core/qbo/payments_client.php ──\n";
$srcPath = $root . '/core/qbo/payments_client.php';
check('module exists', file_exists($srcPath));
$src = (string) file_get_contents($srcPath);
foreach ([
    'qboPaymentsConfigured', 'qboPaymentsBaseUrl', 'qboPaymentsCall',
    'qboCreateCharge', 'qboGetCharge',
    'qboCreateECheck', 'qboGetECheck',
    'qboFetchPaymentTransaction', 'qboRecordChargeShadow',
    'qboApplyCapturedPayment',
] as $fn) {
    check("exports {$fn}()", str_contains($src, "function {$fn}("));
}
check("declares QBO_PAYMENTS_SCOPE = 'com.intuit.quickbooks.payment'",
    str_contains($src, "QBO_PAYMENTS_SCOPE = 'com.intuit.quickbooks.payment'"));
check('declares QBO_PAYMENTS_API_SANDBOX',     str_contains($src, "QBO_PAYMENTS_API_SANDBOX"));
check('declares QBO_PAYMENTS_API_PRODUCTION',  str_contains($src, "QBO_PAYMENTS_API_PRODUCTION"));
check('uses /quickbooks/v4/payments/charges path',
    str_contains($src, '/quickbooks/v4/payments/charges'));
check('uses /quickbooks/v4/payments/echecks path',
    str_contains($src, '/quickbooks/v4/payments/echecks'));
check('sends Request-Id idempotency header',
    str_contains($src, "'Request-Id: ' . \$idem"));
check('parses Payments error envelope (errors[0].code)',
    str_contains($src, "\$resp['body']['errors'][0]"));
check('emits qboAudit on outbound charge creation',
    str_contains($src, "qboAudit(\$tenantId, 'payments_charge_create'"));
check('throws QboApiException on 4xx/5xx',
    str_contains($src, '$ex = new QboApiException'));
check('preserves Intuit troubleshooting correlation IDs',
    str_contains($src, "\$resp['headers']['intuit_tid']")
    && str_contains($src, "\$ex->raw['intuit_tid']")
    && str_contains($src, "'intuit_tid' => \$intuitTid"));
check('shadow upsert is idempotent (selects then UPDATE/INSERT)',
    str_contains($src, 'SELECT id FROM qbo_payment_charges')
    && str_contains($src, 'INSERT INTO qbo_payment_charges'));
check('shadow upsert resolves concurrent insert races',
    str_contains($src, '$raced = $sel->fetch') && str_contains($src, 'return $updateExisting'));

// ─────── 3. Operator endpoint ───────
echo "\n── /api/admin/qbo/payments_charge.php ──\n";
$epPath = $root . '/api/admin/qbo/payments_charge.php';
check('endpoint exists', file_exists($epPath));
$ep = (string) file_get_contents($epPath);
check('endpoint calls api_require_auth',         str_contains($ep, 'api_require_auth()'));
check('endpoint RBAC-gates to admin/wildcard',
    str_contains($ep, "rbac_legacy_require_any") && str_contains($ep, "'master_admin'"));
check('refuses call when payment scope absent',  str_contains($ep, 'qboPaymentsConfigured('));
check('validates invoice exists + open',         str_contains($ep, 'billing_invoices'));
check('rejects amount > invoice.amount_due',     str_contains($ep, 'amount exceeds invoice amount_due'));
check('idempotency: accepts and reuses caller Request-Id',
    str_contains($ep, "\$body['idempotency_key']")
    && str_contains($ep, 'context_token = :k')
    && str_contains($ep, "'idempotency_key' => \$contextToken"));
check('idempotent replay bypasses changed invoice balance/status',
    strpos($ep, 'if (!$prior)') !== false
    && strpos($ep, 'if (!$prior)') < strpos($ep, 'if ($amount - 0.005 >'));
check('new intents accept only allocatable invoice statuses',
    str_contains($ep, "['approved', 'sent', 'partially_paid']"));
check('on CAPTURED: delegates payment creation and allocation',
    str_contains($ep, 'qboApplyCapturedPayment('));
check('shared capture helper inserts billing_payments with source=qbo',
    str_contains($src, "INSERT INTO billing_payments") && str_contains($src, "'qbo'"));
check('shared capture helper calls billingAllocatePayment',
    str_contains($src, 'billingAllocatePayment('));
check('shared capture helper audits billing.qbo_payments.captured',
    str_contains($src, "billingAudit('billing.qbo_payments.captured'"));
check('emits audit billing.qbo_payments.charge_failed on error',
    str_contains($ep, "billingAudit('billing.qbo_payments.charge_failed'"));
check('returns a masked in-app payment receipt with the Intuit Payments disclosure',
    str_contains($ep, 'qboBuildPaymentReceipt(')
    && str_contains($ep, "'processor'       => 'Intuit Payments Inc.'")
    && str_contains($ep, "'processor_nmls'  => '1098819'"));

$qboApi = (string) file_get_contents($root . '/api/qbo.php');
check('status reports granted/configured OAuth scopes and Payments readiness',
    str_contains($qboApi, "'granted_scopes'")
    && str_contains($qboApi, "'configured_scopes'")
    && str_contains($qboApi, "'payments_enabled'")
    && str_contains($qboApi, "'payments_scope_requested'"));
$qboSettings = (string) file_get_contents($root . '/dashboard/src/pages/QboSettings.jsx');
check('settings surfaces Payments grant and re-consent action',
    str_contains($qboSettings, 'qbo-payments-scope-status')
    && str_contains($qboSettings, 'qbo-reconsent-btn'));
check('settings provides in-app QuickBooks support contact',
    str_contains($qboSettings, 'qbo-support-link')
    && str_contains($qboSettings, 'support@corefluxapp.com'));
$paymentsModal = (string) file_get_contents($root . '/modules/billing/ui/QboPaymentsCollectModal.jsx');
check('payment UI renders receipt amount, date, masked method, ID, and processor disclosure',
    str_contains($paymentsModal, 'QuickBooks payment receipt')
    && str_contains($paymentsModal, 'Payment amount:')
    && str_contains($paymentsModal, 'Date of transaction:')
    && str_contains($paymentsModal, 'Payment method:')
    && str_contains($paymentsModal, 'Transaction ID:')
    && str_contains($paymentsModal, 'NMLS #1098819'));
$invoicesUi = (string) file_get_contents($root . '/modules/billing/ui/InvoicesList.jsx');
check('invoice collection CTA is gated by live Payments readiness',
    str_contains($invoicesUi, "/api/qbo/status.php?action=status")
    && str_contains($invoicesUi, 'qboStatus.data?.payments_enabled === true'));
check('invoice collection CTA mirrors the backend admin gate',
    str_contains($invoicesUi, "['master_admin', 'tenant_admin'].includes(user.global_role)")
    && str_contains($invoicesUi, '{ enabled: canCollectViaQbo }'));
$billingModule = (string) file_get_contents($root . '/modules/billing/ui/BillingModule.jsx');
check('billing module passes session into the invoice list',
    str_contains($billingModule, '<InvoicesList session={session} />'));

// ─────── 4. Live exercise (transport stub + SQLite mirror) ───────
echo "\n── live behaviour ──\n";

$GLOBALS['pdo'] = new \PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo = $GLOBALS['pdo'];

if (!function_exists('getDB')) {
    function getDB(): \PDO { return $GLOBALS['pdo']; }
}

$pdo->exec("CREATE TABLE qbo_connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INT UNIQUE, realm_id TEXT, company_name TEXT, environment TEXT,
    access_token_ct BLOB, refresh_token_ct BLOB,
    access_token_exp TEXT, refresh_token_exp TEXT,
    scope TEXT, status TEXT DEFAULT 'active', sync_config TEXT,
    auto_reconcile_paid_out_of_band INT DEFAULT 0,
    last_probe_at TEXT, last_probe_error TEXT,
    connected_by_user_id INT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE qbo_payment_charges (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, qbo_charge_id TEXT,
    charge_type TEXT DEFAULT 'card',
    amount_cents INT DEFAULT 0, currency TEXT DEFAULT 'USD', status TEXT DEFAULT 'ISSUED',
    card_brand TEXT, card_last4 TEXT, card_exp_month INT, card_exp_year INT,
    bank_name TEXT, account_last4 TEXT, routing_last4 TEXT,
    coreflux_invoice_id INT, coreflux_payment_id INT, context_token TEXT,
    error_code TEXT, error_message TEXT,
    raw_payload TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    captured_at TEXT, settled_at TEXT, updated_at TEXT,
    UNIQUE (tenant_id, qbo_charge_id))");
$pdo->exec("CREATE TABLE qbo_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, action TEXT,
    detail_json TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE billing_invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, invoice_number TEXT,
    client_name TEXT, currency TEXT, status TEXT, amount_due NUMERIC,
    amount_paid NUMERIC DEFAULT 0)");
$pdo->exec("CREATE TABLE billing_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, client_name TEXT,
    received_at TEXT, method TEXT, reference TEXT, external_id TEXT,
    source_system TEXT, amount NUMERIC, currency TEXT,
    unallocated_amount NUMERIC, notes TEXT, created_by_user_id INT,
    created_at TEXT,
    UNIQUE (tenant_id, source_system, external_id))");
$pdo->exec("CREATE TABLE billing_payment_allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT, payment_id INT, invoice_id INT,
    amount_applied NUMERIC, created_at TEXT,
    UNIQUE (payment_id, invoice_id))");

// Pre-load a tenant with the payment scope granted.
$pdo->prepare("INSERT INTO qbo_connections (tenant_id, realm_id, status, environment, access_token_ct, refresh_token_ct, scope) VALUES (101, 'R-1', 'active', 'sandbox', x'00', x'00', 'com.intuit.quickbooks.accounting com.intuit.quickbooks.payment')")->execute();
$pdo->prepare("INSERT INTO qbo_connections (tenant_id, realm_id, status, environment, access_token_ct, refresh_token_ct, scope) VALUES (102, 'R-2', 'active', 'sandbox', x'00', x'00', 'com.intuit.quickbooks.accounting')")->execute();

// Stub upstream functions called by payments_client.php.
if (!function_exists('qboAudit')) {
    function qboAudit(int $tid, string $a, array $opts = []): void {
        $GLOBALS['pdo']->prepare(
            "INSERT INTO qbo_audit_log (tenant_id, action, detail_json, created_at) VALUES (:t,:a,:d,:c)"
        )->execute(['t'=>$tid,'a'=>$a,'d'=>json_encode($opts),'c'=>date('Y-m-d H:i:s')]);
    }
}
if (!function_exists('qboAccessToken')) {
    function qboAccessToken(int $tid): string { return 'fake-access-token-' . $tid; }
}
if (!function_exists('qboRefreshAccessToken')) {
    function qboRefreshAccessToken(int $tid): string { return 'fake-refresh-' . $tid; }
}
if (!function_exists('qboEnvironment')) { function qboEnvironment(): string { return 'sandbox'; } }
if (!function_exists('qboConnection')) {
    function qboConnection(int $tid): ?array {
        $s = $GLOBALS['pdo']->prepare("SELECT * FROM qbo_connections WHERE tenant_id=:t LIMIT 1");
        $s->execute(['t'=>$tid]);
        return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
if (!class_exists('QboApiException')) {
    class QboApiException extends \RuntimeException {
        public ?int $httpStatus = null;
        public ?string $errorCode = null;
        public ?array $raw = null;
    }
}
if (!function_exists('billingAllocatePayment')) {
    function billingAllocatePayment(int $paymentId, array $request, ?int $actorUserId = null): array {
        $pdo = $GLOBALS['pdo'];
        $applied = [];
        foreach (($request['allocations'] ?? []) as $allocation) {
            $invoiceId = (int) $allocation['invoice_id'];
            $amount = round((float) $allocation['amount'], 2);
            $pdo->prepare(
                'INSERT INTO billing_payment_allocations
                    (payment_id, invoice_id, amount_applied, created_at)
                 VALUES (:p, :i, :a, CURRENT_TIMESTAMP)'
            )->execute(['p' => $paymentId, 'i' => $invoiceId, 'a' => $amount]);
            $pdo->prepare(
                'UPDATE billing_payments
                    SET unallocated_amount = unallocated_amount - :a
                  WHERE id = :p'
            )->execute(['a' => $amount, 'p' => $paymentId]);
            $pdo->prepare(
                "UPDATE billing_invoices
                    SET amount_paid = amount_paid + :a,
                        amount_due = amount_due - :a,
                        status = CASE WHEN amount_due - :a <= 0 THEN 'paid' ELSE 'partial' END
                  WHERE id = :i"
            )->execute(['a' => $amount, 'i' => $invoiceId]);
            $applied[] = ['invoice_id' => $invoiceId, 'amount' => $amount];
        }
        return ['applied' => $applied];
    }
}
if (!function_exists('billingAudit')) {
    function billingAudit(string $event, array $meta = [], ?int $targetId = null): void {
        $GLOBALS['__billing_audit'][] = compact('event', 'meta', 'targetId');
    }
}

// qboRawRequest will route through $GLOBALS['__qbo_transport'] so we
// can capture/replay the wire — but the real function is defined in
// client.php. Load it via a tiny shim:
if (!function_exists('qboRawRequest')) {
    function qboRawRequest(string $method, string $url, ?string $rawBody, array $headers): array {
        if (isset($GLOBALS['__qbo_transport']) && is_callable($GLOBALS['__qbo_transport'])) {
            return ($GLOBALS['__qbo_transport'])($method, $url, $headers, $rawBody);
        }
        throw new \RuntimeException('qboRawRequest stub: no transport registered');
    }
}

// Pull in the client by stripping the require_once so we don't load
// the real Accounting client (which would re-declare functions we've
// already stubbed).
$clientSrc = file_get_contents($root . '/core/qbo/payments_client.php');
$clientSrc = preg_replace(
    "/require_once __DIR__ \\. '\\/client\\.php';/", '', $clientSrc
);
$clientSrc = preg_replace('/^\s*<\?php/', '', $clientSrc);
eval($clientSrc);

// ─────── (a) Scope gate ───────
echo "── scope gating ──\n";
check('tenant 101 (scope granted) → qboPaymentsConfigured = true',
    qboPaymentsConfigured(101) === true);
check('tenant 102 (scope absent)  → qboPaymentsConfigured = false',
    qboPaymentsConfigured(102) === false);
check('payments base URL is sandbox', qboPaymentsBaseUrl() === 'https://sandbox.api.intuit.com');

// ─────── (b) qboCreateCharge end-to-end ───────
echo "\n── qboCreateCharge happy path ──\n";

$captured = [];
$GLOBALS['__qbo_transport'] = function ($method, $url, $headers, $rawBody) use (&$captured) {
    $captured[] = compact('method', 'url', 'headers', 'rawBody');
    return [
        'status' => 200,
        'body'   => [
            'id'        => 'CHG-AAA',
            'amount'    => '125.50',
            'currency'  => 'USD',
            'status'    => 'CAPTURED',
            'capture'   => true,
            'card'      => ['type' => 'Visa', 'number' => 'xxxxxxxxxxxx4242', 'expMonth' => 12, 'expYear' => 2030],
        ],
        'headers'=> [],
    ];
};

$resp = qboCreateCharge(101, [
    'amount'   => 125.50,
    'currency' => 'USD',
    'token'    => 'tok_test_001',
    'capture'  => true,
    'description' => 'Invoice INV-001',
    'idempotency_key' => 'req-deadbeef',
]);
check('charge returned id=CHG-AAA',                $resp['id'] === 'CHG-AAA');
check('charge returned status=CAPTURED',           $resp['status'] === 'CAPTURED');

// Inspect the wire.
$wire = $captured[0];
check('outbound method=POST',                       $wire['method'] === 'POST');
check('outbound hits /quickbooks/v4/payments/charges',
    str_contains($wire['url'], '/quickbooks/v4/payments/charges'));
check('outbound base URL is the payments sandbox',
    str_starts_with($wire['url'], 'https://sandbox.api.intuit.com'));
check('outbound carries Authorization: Bearer header',
    in_array('Authorization: Bearer fake-access-token-101', $wire['headers'], true));
check('outbound carries Request-Id: req-deadbeef',
    in_array('Request-Id: req-deadbeef', $wire['headers'], true));
$payload = json_decode((string) $wire['rawBody'], true);
check('payload amount serialised as "125.50"',     $payload['amount'] === '125.50');
check('payload token is round-tripped',             $payload['token']  === 'tok_test_001');
check('payload capture=true',                       $payload['capture'] === true);

// Intuit's e-check debit resource requires its WEB payment mode and a
// check number in addition to the opaque bank-account token.
$echeckCreateWire = [];
$GLOBALS['__qbo_transport'] = function ($method, $url, $headers, $rawBody) use (&$echeckCreateWire) {
    $echeckCreateWire[] = compact('method', 'url', 'headers', 'rawBody');
    return [
        'status' => 200,
        'body' => ['id' => 'EC-CREATE-001', 'amount' => '10.00', 'status' => 'PENDING'],
        'headers' => [],
    ];
};
$createdEcheck = qboCreateECheck(101, [
    'amount' => 10,
    'token' => 'bank_token_001',
    'description' => 'Invoice INV-ACH',
    'idempotency_key' => 'req-ach-deadbeef',
]);
$echeckPayload = json_decode((string) ($echeckCreateWire[0]['rawBody'] ?? ''), true);
check('e-check create returns upstream id', $createdEcheck['id'] === 'EC-CREATE-001');
check('e-check create uses WEB payment mode', ($echeckPayload['paymentMode'] ?? '') === 'WEB');
check('e-check create supplies an eight-digit check number',
    preg_match('/^\d{8}$/', (string) ($echeckPayload['checkNumber'] ?? '')) === 1);
check('e-check create sends the opaque bank token',
    ($echeckPayload['token'] ?? '') === 'bank_token_001');
check('e-check request omits unsupported currency field',
    !array_key_exists('currency', $echeckPayload));

// The polling/refresh router must use the e-check resource for ACH
// transactions instead of always calling the card endpoint.
$echeckWire = [];
$GLOBALS['__qbo_transport'] = function ($method, $url, $headers, $rawBody) use (&$echeckWire) {
    $echeckWire[] = compact('method', 'url');
    return [
        'status' => 200,
        'body' => ['id' => 'EC-001', 'amount' => '10.00', 'currency' => 'USD', 'status' => 'PENDING'],
        'headers' => [],
    ];
};
$echeck = qboFetchPaymentTransaction(101, 'EC-001', 'echeck');
check('transaction router returns e-check response', $echeck['id'] === 'EC-001');
check('transaction router uses /payments/echecks for ACH',
    str_contains((string) ($echeckWire[0]['url'] ?? ''), '/quickbooks/v4/payments/echecks/EC-001'));

// ─────── (c) Shadow row write ───────
echo "\n── shadow row idempotent upsert ──\n";
$shadowId = qboRecordChargeShadow(101, $resp, [
    'charge_type'         => 'card',
    'coreflux_invoice_id' => 7,
    'context_token'       => 'req-deadbeef',
]);
check('shadow_id > 0 (insert)', $shadowId > 0);

$shadow = $pdo->query("SELECT * FROM qbo_payment_charges WHERE qbo_charge_id='CHG-AAA'")->fetch(\PDO::FETCH_ASSOC);
check('shadow row has amount_cents=12550',  (int) $shadow['amount_cents'] === 12550);
check('shadow row has status=CAPTURED',     $shadow['status'] === 'CAPTURED');
check('shadow row captured card brand',     $shadow['card_brand'] === 'Visa');
check('shadow row captured last4 from masked PAN', $shadow['card_last4'] === '4242');
check('shadow row links coreflux_invoice_id=7',   (int) $shadow['coreflux_invoice_id'] === 7);
check('shadow row stamps context_token',    $shadow['context_token'] === 'req-deadbeef');
check('captured_at is populated',            !empty($shadow['captured_at']));

// Re-upsert with a fresh payload (e.g. settlement webhook fires).
$settled = $resp;
$settled['status'] = 'SETTLED';
$shadowId2 = qboRecordChargeShadow(101, $settled, [
    'charge_type' => 'card', 'coreflux_invoice_id' => 7, 'context_token' => 'req-deadbeef',
]);
check('shadow_id stable on second upsert',  $shadowId2 === $shadowId);
$shadow2 = $pdo->query("SELECT * FROM qbo_payment_charges WHERE qbo_charge_id='CHG-AAA'")->fetch(\PDO::FETCH_ASSOC);
check('status advanced to SETTLED',          $shadow2['status'] === 'SETTLED');
check('settled_at is populated',             !empty($shadow2['settled_at']));
check('captured_at preserved across upsert', !empty($shadow2['captured_at']));
$rowCount = (int) $pdo->query("SELECT COUNT(*) FROM qbo_payment_charges WHERE qbo_charge_id='CHG-AAA'")->fetchColumn();
check('unique key prevents duplicate rows',  $rowCount === 1);

// A charge that is learned as captured by either the initial request,
// operator refresh, or the poller must create and allocate exactly one
// CoreFlux payment.
echo "\n── captured payment application ──\n";
$pdo->prepare(
    "INSERT INTO billing_invoices
        (id, tenant_id, invoice_number, client_name, currency, status, amount_due, amount_paid)
     VALUES (7, 101, 'INV-001', 'Acme Co', 'USD', 'sent', 125.50, 0)"
)->execute();
$application = qboApplyCapturedPayment(101, $settled, [
    'coreflux_invoice_id' => 7,
    'charge_type' => 'card',
    'context_token' => 'req-deadbeef',
], 9);
check('captured charge creates and applies a CoreFlux payment', $application['applied'] === true);
check('first application is not marked reused', $application['reused'] === false);
$paymentId = (int) ($application['payment_id'] ?? 0);
check('payment row is linked to QBO external id',
    (string) $pdo->query("SELECT external_id FROM billing_payments WHERE id={$paymentId}")->fetchColumn() === 'CHG-AAA');
check('payment row records card method',
    (string) $pdo->query("SELECT method FROM billing_payments WHERE id={$paymentId}")->fetchColumn() === 'card');
check('shadow links the resulting CoreFlux payment',
    (int) $pdo->query("SELECT coreflux_payment_id FROM qbo_payment_charges WHERE qbo_charge_id='CHG-AAA'")->fetchColumn() === $paymentId);
check('invoice is paid by the shared allocator',
    (string) $pdo->query("SELECT status FROM billing_invoices WHERE id=7")->fetchColumn() === 'paid');
check('exactly one allocation was created',
    (int) $pdo->query("SELECT COUNT(*) FROM billing_payment_allocations WHERE payment_id={$paymentId}")->fetchColumn() === 1);

$replay = qboApplyCapturedPayment(101, $settled, [
    'coreflux_invoice_id' => 7,
    'charge_type' => 'card',
    'context_token' => 'req-deadbeef',
], 9);
check('captured replay resolves to the same payment', (int) $replay['payment_id'] === $paymentId);
check('captured replay is marked reused', $replay['reused'] === true);
check('captured replay does not duplicate payment rows',
    (int) $pdo->query("SELECT COUNT(*) FROM billing_payments WHERE external_id='CHG-AAA'")->fetchColumn() === 1);
check('captured replay does not duplicate allocations',
    (int) $pdo->query("SELECT COUNT(*) FROM billing_payment_allocations WHERE payment_id={$paymentId}")->fetchColumn() === 1);
check('capture application emitted billing audit event',
    ($GLOBALS['__billing_audit'][0]['event'] ?? null) === 'billing.qbo_payments.captured');

// ─────── (d) Error envelope ───────
echo "\n── error envelope parsing ──\n";
$GLOBALS['__qbo_transport'] = function ($method, $url, $headers, $rawBody) {
    return [
        'status' => 402,
        'body'   => [
            'errors' => [
                ['code' => 'PMT-4000', 'message' => 'Card declined: insufficient funds'],
            ],
        ],
        'headers'=> [],
    ];
};
$threw = null;
try {
    qboCreateCharge(101, ['amount' => 5.00, 'token' => 'tok_bad', 'capture' => true]);
} catch (\Throwable $e) {
    $threw = $e;
}
check('declined charge throws QboApiException',  $threw instanceof QboApiException);
check('exception carries httpStatus=402',        ($threw->httpStatus ?? null) === 402);
check('exception carries errorCode=PMT-4000',    ($threw->errorCode  ?? null) === 'PMT-4000');
check('exception carries raw body snippet',
    isset($threw->raw['body']) && str_contains((string) $threw->raw['body'], 'PMT-4000'));

// ─────── (e) Scope refusal ───────
echo "\n── scope refusal ──\n";
$refused = null;
try {
    qboCreateCharge(102, ['amount' => 10.00, 'token' => 'tok_x', 'capture' => true]);
} catch (\Throwable $e) {
    $refused = $e;
}
check('tenant without payment scope is refused',  $refused !== null);
check('refusal message mentions re-connect',      str_contains((string) ($refused?->getMessage() ?? ''), 're-connect'));

// ─────── (f) Audit trail ───────
echo "\n── audit trail ──\n";
$created = (int) $pdo->query("SELECT COUNT(*) FROM qbo_audit_log WHERE action='payments_charge_create'")->fetchColumn();
check('outbound charge create audited (count >= 1)', $created >= 1);
$failed = (int) $pdo->query("SELECT COUNT(*) FROM qbo_audit_log WHERE action='payments_http_error'")->fetchColumn();
check('outbound charge failure audited (count >= 1)', $failed >= 1);

// ─────── Summary ───────
$total = $passes + count($failures);
echo "\n=========================================\n";
echo "qbo_payments_client smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
