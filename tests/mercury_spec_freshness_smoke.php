<?php
/** Smoke — Mercury spec freshness (lightweight, mirrors QBO/Zoho). */
declare(strict_types=1);
$passes = 0; $failures = [];
function check(string $l, bool $c) { global $passes, $failures; if ($c) {$passes++; echo "  ✓ {$l}\n";} else {$failures[]=$l; echo "  ✗ {$l}\n";} }

echo "\nMercury spec freshness\n======================\n\n";
$schemaPath = '/app/spec/mercury_schema.json';
$tool       = '/app/tools/refresh_mercury_spec.sh';
check('spec exists',                          is_file($schemaPath));
$schema = json_decode((string) file_get_contents($schemaPath), true);
check('schema parses as JSON',                is_array($schema));
foreach (['PaymentCreate', 'RecipientCreate', 'RoutingInfo'] as $d) {
    check("definitions[{$d}] present",        isset($schema['definitions'][$d]));
}
check('PaymentCreate.note.maxLength = 50',
    ($schema['definitions']['PaymentCreate']['constraints']['note.maxLength'] ?? null) === 50);
check('paymentMethod allowed includes ach + wire',
    in_array('ach',  $schema['definitions']['PaymentCreate']['constraints']['paymentMethod.allowed'] ?? [], true) &&
    in_array('wire', $schema['definitions']['PaymentCreate']['constraints']['paymentMethod.allowed'] ?? [], true));
check('RoutingInfo.routingNumber.length = 9',
    ($schema['definitions']['RoutingInfo']['constraints']['routingNumber.length'] ?? null) === 9);
check('refresh tool exists + executable on Unix or local Windows checkout',
    is_file($tool) && (is_executable($tool) || DIRECTORY_SEPARATOR === '\\'));
check('tool sources docs.mercury.com',        str_contains((string) file_get_contents($tool), 'docs.mercury.com'));

echo "\nmercury_spec_freshness smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
exit($failures ? 1 : 0);
