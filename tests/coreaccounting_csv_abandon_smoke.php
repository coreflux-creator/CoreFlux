<?php
/** Locked, explicit closure of a finance CSV reservation with no source commit. */
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/csv_import_run_recovery.php';

final class CsvAbandonPDO extends PDO
{
    public array $run;
    public bool $failWrite = false;
    public array $queries = [];
    private bool $transaction = false;
    private array $snapshot = [];

    public function __construct()
    {
        $this->run = [
            'id' => 12, 'tenant_id' => 21, 'created_by_user_id' => 3,
            'entity' => 'ap_vendors', 'status' => 'failed',
            'rows_total' => 1, 'rows_imported' => 0, 'rows_skipped' => 0,
            'errors_count' => 0,
            'error_summary' => json_encode(['_import_outcome_unconfirmed' => ['pending']]),
        ];
    }

    public function beginTransaction(): bool
    {
        if ($this->transaction) throw new RuntimeException('Nested transaction');
        $this->snapshot = $this->run;
        return $this->transaction = true;
    }
    public function commit(): bool
    {
        if (!$this->transaction) throw new RuntimeException('No transaction');
        $this->transaction = false;
        return true;
    }
    public function rollBack(): bool
    {
        if (!$this->transaction) throw new RuntimeException('No transaction');
        $this->run = $this->snapshot;
        $this->transaction = false;
        return true;
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return new CsvAbandonStatement($this, $query);
    }
}

final class CsvAbandonStatement extends PDOStatement
{
    private array $params = [];
    private int $affected = 0;
    public function __construct(private CsvAbandonPDO $db, private string $query) {}
    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (str_contains($this->query, 'UPDATE csv_import_history')) {
            if ($this->db->failWrite) throw new RuntimeException('Simulated closure write failure');
            $this->db->run['error_summary'] = $this->params['errors'];
            $this->db->run['status'] = 'failed';
            $this->db->run['errors_count'] = 0;
            $this->affected = 1;
        } elseif (!str_contains($this->query, 'SELECT id, entity, status, error_summary')) {
            throw new RuntimeException('Unexpected closure SQL');
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return (int) ($this->params['id'] ?? 0) === $this->db->run['id']
            && (int) ($this->params['tenant_id'] ?? 0) === $this->db->run['tenant_id']
            && (int) ($this->params['user_id'] ?? 0) === $this->db->run['created_by_user_id']
            ? $this->db->run : false;
    }
    public function rowCount(): int { return $this->affected; }
}

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
putenv('COREFLUX_ENV=coreaccounting');

$db = new CsvAbandonPDO();
$check('unstarted reservation is eligible', coreAccountingCsvCanAbandonUnstarted($db->run));
$result = coreAccountingCsvAbandonUnstarted($db, 12, 21, 3);
$markers = json_decode($db->run['error_summary'], true);
$check('closure is attributed and removes the claim marker', $result['state'] === 'abandoned'
    && !isset($markers['_import_outcome_unconfirmed'])
    && (int) $markers['_import_abandoned']['by_user_id'] === 3
    && $db->run['rows_imported'] === 0 && !$db->inTransaction());
$check('closure uses the import row lock', str_contains($db->queries[0], 'FOR UPDATE'));
$check('exact closure replay is idempotent',
    coreAccountingCsvAbandonUnstarted($db, 12, 21, 3)['state'] === 'already_abandoned');

$db = new CsvAbandonPDO();
$check('another user cannot close the run',
    coreAccountingCsvAbandonUnstarted($db, 12, 21, 9)['state'] === 'not_found'
    && coreAccountingCsvCanAbandonUnstarted($db->run));

$db = new CsvAbandonPDO();
$db->run['error_summary'] = json_encode([
    '_import_outcome_unconfirmed' => ['pending'], '_source_commit_started' => ['started'],
]);
$check('an uncertain source commit cannot be closed',
    coreAccountingCsvAbandonUnstarted($db, 12, 21, 3)['state'] === 'needs_review'
    && !coreAccountingCsvCanAbandonUnstarted($db->run));

$db = new CsvAbandonPDO();
$db->run['status'] = 'success';
$db->run['rows_imported'] = 1;
$db->run['error_summary'] = json_encode(['_server_outcome_recorded' => ['done']]);
$check('a receipt arriving first wins over closure',
    coreAccountingCsvAbandonUnstarted($db, 12, 21, 3)['state'] === 'already_recorded'
    && $db->run['rows_imported'] === 1);

$db = new CsvAbandonPDO();
$db->run['rows_imported'] = 1;
$check('inconsistent imported count requires review',
    coreAccountingCsvAbandonUnstarted($db, 12, 21, 3)['state'] === 'needs_review');

$db = new CsvAbandonPDO();
$db->run['entity'] = 'people';
$check('non-finance run cannot be closed',
    coreAccountingCsvAbandonUnstarted($db, 12, 21, 3)['state'] === 'needs_review');

$db = new CsvAbandonPDO();
$db->failWrite = true;
try {
    coreAccountingCsvAbandonUnstarted($db, 12, 21, 3);
    $check('write failure leaves the run unconfirmed', false);
} catch (RuntimeException $error) {
    $check('write failure leaves the run unconfirmed', !$db->inTransaction()
        && coreAccountingCsvCanAbandonUnstarted($db->run));
}

putenv('COREFLUX_ENV=development');
$db = new CsvAbandonPDO();
try {
    coreAccountingCsvAbandonUnstarted($db, 12, 21, 3);
    $check('broader ERP cannot use the closure action', false);
} catch (RuntimeException $error) {
    $check('broader ERP cannot use the closure action', !$db->inTransaction());
}

echo "\n--- {$passed} passed, {$failed} failed ---\n";
exit($failed === 0 ? 0 : 1);
