<?php
/** Exercise batch and row rollback without requiring a database server. */
declare(strict_types=1);

final class CsvTransactionProbe extends PDO
{
    public array $values = [];
    private bool $active = false;
    private array $snapshots = [];

    public function __construct() {}

    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool
    {
        $this->active = true;
        $this->snapshots['transaction'] = $this->values;
        return true;
    }
    public function commit(): bool
    {
        $this->active = false;
        $this->snapshots = [];
        return true;
    }
    public function rollBack(): bool
    {
        $this->values = $this->snapshots['transaction'] ?? [];
        $this->active = false;
        $this->snapshots = [];
        return true;
    }
    public function exec(string $statement): int|false
    {
        if (preg_match('/^SAVEPOINT (\w+)$/', $statement, $match)) {
            $this->snapshots[$match[1]] = $this->values;
        } elseif (preg_match('/^ROLLBACK TO SAVEPOINT (\w+)$/', $statement, $match)) {
            $this->values = $this->snapshots[$match[1]];
        } elseif (preg_match('/^RELEASE SAVEPOINT (\w+)$/', $statement, $match)) {
            unset($this->snapshots[$match[1]]);
        } else {
            throw new RuntimeException("Unexpected transaction statement: {$statement}");
        }
        return 0;
    }
}

$probe = new CsvTransactionProbe();
function getDB(): PDO
{
    global $probe;
    return $probe;
}

require_once __DIR__ . '/../core/CsvImportService.php';
\Core\CsvImportService::registerSchema('__atomic_probe', [
    'fields' => ['name' => ['label' => 'Name', 'required' => true]],
]);
$csv = "Name\nfirst\nfail\nlast\n";
$write = static function (array $row) use ($probe): int {
    $probe->values[] = $row['name'];
    if ($row['name'] === 'fail') throw new RuntimeException('rejected after write');
    return count($probe->values);
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$result = \Core\CsvImportService::commit('__atomic_probe', $csv, $write, ['atomic' => true]);
$assert($result['imported_count'] === 0 && $probe->values === [], 'Atomic batch left partial writes');

$result = \Core\CsvImportService::commit('__atomic_probe', $csv, $write, [
    'skip_invalid' => true, 'row_atomic' => true,
]);
$assert($result['imported_count'] === 2 && $probe->values === ['first', 'last'],
    'Skip-invalid mode did not roll back the rejected row');

$probe->values = [];
$probe->beginTransaction();
$result = \Core\CsvImportService::commit('__atomic_probe', $csv, $write, ['atomic' => true]);
$assert($result['imported_count'] === 0 && $probe->values === [] && $probe->inTransaction(),
    'Atomic batch did not roll back to the outer transaction savepoint');
$probe->rollBack();

echo "CSV import transactions: ok\n";
