<?php
/** Pure contract and source-ownership checks; staging fixture covers persistence. */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/accounting/coreone_bills_v1.php';
$route = (string) file_get_contents($root . '/api/coreone/v1/bills.php');
$service = (string) file_get_contents($root . '/core/accounting/coreone_bills_v1.php');
$credentialService = (string) file_get_contents($root . '/core/accounting/coreone_v1.php');
$issuerBackfill = (string) file_get_contents($root . '/core/migrations/159_coreone_bill_issuer_attribution.sql');
$drafts = (string) file_get_contents($root . '/modules/ap/lib/bill_drafts.php');
$credentialRoute = (string) file_get_contents($root . '/api/coreone_credentials.php');
$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    echo ($ok ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
};
$rejects = static function (callable $action): bool {
    try { $action(); return false; }
    catch (InvalidArgumentException $e) { return true; }
};
$identity = ['tenant_id' => 999, 'entity_id' => 1, 'base_currency' => 'USD'];
$body = [
    'schema_version' => 1,
    'source_record_id' => 'coreone:bill:sample-1',
    'vendor_name' => 'Example Vendor',
    'vendor_type' => 'w9_business',
    'bill_number' => 'EV-100',
    'received_at' => '2026-09-10',
    'bill_date' => '2026-09-09',
    'due_date' => '2026-10-09',
    'currency' => 'USD',
    'tax_rate_pct' => '0',
    'lines' => [[
        'item_type' => 'other', 'description' => 'Prepared service',
        'quantity' => '2', 'unit' => 'each', 'unit_price' => '12.50',
        'is_1099_eligible' => false,
    ]],
];
$normalized = coreoneV1NormalizeBill($identity, $body);
$check('bill numbers, dates and four-place values normalize without a database',
    $normalized['bill_number'] === 'EV-100'
    && $normalized['lines'][0]['quantity'] === '2.0000'
    && $normalized['lines'][0]['unit_price'] === '12.5000');
$check('existing credentials do not gain bill preparation',
    !in_array('bills:prepare', COREONE_V1_DEFAULT_SCOPES, true)
    && !in_array('bills:request_approval', COREONE_V1_DEFAULT_SCOPES, true)
    && in_array('bills:prepare', COREONE_V1_ALLOWED_SCOPES, true)
    && in_array('bills:request_approval', COREONE_V1_ALLOWED_SCOPES, true));
$check('bill scope issuance requires the AP bill permission',
    str_contains($credentialRoute, "'bills:prepare'" )
    && str_contains($credentialRoute, "'bills:request_approval'")
    && str_contains($credentialRoute, "'ap.bill.create'"));
$check('machine route requires bearer and bill scope',
    str_contains($route, 'coreoneV1Authenticate(')
    && str_contains($route, "coreoneV1HasScope(\$credential, 'bills:prepare')"));
$check('machine bill uses the same AP creation service as the ERP route',
    str_contains($service, 'apCreateManualBill(')
    && str_contains((string) file_get_contents($root . '/modules/ap/api/bills.php'), 'apCreateManualBill('));
$check('service-key issuer is recorded for AP two-eye approval',
    str_contains($credentialService, 'c.created_by_user_id')
    && str_contains($service, "\$credential['created_by_user_id']")
    && str_contains($service, '$issuerUserId > 0 ? $issuerUserId : null'));
$check('historical issuer backfill is source and tenant scoped',
    str_contains($issuerBackfill, "d.source_type = 'ap.bill'")
    && str_contains($issuerBackfill, 'd.tenant_id = b.tenant_id')
    && str_contains($issuerBackfill, 'c.id = d.credential_id')
    && str_contains($issuerBackfill, 'u.tenant_id = d.tenant_id')
    && str_contains($issuerBackfill, 'b.created_by_user_id IS NULL'));
$check('source mapping is atomic and entity scoped',
    str_contains($service, "coreoneV1SubmitDocument(\$credential, 'ap.bill'")
    && str_contains($service, 'b.entity_id = d.entity_id')
    && str_contains($service, 'd.entity_id = :e'));
$invalid = $body; $invalid['entity_id'] = 2;
$check('caller cannot override the legal entity', $rejects(static fn() => coreoneV1NormalizeBill($identity, $invalid)));
$invalid = $body; $invalid['currency'] = 'EUR';
$check('bill currency is tied to the legal entity', $rejects(static fn() => coreoneV1NormalizeBill($identity, $invalid)));
$invalid = $body; $invalid['due_date'] = '2026-09-08';
$check('due date cannot precede bill date', $rejects(static fn() => coreoneV1NormalizeBill($identity, $invalid)));
$invalid = $body; $invalid['lines'][0]['is_1099_eligible'] = 'false';
$check('1099 eligibility must be a boolean', $rejects(static fn() => coreoneV1NormalizeBill($identity, $invalid)));
$invalid = $body; $invalid['lines'][0]['unit_price'] = '-2';
$check('negative bill price is not accepted as a regular line', $rejects(static fn() => coreoneV1NormalizeBill($identity, $invalid)));
$invalid = $body; $invalid['lines'][0]['unit_price'] = '0';
$check('zero-value bill line cannot be prepared', $rejects(static fn() => coreoneV1NormalizeBill($identity, $invalid)));
$invalid = $body; $invalid['lines'][0]['item_type'] = 'discount';
$check('unsupported discount cannot become a payable expense', $rejects(static fn() => coreoneV1NormalizeBill($identity, $invalid)));
$invalid = $body; $invalid['lines'][0]['gl_expense_account_code'] = '2000';
$check('managed AP control account cannot be selected for a bill expense',
    $rejects(static fn() => coreoneV1NormalizeBill($identity, $invalid)));
$check('bill preparation cannot approve, post or pay',
    !str_contains($route, 'apBillTransitionAllowed(')
    && !str_contains($route, 'accountingPostJe(')
    && !str_contains($route, 'apClearPayment(')
    && str_contains($drafts, "'status' => 'pending_approval'"));
$check('machine review requests the canonical AP workflow without deciding or posting',
    str_contains($route, "'request_approval'")
    && str_contains($service, 'apWorkflowSubmitBillForApproval(')
    && str_contains($service, 'apEvaluateApprovalPolicy(')
    && str_contains($service, 'apPushRoutedBillApprovers(')
    && !str_contains($route, 'apWorkflowActBillApproval(')
    && !str_contains($route, 'accountingPostJe('));
$check('nested AP creation has its own rollback boundary',
    str_contains($drafts, 'SAVEPOINT ')
    && str_contains($drafts, 'ROLLBACK TO SAVEPOINT '));

$failed = count(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo $failed ? "Failed: {$failed}\n" : 'Passed: ' . count($checks) . "\n";
exit($failed ? 1 : 0);
