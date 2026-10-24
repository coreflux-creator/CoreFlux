<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/export_paging.php';
require_once dirname(__DIR__) . '/core/CsvExportService.php';

$source = range(1, 10025);
$calls = [];
$rows = exportPagedRows(static function (int $size, int $offset) use ($source, &$calls): array {
    $calls[] = [$size, $offset];
    return array_map(static fn (int $id): array => ['id' => $id], array_slice($source, $offset, $size));
}, 1000);

$stream = fopen('php://temp', 'w+');
$written = (new \Core\CsvExportService(['id' => 'ID']))->writeToStream($stream, $rows);
rewind($stream);
$header = fgetcsv($stream, 0, ',', '"', '');
$first = fgetcsv($stream, 0, ',', '"', '');
$last = null;
$read = 1;
while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
    $last = $row;
    $read++;
}
fclose($stream);

if ($written !== 10025 || $read !== 10025 || $header !== ['ID']
    || $first !== ['1'] || $last !== ['10025']
    || count($calls) !== 11 || end($calls) !== [1000, 10000]) {
    fwrite(STDERR, "FAIL: paged CSV omitted, duplicated, or misordered rows.\n");
    exit(1);
}

$empty = iterator_to_array(exportPagedRows(static fn (int $size, int $offset): array => [], 1000), false);
if ($empty !== []) {
    fwrite(STDERR, "FAIL: empty export generated a row.\n");
    exit(1);
}
$prepared = fopen('php://temp', 'w+');
fwrite($prepared, str_repeat("CSV-row\n", 10000));
ob_start();
exportCopyPreparedStream($prepared);
$download = ob_get_clean();
fclose($prepared);
if ($download !== str_repeat("CSV-row\n", 10000)) {
    fwrite(STDERR, "FAIL: prepared export copy lost bytes.\n");
    exit(1);
}
$activity = exportAccountActivityRunningBalances(exportPagedRows(
    static function (int $size, int $offset): array {
        $end = min($offset + $size, 10025);
        $page = [];
        for ($i = $offset; $i < $end; $i++) {
            $page[] = ['normal_side' => 'debit', 'debit' => '0.01', 'credit' => '0.00'];
        }
        return $page;
    }
));
$activityCount = 0;
$lastBalance = null;
foreach ($activity as $row) {
    $activityCount++;
    $lastBalance = $row['running_balance'];
}
if ($activityCount !== 10025 || $lastBalance !== '100.25') {
    fwrite(STDERR, "FAIL: account-activity balance drifted or omitted ledger lines.\n");
    exit(1);
}
$creditBalances = array_column(iterator_to_array(exportAccountActivityRunningBalances([
    ['normal_side' => 'credit', 'debit' => '0.00', 'credit' => '10.01'],
    ['normal_side' => 'credit', 'debit' => '0.02', 'credit' => '0.00'],
]), false), 'running_balance');
if ($creditBalances !== ['10.01', '9.99']) {
    fwrite(STDERR, "FAIL: credit-normal account balance used the wrong direction.\n");
    exit(1);
}
echo "PASS: 10,025 rows survived bounded CSV paging without a cutoff.\n";
echo "PASS: 10,025 account-activity lines retained a cent-exact running balance.\n";
