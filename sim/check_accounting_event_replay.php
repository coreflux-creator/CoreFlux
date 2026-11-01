<?php
/** Rollback-only staging check for canonical accounting-event replay. */
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
$pdo = getDB();
$tenant = $pdo->prepare('SELECT name FROM tenants WHERE id = :id');
$tenant->execute(['id' => $tenantId]);
if ($tenant->fetchColumn() !== "CoreFlux CI Simulation {$tenantId}") {
    fwrite(STDERR, "The tenant must be an isolated CI Simulation tenant.\n");
    exit(2);
}

$posted = $pdo->prepare(
    'SELECT entity_id, event_type, source_module, source_record_id, event_date, payload,
            id, journal_entry_id
       FROM accounting_events
      WHERE tenant_id = :t AND status = "posted" AND journal_entry_id IS NOT NULL
      ORDER BY id DESC LIMIT 1'
);
$posted->execute(['t' => $tenantId]);
$prior = $posted->fetch(PDO::FETCH_ASSOC);
if (!$prior) {
    fwrite(STDERR, "The simulation tenant needs one posted event for replay checks.\n");
    exit(2);
}

$rules = $pdo->prepare(
    'SELECT COUNT(*) FROM accounting_posting_rules
      WHERE tenant_id = :t AND event_type = "ar.invoice.drafted" AND status = "active"'
);
$rules->execute(['t' => $tenantId]);
if ((int) $rules->fetchColumn() !== 0) {
    fwrite(STDERR, "This fixture requires no active posting rule for ar.invoice.drafted.\n");
    exit(2);
}

$checks = [];
$expect = static function (string $label, bool $ok) use (&$checks): void {
    $checks[$label] = $ok;
    if (!$ok) throw new RuntimeException("Check failed: {$label}");
};
$replay = [
    'entity_id' => (int) $prior['entity_id'],
    'event_type' => (string) $prior['event_type'],
    'source_module' => (string) $prior['source_module'],
    'source_record_id' => (string) $prior['source_record_id'],
    'event_date' => (string) $prior['event_date'],
    'payload' => json_decode((string) $prior['payload'], true),
];
$outerless = accountingProcessEvent($tenantId, $replay);
$expect('engine_owned_transaction_replay', $outerless['status'] === 'posted'
    && !empty($outerless['idempotent_replay']) && !$pdo->inTransaction());
$sourceId = 'rollback-event-replay-' . bin2hex(random_bytes(8));
$newEventId = 0;
$pdo->beginTransaction();
try {
    $same = accountingProcessEvent($tenantId, $replay);
    $expect('posted_exact_replay', $same['status'] === 'posted'
        && !empty($same['idempotent_replay'])
        && (int) $same['event_id'] === (int) $prior['id']
        && (int) $same['journal_entry_id'] === (int) $prior['journal_entry_id']);

    $changed = $replay;
    $changed['event_date'] = $replay['event_date'] === '2026-10-03' ? '2026-10-02' : '2026-10-03';
    $conflicted = false;
    try {
        accountingProcessEvent($tenantId, $changed);
    } catch (AccountingEventConflictException $e) {
        $conflicted = true;
    }
    $expect('posted_changed_date_rejected', $conflicted);

    $fresh = [
        'entity_id' => (int) $prior['entity_id'],
        'event_type' => 'ar.invoice.drafted',
        'source_module' => 'sim_event_replay',
        'source_record_id' => $sourceId,
        'event_date' => date('Y-m-d'),
        'payload' => ['invoice_id' => 0, 'total' => 0, 'currency' => 'USD', 'lines' => []],
    ];
    $first = accountingProcessEvent($tenantId, $fresh);
    $newEventId = (int) ($first['event_id'] ?? 0);
    $expect('ignored_event_created', $first['status'] === 'ignored' && $newEventId > 0);
    $again = accountingProcessEvent($tenantId, $fresh);
    $expect('ignored_exact_replay_keeps_id', $again['status'] === 'ignored'
        && (int) $again['event_id'] === $newEventId);

    $changed = $fresh;
    $changed['payload']['_replay_probe'] = 'changed';
    $conflicted = false;
    try {
        accountingProcessEvent($tenantId, $changed);
    } catch (AccountingEventConflictException $e) {
        $conflicted = true;
    }
    $expect('ignored_changed_payload_rejected', $conflicted);
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    echo json_encode(['checks' => $checks, 'passed' => count(array_filter($checks))]) . "\n";
    exit(1);
}

$verify = $pdo->prepare('SELECT COUNT(*) FROM accounting_events WHERE tenant_id = :t AND id = :id');
$verify->execute(['t' => $tenantId, 'id' => $newEventId]);
$expect('fixture_rolled_back', (int) $verify->fetchColumn() === 0);
echo json_encode(['checks' => $checks, 'passed' => count(array_filter($checks))], JSON_PRETTY_PRINT) . "\n";
