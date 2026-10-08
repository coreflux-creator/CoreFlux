<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/schema_contract.php';

$required = coreAccountingRequiredSchema();
$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? 'OK  ' : 'FAIL  ') . $name . "\n";
    if (!$ok) $failures++;
};

$check('complete CoreAccounting schema has no gaps', coreAccountingMissingSchema($required) === []);
$withoutBill = $required;
unset($withoutBill['ap_bills']);
$check('missing AP table is reported', in_array('ap_bills',
    coreAccountingMissingSchema($withoutBill), true));
$withoutBankUpdate = $required;
$withoutBankUpdate['accounting_bank_statement_lines'] = array_values(array_diff(
    $withoutBankUpdate['accounting_bank_statement_lines'], ['updated_at']));
$check('missing bank update column is reported', in_array(
    'accounting_bank_statement_lines.updated_at',
    coreAccountingMissingSchema($withoutBankUpdate), true));
$withoutCoreOneIntent = $required;
$withoutCoreOneIntent['coreone_document_requests'] = array_values(array_diff(
    $withoutCoreOneIntent['coreone_document_requests'], ['intent_hash']));
$check('missing source idempotency column is reported', in_array(
    'coreone_document_requests.intent_hash',
    coreAccountingMissingSchema($withoutCoreOneIntent), true));
$withoutTokenRevocation = $required;
$withoutTokenRevocation['billing_invoice_tokens'] = array_values(array_diff(
    $withoutTokenRevocation['billing_invoice_tokens'], ['revoked_at']));
$check('missing invoice-link revocation column is reported', in_array(
    'billing_invoice_tokens.revoked_at',
    coreAccountingMissingSchema($withoutTokenRevocation), true));
$requiredKeys = coreAccountingRequiredUniqueKeys();
$check('required unique-key signatures have no gaps',
    coreAccountingMissingUniqueKeys($requiredKeys) === []);
$withoutPostingKey = $requiredKeys;
unset($withoutPostingKey['accounting_posting_idempotency']);
$check('missing posting idempotency uniqueness is reported', in_array(
    'accounting_posting_idempotency UNIQUE (tenant_id, idempotency_key)',
    coreAccountingMissingUniqueKeys($withoutPostingKey), true));
$wrongEventKey = $requiredKeys;
$wrongEventKey['accounting_events'] = [['tenant_id', 'source_module', 'event_type', 'source_record_id']];
$check('reordered event source key is not accepted', in_array(
    'accounting_events UNIQUE (tenant_id, source_module, source_record_id, event_type)',
    coreAccountingMissingUniqueKeys($wrongEventKey), true));
$withoutTokenKey = $requiredKeys;
unset($withoutTokenKey['billing_invoice_tokens']);
$check('missing invoice-link hash uniqueness is reported', in_array(
    'billing_invoice_tokens UNIQUE (token_hash)',
    coreAccountingMissingUniqueKeys($withoutTokenKey), true));
$provisioner = (string) file_get_contents(__DIR__ . '/../deploy/provision_coreaccounting_tenant.php');
$check('first-tenant provisioning checks the accounting schema before creating records',
    strpos($provisioner, 'coreAccountingInspectSchema($pdo)') !== false
    && strpos($provisioner, 'coreAccountingInspectSchema($pdo)')
        < strpos($provisioner, '$pdo->beginTransaction()'));

echo $failures ? "Failed: {$failures}\n" : "Passed: 10\n";
exit($failures ? 1 : 0);
