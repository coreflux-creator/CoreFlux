<?php
/** A CSV import's original evidence must remain reachable for audit. */
declare(strict_types=1);

putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../core/evidence_attachments.php';

final class ImportEvidencePDO extends PDO {
    public array $rows = [
        10 => ['tenant_id' => 1, 'subject_type' => 'csv_import_run', 'deleted_at' => null, 'superseded_by_id' => null],
        11 => ['tenant_id' => 1, 'subject_type' => 'billing_invoice', 'deleted_at' => null, 'superseded_by_id' => null],
        12 => ['tenant_id' => 2, 'subject_type' => 'csv_import_run', 'deleted_at' => null, 'superseded_by_id' => null],
        13 => ['tenant_id' => 1, 'subject_type' => 'ap_bill', 'deleted_at' => null, 'superseded_by_id' => null],
    ];
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (!str_contains($query, 'SELECT 1 FROM evidence_attachments LIMIT 0')) {
            throw new RuntimeException('Unexpected evidence table probe');
        }
        return new ImportEvidenceStatement($this, $query);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ImportEvidenceStatement($this, $query);
    }
}

final class ImportEvidenceStatement extends PDOStatement {
    private array $params = [];
    private int $affected = 0;
    public function __construct(private ImportEvidencePDO $db, private string $query) {}
    public function execute(?array $params = null): bool {
        $this->params = $params ?? [];
        $id = (int) ($this->params['id'] ?? $this->params['old'] ?? 0);
        $row = $this->db->rows[$id] ?? null;
        if (!str_contains($this->query, 'UPDATE evidence_attachments')) return true;
        if (!$row || $row['tenant_id'] !== (int) ($this->params['t'] ?? 0)
            || $row['subject_type'] === 'csv_import_run') return true;
        if (str_contains($this->query, 'SET deleted_at = NOW()') && $row['deleted_at'] === null) {
            $this->db->rows[$id]['deleted_at'] = 'now';
            $this->affected = 1;
        }
        if (str_contains($this->query, 'SET superseded_by_id = :new') && $row['superseded_by_id'] === null) {
            $this->db->rows[$id]['superseded_by_id'] = (int) $this->params['new'];
            $this->affected = 1;
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0): mixed {
        $id = (int) ($this->params['id'] ?? 0);
        $row = $this->db->rows[$id] ?? null;
        return $row && $row['tenant_id'] === (int) ($this->params['t'] ?? 0)
            && $row['deleted_at'] === null ? ['subject_type' => $row['subject_type']] : false;
    }
    public function rowCount(): int { return $this->affected; }
}

$checks = 0;
$assert = static function (string $name, bool $passed) use (&$checks): void {
    if (!$passed) throw new RuntimeException($name);
    $checks++;
};

$assert('CSV source is protected', evidenceIsProtectedImportAttachment([
    'subject_type' => 'csv_import_run', 'document_type' => 'csv_source',
]));
$assert('column map is protected', evidenceIsProtectedImportAttachment([
    'subject_type' => 'csv_import_run', 'document_type' => 'column_map',
]));
$assert('normal invoice evidence remains editable', !evidenceIsProtectedImportAttachment([
    'subject_type' => 'billing_invoice', 'document_type' => 'customer_po',
]));
$assert('missing subject is not mistaken for an import', !evidenceIsProtectedImportAttachment([]));

$GLOBALS['pdo'] = new ImportEvidencePDO();
$rejectedDelete = false;
try { evidenceSoftDelete(1, 10, 4); }
catch (DomainException $e) { $rejectedDelete = str_contains($e->getMessage(), 'Import evidence'); }
$assert('import source cannot be deleted', $rejectedDelete
    && $GLOBALS['pdo']->rows[10]['deleted_at'] === null);
$rejectedSupersede = false;
try { evidenceSupersede(1, 10, 13); }
catch (DomainException $e) { $rejectedSupersede = str_contains($e->getMessage(), 'Import evidence'); }
$assert('import source cannot be superseded', $rejectedSupersede
    && $GLOBALS['pdo']->rows[10]['superseded_by_id'] === null);
$assert('ordinary invoice evidence can be deleted', evidenceSoftDelete(1, 11, 4)
    && $GLOBALS['pdo']->rows[11]['deleted_at'] !== null);
$assert('ordinary bill evidence can be superseded', evidenceSupersede(1, 13, 11)
    && $GLOBALS['pdo']->rows[13]['superseded_by_id'] === 11);
$assert('another tenant cannot mutate evidence', !evidenceSoftDelete(1, 12, 4)
    && $GLOBALS['pdo']->rows[12]['deleted_at'] === null);

$helper = file_get_contents(__DIR__ . '/../core/evidence_attachments.php');
$api = file_get_contents(__DIR__ . '/../api/accounting/evidence.php');
$assert('delete checks tenant-owned row', str_contains($helper, '_evidenceRequireMutableAttachment($pdo, $tenantId, $id)'));
$assert('supersede checks tenant-owned row', str_contains($helper, '_evidenceRequireMutableAttachment($pdo, $tenantId, $oldId)'));
$assert('both updates keep atomic import guard', substr_count($helper, "subject_type <> 'csv_import_run'") === 2);
$assert('both API actions report locked evidence', substr_count($api, 'catch (\\DomainException $e)') === 2);

echo "Passed: {$checks} import-evidence immutability checks\n";
