<?php
/**
 * Smoke — Plaid spec freshness (lightweight, mirrors QBO/Mercury/Zoho).
 */
declare(strict_types=1);
$passes = 0; $failures = [];
function check(string $l, bool $c) { global $passes, $failures; if ($c) {$passes++; echo "  ✓ {$l}\n";} else {$failures[]=$l; echo "  ✗ {$l}\n";} }

echo "\nPlaid spec freshness\n=====================\n\n";
$schemaPath = '/app/spec/plaid_schema.json';
$tool       = '/app/tools/refresh_plaid_spec.sh';
check('spec exists',                          is_file($schemaPath));
$schema = json_decode((string) file_get_contents($schemaPath), true);
check('schema parses as JSON',                is_array($schema));
foreach (['TransferCreate', 'TransferUserInRequest', 'ItemPublicTokenExchange',
          'AccountsBalanceGet', 'TransfersWebhook'] as $d) {
    check("definitions[{$d}] present",        isset($schema['definitions'][$d]));
}
check('TransferCreate.type.allowed has debit + credit',
    in_array('debit',  $schema['definitions']['TransferCreate']['constraints']['type.allowed'] ?? [], true) &&
    in_array('credit', $schema['definitions']['TransferCreate']['constraints']['type.allowed'] ?? [], true));
check('TransferCreate.network.allowed includes ach + same-day-ach',
    in_array('ach', $schema['definitions']['TransferCreate']['constraints']['network.allowed'] ?? [], true) &&
    in_array('same-day-ach', $schema['definitions']['TransferCreate']['constraints']['network.allowed'] ?? [], true));
check('description.maxLength = 15 (Plaid ACH header field limit)',
    ($schema['definitions']['TransferCreate']['constraints']['description.maxLength'] ?? null) === 15);
check('errorCodes maps ITEM_LOGIN_REQUIRED to critical auth',
    ($schema['errorCodes']['ITEM_LOGIN_REQUIRED']['category'] ?? null) === 'auth' &&
    ($schema['errorCodes']['ITEM_LOGIN_REQUIRED']['severity'] ?? null) === 'critical');
check('refresh tool exists',                 is_file($tool));
check('tool sources plaid.com/docs',         str_contains((string) file_get_contents($tool), 'plaid.com'));

echo "\nplaid_spec_freshness smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
exit($failures ? 1 : 0);
