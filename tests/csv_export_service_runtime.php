<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CsvExportService.php';

set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
});

$csv = \Core\CsvExportService::toString(
    ['code' => 'Account code', 'name' => 'Account name', 'active' => 'Active'],
    [
        ['code' => '1997', 'name' => 'QA "Temporary", Asset', 'active' => true],
        ['code' => '1998', 'name' => 'Backslash \\ account', 'active' => false],
    ]
);
restore_error_handler();

$stream = fopen('php://temp', 'w+');
fwrite($stream, $csv);
rewind($stream);
$rows = [];
while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) $rows[] = $row;
fclose($stream);

$expected = [
    ['Account code', 'Account name', 'Active'],
    ['1997', 'QA "Temporary", Asset', '1'],
    ['1998', 'Backslash \\ account', '0'],
];
if ($rows !== $expected) {
    fwrite(STDERR, "FAIL: CSV export did not round-trip headers and rows.\n");
    exit(1);
}
echo "PASS: CSV export round-trips without PHP warnings.\n";
