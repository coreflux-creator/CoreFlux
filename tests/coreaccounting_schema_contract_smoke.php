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

echo $failures ? "Failed: {$failures}\n" : "Passed: 4\n";
exit($failures ? 1 : 0);
