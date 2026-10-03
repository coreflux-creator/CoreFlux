<?php
/** Two-session exact-duplicate event submission against a synthetic staging tenant. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/posting_engine/process.php';

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) continue;
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $args[$key] = $value;
}
$tenantId = (int) ($args['tenant'] ?? 0);
if ($tenantId <= 0) {
    fwrite(STDERR, "Use --tenant=ID with a CoreFlux CI Simulation tenant.\n");
    exit(2);
}
$GLOBALS['__cf_request_tenant_id'] = $tenantId;
$pdo = getDB();
$tenantStmt = $pdo->prepare('SELECT name FROM tenants WHERE id = :id');
$tenantStmt->execute(['id' => $tenantId]);
if ($tenantStmt->fetchColumn() !== "CoreFlux CI Simulation {$tenantId}") {
    fwrite(STDERR, "This check can only run against a disposable CI Simulation tenant.\n");
    exit(2);
}

if (($args['mode'] ?? '') === 'child') {
    $event = json_decode(base64_decode((string) ($args['event'] ?? ''), true) ?: '', true);
    if (!is_array($event) || ($event['source_module'] ?? '') !== 'sim_event_race') exit(2);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 15');
    fwrite(STDOUT, "attempting\n");
    fflush(STDOUT);
    $started = microtime(true);
    try {
        $result = accountingProcessEvent($tenantId, $event);
        echo json_encode(['result' => $result, 'wait_ms' => round((microtime(true) - $started) * 1000)]) . "\n";
        exit(($result['status'] ?? '') === 'posted' && !empty($result['idempotent_replay']) ? 0 : 1);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage(),
            'wait_ms' => round((microtime(true) - $started) * 1000)]) . "\n";
        exit(1);
    }
}

$priorStmt = $pdo->prepare(
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
$sourceId = 'race:' . bin2hex(random_bytes(8));
$event = [
    'entity_id' => (int) $prior['entity_id'],
    'event_type' => 'ap.bill.approved',
    'source_module' => 'sim_event_race',
    'source_record_id' => $sourceId,
    'event_date' => (string) $prior['event_date'],
    'payload' => $payload,
];
$checks = [];
$eventId = 0;
$journalId = 0;
$proc = null;
$pipes = [];
$error = null;
try {
    $pdo->beginTransaction();
    $first = accountingProcessEvent($tenantId, $event);
    $eventId = (int) ($first['event_id'] ?? 0);
    $journalId = (int) ($first['journal_entry_id'] ?? 0);
    $checks['first_submission_posted_in_outer_transaction'] = $pdo->inTransaction()
        && ($first['status'] ?? '') === 'posted' && $eventId > 0 && $journalId > 0;
    if (!$checks['first_submission_posted_in_outer_transaction']) {
        throw new RuntimeException('First event did not post inside the held transaction.');
    }

    $encoded = base64_encode(json_encode($event, JSON_THROW_ON_ERROR));
    $cmd = [PHP_BINARY, __FILE__, '--mode=child', '--tenant=' . $tenantId, '--event=' . $encoded];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    if (!is_resource($proc)) throw new RuntimeException('Second PHP session could not start.');
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 18);
    if (trim((string) fgets($pipes[1])) !== 'attempting') {
        throw new RuntimeException('Second session did not start its submission.');
    }
    usleep(1_000_000);
    $pdo->commit();

    $child = json_decode(trim((string) fgets($pipes[1])), true);
    $childError = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $childExit = proc_close($proc);
    $proc = null;
    if ($childError !== '') throw new RuntimeException('Second session error: ' . $childError);
    $checks['second_submission_waited_for_commit'] = is_array($child)
        && (int) ($child['wait_ms'] ?? 0) >= 700;
    $second = $child['result'] ?? [];
    $checks['second_submission_replayed_first_result'] = $childExit === 0
        && ($second['status'] ?? '') === 'posted'
        && !empty($second['idempotent_replay'])
        && (int) ($second['event_id'] ?? 0) === $eventId
        && (int) ($second['journal_entry_id'] ?? 0) === $journalId;
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM accounting_events WHERE tenant_id = :t
            AND source_module = "sim_event_race" AND source_record_id = :source_id'
    );
    $countStmt->execute(['t' => $tenantId, 'source_id' => $sourceId]);
    $checks['only_one_event_row'] = (int) $countStmt->fetchColumn() === 1;
    $linkStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM accounting_subledger_links WHERE tenant_id = :t
            AND source_module = "sim_event_race" AND source_record_id = :source_id
            AND journal_entry_id = :je AND accounting_event_id = :event_id AND link_kind = "primary"'
    );
    $linkStmt->execute(['t' => $tenantId, 'source_id' => $sourceId,
        'je' => $journalId, 'event_id' => $eventId]);
    $checks['only_one_primary_source_link'] = (int) $linkStmt->fetchColumn() === 1;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
} finally {
    if (is_resource($proc)) {
        proc_terminate($proc);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($proc);
    }
    if ($eventId > 0 && $journalId > 0) {
        $activeStmt = $pdo->prepare(
            'SELECT status FROM accounting_events WHERE tenant_id = :t AND id = :id'
        );
        $activeStmt->execute(['t' => $tenantId, 'id' => $eventId]);
        if ($activeStmt->fetchColumn() === 'posted') {
            try {
                $pdo->beginTransaction();
                $reversal = accountingReverseJe($tenantId, $journalId,
                    'Synthetic concurrent event submission check', null);
                if (!empty($reversal['idempotent_replay'])) {
                    throw new RuntimeException('Synthetic journal was already reversed.');
                }
                $pdo->prepare(
                    'UPDATE accounting_events SET status = "reversed"
                      WHERE tenant_id = :t AND id = :id AND status = "posted"'
                )->execute(['t' => $tenantId, 'id' => $eventId]);
                $pdo->commit();
                $checks['synthetic_journal_reversed'] = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $checks['synthetic_journal_reversed'] = false;
                $error ??= $e->getMessage();
            }
        }
    }
}

echo json_encode(['tenant_id' => $tenantId, 'checks' => $checks,
    'error' => $error], JSON_PRETTY_PRINT) . "\n";
exit($error === null && $checks && !in_array(false, $checks, true) ? 0 : 1);
