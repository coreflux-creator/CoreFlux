<?php
/** Smoke — Zoho spec freshness (lightweight pattern, mirrors QBO). */
declare(strict_types=1);
$passes = 0; $failures = [];
function check(string $l, bool $c) { global $passes, $failures; if ($c) {$passes++; echo "  ✓ {$l}\n";} else {$failures[]=$l; echo "  ✗ {$l}\n";} }

echo "\nZoho spec freshness\n===================\n\n";
$schemaPath = '/app/spec/zoho_schema.json';
$tool       = '/app/tools/refresh_zoho_spec.sh';
check('spec exists',                   is_file($schemaPath));
$schema = json_decode((string) file_get_contents($schemaPath), true);
check('schema parses as JSON',         is_array($schema));
foreach (['BillCreate', 'InvoiceCreate', 'JournalCreate', 'JournalLineItem'] as $d) {
    check("definitions[{$d}] present", isset($schema['definitions'][$d]));
}
check('JournalLineItem.debit_or_credit enum locked to debit|credit',
    ($schema['definitions']['JournalLineItem']['constraints']['debit_or_credit.allowed'] ?? []) === ['debit','credit']);
check('refresh tool exists',           is_file($tool));
check('refresh tool executable',       is_file($tool) && is_executable($tool));
check('tool sources www.zoho.com/books/api/v3',
    str_contains((string) file_get_contents($tool), 'www.zoho.com/books/api/v3'));

echo "\nzoho_spec_freshness smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
exit($failures ? 1 : 0);
