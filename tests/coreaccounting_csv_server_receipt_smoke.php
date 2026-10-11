<?php
/** Source-owned finance CSV receipt without a live database connection. */
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/csv_import_gate.php';
require_once __DIR__ . '/../core/CsvImportService.php';

final class CsvReceiptApiError extends RuntimeException {}

function api_json_body(): array { return $GLOBALS['csvReceiptBody']; }
function getDB(): ?PDO { return $GLOBALS['csvReceiptDb']; }
function api_error(string $message, int $status = 400, array $extra = []): void
{
    throw new CsvReceiptApiError($message, $status);
}

final class CsvReceiptFakePDO extends PDO
{
    public array $run;
    public array $sources = [];
    public bool $failWrite = false;
    public bool $failSavepointRollback = false;
    private bool $transaction = false;
    private array $snapshot = [];
    private array $savepoints = [];

    public function __construct()
    {
        $this->run = [
            'id' => 7, 'tenant_id' => 21, 'created_by_user_id' => 3,
            'error_summary' => json_encode([
                '_import_outcome_unconfirmed' => ['pending'],
                '_source_commit_started' => ['started'],
            ]),
            'rows_total' => 0, 'rows_imported' => 0,
            'rows_skipped' => 0, 'errors_count' => 0, 'status' => 'failed',
        ];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new CsvReceiptFakeStatement($this, $query);
    }
    public function beginTransaction(): bool
    {
        if ($this->transaction) throw new RuntimeException('Nested transaction is not allowed');
        $this->snapshot = $this->state();
        return $this->transaction = true;
    }
    public function commit(): bool
    {
        if (!$this->transaction) throw new RuntimeException('No transaction to commit');
        $this->transaction = false;
        $this->savepoints = [];
        return true;
    }
    public function rollBack(): bool
    {
        if (!$this->transaction) throw new RuntimeException('No transaction to roll back');
        $this->restore($this->snapshot);
        $this->transaction = false;
        $this->savepoints = [];
        return true;
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function exec(string $statement): int|false
    {
        if (!$this->transaction) throw new RuntimeException('Savepoint requires a transaction');
        if (preg_match('/^SAVEPOINT ([a-z_]+)$/', $statement, $match)) {
            $this->savepoints[$match[1]] = $this->state();
        } elseif (preg_match('/^ROLLBACK TO SAVEPOINT ([a-z_]+)$/', $statement, $match)) {
            if ($this->failSavepointRollback) return false;
            if (!isset($this->savepoints[$match[1]])) throw new RuntimeException('Unknown savepoint');
            $this->restore($this->savepoints[$match[1]]);
        } elseif (preg_match('/^RELEASE SAVEPOINT ([a-z_]+)$/', $statement, $match)) {
            if (!isset($this->savepoints[$match[1]])) throw new RuntimeException('Unknown savepoint');
            unset($this->savepoints[$match[1]]);
        } else {
            throw new RuntimeException('Unexpected transaction SQL: ' . $statement);
        }
        return 0;
    }

    private function state(): array { return ['run' => $this->run, 'sources' => $this->sources]; }
    private function restore(array $state): void
    {
        $this->run = $state['run'];
        $this->sources = $state['sources'];
    }

    public function read(array $params): array|false
    {
        return (int) $params['id'] === $this->run['id']
            && (int) $params['tenant_id'] === $this->run['tenant_id']
            && (int) $params['user_id'] === $this->run['created_by_user_id']
            ? ['error_summary' => $this->run['error_summary']] : false;
    }
    public function write(array $params): void
    {
        if ($this->failWrite) throw new RuntimeException('Simulated database write failure');
        if ((int) $params['id'] !== $this->run['id']
            || (int) $params['tenant_id'] !== $this->run['tenant_id']) {
            throw new RuntimeException('Incorrect receipt target');
        }
        foreach (['total' => 'rows_total', 'imported' => 'rows_imported',
                  'skipped' => 'rows_skipped', 'error_count' => 'errors_count',
                  'errors' => 'error_summary', 'status' => 'status'] as $from => $to) {
            $this->run[$to] = $params[$from];
        }
    }
}

final class CsvReceiptFakeStatement extends PDOStatement
{
    private array $params = [];
    public function __construct(private CsvReceiptFakePDO $db, private string $query) {}
    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (str_contains($this->query, 'UPDATE csv_import_history')) {
            $this->db->write($this->params);
        } elseif (!str_contains($this->query, 'SELECT error_summary FROM csv_import_history')) {
            throw new RuntimeException('Unexpected receipt SQL');
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->db->read($this->params);
    }
}

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$GLOBALS['csvReceiptBody'] = ['import_run_id' => 7];
$GLOBALS['csvReceiptDb'] = new CsvReceiptFakePDO();
putenv('COREFLUX_ENV=coreaccounting');

$result = ['imported_count' => 1, 'skipped_count' => 1,
    'row_count' => 4, 'imported_row_count' => 3, 'skipped_row_count' => 1,
    'errors' => ['4' => ['invalid amount']]];
$GLOBALS['csvReceiptDb']->beginTransaction();
$GLOBALS['csvReceiptDb']->sources[] = 'synthetic-source';
$saved = coreAccountingRecordCsvImportOutcome($result, 21, 3);
$run = $GLOBALS['csvReceiptDb']->run;
$summary = json_decode($run['error_summary'], true);
$check('source result is saved using row counts', $run['rows_total'] === 4
    && $run['rows_imported'] === 3 && $run['rows_skipped'] === 1
    && $run['errors_count'] === 1 && $run['status'] === 'partial');
$check('receipt is server-marked and returned', $saved['server_outcome_recorded'] === true
    && $saved['import_run_id'] === 7 && isset($summary['_server_outcome_recorded'])
    && !isset($summary['_import_outcome_unconfirmed']) && isset($summary['4'])
    && $GLOBALS['csvReceiptDb']->sources === ['synthetic-source']
    && !$GLOBALS['csvReceiptDb']->inTransaction());

$GLOBALS['csvReceiptDb'] = new CsvReceiptFakePDO();
$GLOBALS['csvReceiptDb']->beginTransaction();
$GLOBALS['csvReceiptDb']->sources[] = 'must-roll-back';
try {
    coreAccountingRecordCsvImportOutcome($result, 21, 9);
    $check('wrong actor cannot record outcome', false);
} catch (CsvReceiptApiError $error) {
    $check('wrong actor cannot record outcome', $error->getCode() === 503
        && str_contains($GLOBALS['csvReceiptDb']->run['error_summary'], '_import_outcome_unconfirmed')
        && $GLOBALS['csvReceiptDb']->sources === []);
}

$GLOBALS['csvReceiptDb'] = new CsvReceiptFakePDO();
$GLOBALS['csvReceiptDb']->failWrite = true;
$GLOBALS['csvReceiptDb']->beginTransaction();
$GLOBALS['csvReceiptDb']->sources[] = 'must-roll-back';
try {
    coreAccountingRecordCsvImportOutcome($result, 21, 3);
    $check('failed receipt rolls back the source and leaves run unconfirmed', false);
} catch (CsvReceiptApiError $error) {
    $check('failed receipt rolls back the source and leaves run unconfirmed', $error->getCode() === 503
        && !$GLOBALS['csvReceiptDb']->inTransaction()
        && $GLOBALS['csvReceiptDb']->sources === []
        && str_contains($GLOBALS['csvReceiptDb']->run['error_summary'], '_import_outcome_unconfirmed'));
}

$GLOBALS['csvReceiptDb'] = new CsvReceiptFakePDO();
try {
    coreAccountingRecordCsvImportOutcome($result, 21, 3);
    $check('receipt refuses an already committed source transaction', false);
} catch (CsvReceiptApiError $error) {
    $check('receipt refuses an already committed source transaction', $error->getCode() === 503);
}

\Core\CsvImportService::registerSchema('__receipt_atomic_test', [
    'fields' => ['name' => ['label' => 'Name', 'required' => true]],
]);
$csv = "Name\nFirst\nSecond\n";
$GLOBALS['csvReceiptDb'] = new CsvReceiptFakePDO();
$GLOBALS['csvReceiptDb']->beginTransaction();
$atomic = \Core\CsvImportService::commit('__receipt_atomic_test', $csv,
    static function (array $row): int {
        $GLOBALS['csvReceiptDb']->sources[] = $row['name'];
        if ($row['name'] === 'Second') throw new RuntimeException('simulated row failure');
        return count($GLOBALS['csvReceiptDb']->sources);
    }, ['atomic' => true]);
$check('atomic import rolls back all source rows but keeps receipt transaction',
    $atomic['imported_count'] === 0 && $GLOBALS['csvReceiptDb']->sources === []
    && $GLOBALS['csvReceiptDb']->inTransaction());
coreAccountingRecordCsvImportOutcome($atomic, 21, 3);
$check('failed atomic import records only its failure receipt',
    $GLOBALS['csvReceiptDb']->run['status'] === 'failed'
    && $GLOBALS['csvReceiptDb']->sources === []);

$GLOBALS['csvReceiptDb'] = new CsvReceiptFakePDO();
$GLOBALS['csvReceiptDb']->beginTransaction();
$partial = \Core\CsvImportService::commit('__receipt_atomic_test', $csv,
    static function (array $row): int {
        $GLOBALS['csvReceiptDb']->sources[] = $row['name'];
        if ($row['name'] === 'Second') throw new RuntimeException('simulated row failure');
        return count($GLOBALS['csvReceiptDb']->sources);
    }, ['skip_invalid' => true]);
$check('partial import rolls back the failed row only',
    $partial['imported_count'] === 1 && $partial['skipped_count'] === 1
    && $GLOBALS['csvReceiptDb']->sources === ['First']);
coreAccountingRecordCsvImportOutcome($partial, 21, 3);
$check('partial source and receipt commit together',
    $GLOBALS['csvReceiptDb']->run['status'] === 'partial'
    && $GLOBALS['csvReceiptDb']->sources === ['First']
    && !$GLOBALS['csvReceiptDb']->inTransaction());

$GLOBALS['csvReceiptDb'] = new CsvReceiptFakePDO();
$GLOBALS['csvReceiptDb']->beginTransaction();
$GLOBALS['csvReceiptDb']->failSavepointRollback = true;
try {
    \Core\CsvImportService::commit('__receipt_atomic_test', $csv,
        static function (array $row): int {
            $GLOBALS['csvReceiptDb']->sources[] = $row['name'];
            if ($row['name'] === 'Second') throw new RuntimeException('simulated row failure');
            return count($GLOBALS['csvReceiptDb']->sources);
        }, ['skip_invalid' => true]);
    $check('failed savepoint rollback stops the entire import', false);
} catch (RuntimeException $error) {
    $check('failed savepoint rollback stops the entire import',
        str_contains($error->getMessage(), 'Could not roll back')
        && !$GLOBALS['csvReceiptDb']->inTransaction()
        && $GLOBALS['csvReceiptDb']->sources === []
        && str_contains($GLOBALS['csvReceiptDb']->run['error_summary'], '_import_outcome_unconfirmed'));
}

$unitDb = new CsvReceiptFakePDO();
$unitDb->beginTransaction();
$ownsUnit = coreAccountingCsvBeginUnit($unitDb, 'csv_test_unit');
$unitDb->sources[] = 'discard';
coreAccountingCsvRollbackUnit($unitDb, 'csv_test_unit', $ownsUnit);
$check('document failure rolls back its savepoint without ending the import',
    !$ownsUnit && $unitDb->sources === [] && $unitDb->inTransaction());
$ownsUnit = coreAccountingCsvBeginUnit($unitDb, 'csv_test_unit');
$unitDb->sources[] = 'retain';
coreAccountingCsvCommitUnit($unitDb, 'csv_test_unit', $ownsUnit);
$check('document success remains pending until the import receipt',
    $unitDb->sources === ['retain'] && $unitDb->inTransaction());
$unitDb->rollBack();
$check('aborted import discards successful document savepoints', $unitDb->sources === []);

$unitDb = new CsvReceiptFakePDO();
$ownsUnit = coreAccountingCsvBeginUnit($unitDb, 'csv_test_unit');
$unitDb->sources[] = 'erp-row';
coreAccountingCsvCommitUnit($unitDb, 'csv_test_unit', $ownsUnit);
$check('broader ERP still owns its per-document transaction',
    $ownsUnit && $unitDb->sources === ['erp-row'] && !$unitDb->inTransaction());

putenv('COREFLUX_ENV=development');
$check('broader ERP keeps its prior result path',
    coreAccountingRecordCsvImportOutcome($result, 21, 3) === $result);

echo "\n--- {$passed} passed, {$failed} failed ---\n";
exit($failed === 0 ? 0 : 1);
