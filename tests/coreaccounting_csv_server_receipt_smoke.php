<?php
/** Source-owned finance CSV receipt without a live database connection. */
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/csv_import_gate.php';

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
    public bool $failWrite = false;
    private bool $transaction = false;
    private array $snapshot = [];

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
        $this->snapshot = $this->run;
        return $this->transaction = true;
    }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool
    {
        $this->run = $this->snapshot;
        $this->transaction = false;
        return true;
    }
    public function inTransaction(): bool { return $this->transaction; }

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
$saved = coreAccountingRecordCsvImportOutcome($result, 21, 3);
$run = $GLOBALS['csvReceiptDb']->run;
$summary = json_decode($run['error_summary'], true);
$check('source result is saved using row counts', $run['rows_total'] === 4
    && $run['rows_imported'] === 3 && $run['rows_skipped'] === 1
    && $run['errors_count'] === 1 && $run['status'] === 'partial');
$check('receipt is server-marked and returned', $saved['server_outcome_recorded'] === true
    && $saved['import_run_id'] === 7 && isset($summary['_server_outcome_recorded'])
    && !isset($summary['_import_outcome_unconfirmed']) && isset($summary['4']));

$GLOBALS['csvReceiptDb'] = new CsvReceiptFakePDO();
try {
    coreAccountingRecordCsvImportOutcome($result, 21, 9);
    $check('wrong actor cannot record outcome', false);
} catch (CsvReceiptApiError $error) {
    $check('wrong actor cannot record outcome', $error->getCode() === 503
        && str_contains($GLOBALS['csvReceiptDb']->run['error_summary'], '_import_outcome_unconfirmed'));
}

$GLOBALS['csvReceiptDb'] = new CsvReceiptFakePDO();
$GLOBALS['csvReceiptDb']->failWrite = true;
try {
    coreAccountingRecordCsvImportOutcome($result, 21, 3);
    $check('failed receipt leaves run unconfirmed', false);
} catch (CsvReceiptApiError $error) {
    $check('failed receipt leaves run unconfirmed', $error->getCode() === 503
        && !$GLOBALS['csvReceiptDb']->inTransaction()
        && str_contains($GLOBALS['csvReceiptDb']->run['error_summary'], '_import_outcome_unconfirmed'));
}

putenv('COREFLUX_ENV=development');
$check('broader ERP keeps its prior result path',
    coreAccountingRecordCsvImportOutcome($result, 21, 3) === $result);

echo "\n--- {$passed} passed, {$failed} failed ---\n";
exit($failed === 0 ? 0 : 1);
