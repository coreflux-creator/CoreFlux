<?php
/** Inject a source-link failure after JE creation on synthetic staging only. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/posting_engine/process.php';

$tenantId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) $tenantId = (int) substr($arg, 9);
}
if ($tenantId <= 0) {
    fwrite(STDERR, "Use --tenant=ID with a CoreFlux CI Simulation tenant.\n");
    exit(2);
}
$GLOBALS['__cf_request_tenant_id'] = $tenantId;
$originalDb = getDB();
$tenantStmt = $originalDb->prepare('SELECT name FROM tenants WHERE id = :id');
$tenantStmt->execute(['id' => $tenantId]);
if ($tenantStmt->fetchColumn() !== "CoreFlux CI Simulation {$tenantId}") {
    fwrite(STDERR, "This check can only run against a disposable CI Simulation tenant.\n");
    exit(2);
}

class FaultOnEventLinkPdo extends PDO
{
    public int $tenantId = 0;
    public int $seenEventId = 0;
    public int $seenJournalId = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'INSERT IGNORE INTO accounting_subledger_links')) {
            $eventStmt = parent::query(
                'SELECT id FROM accounting_events WHERE tenant_id = ' . $this->tenantId
                . ' AND source_module = "sim_event_fault" ORDER BY id DESC LIMIT 1'
            );
            $journalStmt = parent::query(
                'SELECT id FROM accounting_journal_entries WHERE tenant_id = ' . $this->tenantId
                . ' AND source_module = "sim_event_fault" ORDER BY id DESC LIMIT 1'
            );
            $this->seenEventId = (int) $eventStmt->fetchColumn();
            $this->seenJournalId = (int) $journalStmt->fetchColumn();
            throw new RuntimeException('Injected source-link failure after journal posting');
        }
        return parent::prepare($query, $options);
    }
}

$priorStmt = $originalDb->prepare(
    'SELECT entity_id, event_date, payload FROM accounting_events
      WHERE tenant_id = :t AND event_type = "ap.bill.approved"
        AND status = "posted" AND journal_entry_id IS NOT NULL
      ORDER BY id DESC LIMIT 1'
);
$priorStmt->execute(['t' => $tenantId]);
$prior = $priorStmt->fetch(PDO::FETCH_ASSOC);
if (!$prior) {
    fwrite(STDERR, "Run the canonical AP simulation scenario on this tenant first.\n");
    exit(2);
}
$payload = json_decode((string) $prior['payload'], true);
if (!is_array($payload) || !is_array($payload['lines'] ?? null)) {
    throw new RuntimeException('Synthetic AP event has no payload lines.');
}
unset($payload['source_ref_type'], $payload['source_ref_id']);
$sourceId = 'fault:' . bin2hex(random_bytes(8));
$event = [
    'entity_id' => (int) $prior['entity_id'],
    'event_type' => 'ap.bill.approved',
    'source_module' => 'sim_event_fault',
    'source_record_id' => $sourceId,
    'event_date' => (string) $prior['event_date'],
    'payload' => $payload,
];
$countEvents = $originalDb->prepare(
    'SELECT COUNT(*) FROM accounting_events WHERE tenant_id = :t
        AND source_module = "sim_event_fault" AND source_record_id = :source_id'
);
$countLinks = $originalDb->prepare(
    'SELECT COUNT(*) FROM accounting_subledger_links WHERE tenant_id = :t
        AND source_module = "sim_event_fault" AND source_record_id = :source_id'
);
$countJournals = $originalDb->prepare(
    'SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = :t
        AND source_module = "sim_event_fault"'
);
$countJournals->execute(['t' => $tenantId]);
$journalsBefore = (int) $countJournals->fetchColumn();

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$faultDb = new FaultOnEventLinkPdo($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$faultDb->tenantId = $tenantId;
$threwAtLink = false;
$GLOBALS['pdo'] = $faultDb;
try {
    accountingProcessEvent($tenantId, $event);
} catch (RuntimeException $e) {
    $threwAtLink = $e->getMessage() === 'Injected source-link failure after journal posting';
} finally {
    $GLOBALS['pdo'] = $originalDb;
}

$checks = [
    'fault_reached_source_link_write' => $threwAtLink,
    'event_and_journal_existed_before_fault' => $faultDb->seenEventId > 0 && $faultDb->seenJournalId > 0,
    'engine_closed_its_failed_transaction' => !$faultDb->inTransaction(),
];
$countEvents->execute(['t' => $tenantId, 'source_id' => $sourceId]);
$checks['event_rolled_back'] = (int) $countEvents->fetchColumn() === 0;
$countLinks->execute(['t' => $tenantId, 'source_id' => $sourceId]);
$checks['source_link_rolled_back'] = (int) $countLinks->fetchColumn() === 0;
$countJournals->execute(['t' => $tenantId]);
$checks['journal_rolled_back'] = (int) $countJournals->fetchColumn() === $journalsBefore;

if (!in_array(false, $checks, true)) {
    $originalDb->beginTransaction();
    try {
        $retry = accountingProcessEvent($tenantId, $event);
        $checks['same_source_can_retry_after_rollback'] = ($retry['status'] ?? '') === 'posted'
            && empty($retry['idempotent_replay'])
            && (int) ($retry['event_id'] ?? 0) > 0
            && (int) ($retry['journal_entry_id'] ?? 0) > 0;
        $originalDb->rollBack();
    } catch (Throwable $e) {
        if ($originalDb->inTransaction()) $originalDb->rollBack();
        $checks['same_source_can_retry_after_rollback'] = false;
    }
    $countEvents->execute(['t' => $tenantId, 'source_id' => $sourceId]);
    $checks['retry_fixture_rolled_back'] = (int) $countEvents->fetchColumn() === 0;
}

echo json_encode(['tenant_id' => $tenantId, 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
exit($checks && !in_array(false, $checks, true) ? 0 : 1);
