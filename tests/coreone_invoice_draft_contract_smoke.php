<?php
/** Pure input and boundary checks for CoreOne's shared Billing draft contract. */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/accounting/coreone_invoices_v1.php';
$route = (string) file_get_contents($root . '/api/coreone/v1/invoices.php');
$service = (string) file_get_contents($root . '/core/accounting/coreone_invoices_v1.php');
$documents = (string) file_get_contents($root . '/core/accounting/coreone_documents_v1.php');
$credential = (string) file_get_contents($root . '/core/accounting/coreone_v1.php');
$migration = (string) file_get_contents($root . '/core/migrations/154_coreone_document_requests.sql');
$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    echo ($ok ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
};
$rejects = static function (callable $call): bool {
    try { $call(); return false; }
    catch (InvalidArgumentException $e) { return true; }
};
$identity = ['base_currency' => 'USD', 'entity_id' => 1, 'tenant_id' => 999];
$body = [
    'schema_version' => 1, 'source_record_id' => 'test:invoice:1',
    'client_name' => 'Example Client', 'issue_date' => '2026-10-03',
    'due_date' => '2026-11-02', 'currency' => 'USD', 'tax_rate_pct' => '0',
    'lines' => [['description' => 'Advisory', 'quantity' => '2', 'unit' => 'hour',
        'unit_price' => '12.50', 'taxable' => false]],
];
$normalized = coreoneV1NormalizeInvoiceDraft($identity, $body);
$check('canonical quantity and price preserve four-place precision',
    $normalized['lines'][0]['quantity'] === '2.0000'
    && $normalized['lines'][0]['unit_price'] === '12.5000');
$check('old service credentials do not gain invoice drafting by default',
    !in_array('invoices:draft', COREONE_V1_DEFAULT_SCOPES, true)
    && coreoneV1HasScope(['scopes' => COREONE_V1_DEFAULT_SCOPES], 'journals:write')
    && !coreoneV1HasScope(['scopes' => COREONE_V1_DEFAULT_SCOPES], 'invoices:draft'));
$check('machine route requires bearer and explicit invoice scope',
    str_contains($route, 'coreoneV1Authenticate(')
    && str_contains($route, "coreoneV1HasScope(\$credential, 'invoices:draft')")
    && !str_contains($route, 'api_require_auth('));
$check('entity is supplied by credential and draft uses shared Billing service',
    str_contains($service, "'entity_id' => \$entityId")
    && str_contains($service, 'billingCreateDirectInvoiceDraft(')
    && !str_contains($route, 'billingNextInvoiceNumber('));
$check('source ID has tenant-level unique key and atomically maps to invoice',
    str_contains($migration, 'UNIQUE KEY uq_coreone_source (tenant_id, source_type, source_record_id)')
    && str_contains($service, 'coreoneV1SubmitDocument(')
    && str_contains($documents, 'SELECT entity_id, intent_hash FROM coreone_document_requests')
    && str_contains($documents, 'hash_equals('));
$cross = $body; $cross['entity_id'] = 2;
$check('caller cannot override entity', $rejects(static fn() => coreoneV1NormalizeInvoiceDraft($identity, $cross)));
$cross = $body; $cross['currency'] = 'EUR';
$check('currency must match entity', $rejects(static fn() => coreoneV1NormalizeInvoiceDraft($identity, $cross)));
$cross = $body; $cross['due_date'] = '2026-10-02';
$check('due date cannot precede issue date', $rejects(static fn() => coreoneV1NormalizeInvoiceDraft($identity, $cross)));
$cross = $body; $cross['lines'][0]['taxable'] = 'false';
$check('taxable must be a boolean', $rejects(static fn() => coreoneV1NormalizeInvoiceDraft($identity, $cross)));
$cross = $body; $cross['lines'][0]['quantity'] = '0';
$check('zero quantity is rejected', $rejects(static fn() => coreoneV1NormalizeInvoiceDraft($identity, $cross)));
$check('fractional-cent parser is bounded to four places',
    coreoneV1DocumentDecimal4('0.0001', 'quantity', true) === '0.0001'
    && $rejects(static fn() => coreoneV1DocumentDecimal4('0.00001', 'quantity'))
    && $rejects(static fn() => coreoneV1DocumentDecimal4('-1', 'quantity')));
$check('route cannot approve, send or post invoices',
    !str_contains($route, 'billing.invoice.sent')
    && !str_contains($route, 'accountingPostJe(')
    && str_contains($route, "if (\$_GET) api_error('Unsupported query field', 422)")
    && !str_contains($route, 'action=approve'));

$failed = count(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo $failed ? "Failed: {$failed}\n" : 'Passed: ' . count($checks) . "\n";
exit($failed ? 1 : 0);
