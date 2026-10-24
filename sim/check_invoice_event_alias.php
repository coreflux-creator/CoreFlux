<?php
/** Rollback-only check that canonical invoice events reuse legacy posted events. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array((string) getenv('COREFLUX_ENV'), ['coreaccounting', 'staging'], true)
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Requires CLI, a QA environment, and --confirm-disposable-fixture.\n");
    exit(2);
}

$tenantId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) $tenantId = (int) substr($arg, 9);
}
if ($tenantId <= 0) exit(2);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/tenant_scope.php';
require_once __DIR__ . '/../core/posting_engine/process.php';

$pdo = getDB();
if (!$pdo) throw new RuntimeException('Database unavailable');
$tenant = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :t');
$tenant->execute(['t' => $tenantId]);
if ((int) $tenant->fetchColumn() !== 1) {
    fwrite(STDERR, "Refusing a non-simulation tenant.\n");
    exit(2);
}
setRequestTenantId($tenantId);

$source = $pdo->prepare(
    'SELECT id, entity_id, event_date, payload, journal_entry_id
       FROM accounting_events
      WHERE tenant_id = :t AND event_type = "ar.invoice.issued"
        AND source_module = "billing" AND source_record_id = "sim:invoice:0001"
        AND status = "posted" AND journal_entry_id IS NOT NULL
      LIMIT 1'
);
$source->execute(['t' => $tenantId]);
$prior = $source->fetch(PDO::FETCH_ASSOC);
if (!$prior) throw new RuntimeException('Run ar_invoice_happy_path before this check');
$payload = json_decode((string) $prior['payload'], true, 512, JSON_THROW_ON_ERROR);

$rules = $pdo->prepare(
    'SELECT id, journal_template_id FROM accounting_posting_rules
      WHERE tenant_id = :t AND event_type = "ar.invoice.issued" AND status = "active"'
);
$rules->execute(['t' => $tenantId]);
$canonicalRules = $rules->fetchAll(PDO::FETCH_ASSOC);
if (count($canonicalRules) !== 1) throw new RuntimeException('Expected one canonical invoice rule in disposable fixture');
$canonicalRuleId = (int) $canonicalRules[0]['id'];

$checks = [];
$expect = static function (string $name, bool $condition) use (&$checks): void {
    $checks[$name] = $condition;
    if (!$condition) throw new RuntimeException("Check failed: {$name}");
};
$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO accounting_posting_rules
            (tenant_id, name, event_type, journal_template_id, priority, status)
         VALUES (:t, "Legacy invoice alias QA rule", "billing.invoice.sent", :tpl, 1000000, "active")'
    )->execute(['t' => $tenantId, 'tpl' => (int) $canonicalRules[0]['journal_template_id']]);
    $legacyRuleId = (int) $pdo->lastInsertId();

    $event = [
        'entity_id' => (int) $prior['entity_id'],
        'event_type' => 'ar.invoice.issued',
        'source_module' => 'sim_alias_probe',
        'source_record_id' => 'sim:invoice-alias:' . bin2hex(random_bytes(8)),
        'event_date' => (string) $prior['event_date'],
        'payload' => $payload,
    ];
    $context = ['payload' => $payload, 'event' => $event];
    $preferred = postingEngineFindRule($pdo, $tenantId, (int) $event['entity_id'], 'ar.invoice.issued', $context);
    $expect('canonical_rule_preferred', (int) ($preferred['id'] ?? 0) === $canonicalRuleId);
    $pdo->prepare('UPDATE accounting_posting_rules SET status = "archived" WHERE id = :id AND tenant_id = :t')
        ->execute(['id' => $canonicalRuleId, 't' => $tenantId]);
    $fallback = postingEngineFindRule($pdo, $tenantId, (int) $event['entity_id'], 'ar.invoice.issued', $context);
    $expect('legacy_rule_available_to_existing_tenant', (int) ($fallback['id'] ?? 0) === $legacyRuleId);
    $pdo->prepare('UPDATE accounting_posting_rules SET status = "active" WHERE id = :id AND tenant_id = :t')
        ->execute(['id' => $canonicalRuleId, 't' => $tenantId]);

    $pdo->prepare(
        'INSERT INTO accounting_events
            (tenant_id, entity_id, event_type, source_module, source_record_id,
             event_date, payload, status, journal_entry_id, posted_at)
         VALUES (:t, :e, "billing.invoice.sent", :sm, :sr,
                 :ed, :pl, "posted", :je, NOW())'
    )->execute([
        't' => $tenantId,
        'e' => $event['entity_id'],
        'sm' => $event['source_module'],
        'sr' => $event['source_record_id'],
        'ed' => $event['event_date'],
        'pl' => json_encode($payload, JSON_THROW_ON_ERROR),
        'je' => (int) $prior['journal_entry_id'],
    ]);
    $legacyEventId = (int) $pdo->lastInsertId();
    $count = $pdo->prepare('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = :t');
    $count->execute(['t' => $tenantId]);
    $journalsBefore = (int) $count->fetchColumn();
    $replay = accountingProcessEvent($tenantId, $event);
    $count->execute(['t' => $tenantId]);
    $expect('old_posted_event_reused', ($replay['status'] ?? null) === 'posted'
        && !empty($replay['idempotent_replay'])
        && (int) ($replay['event_id'] ?? 0) === $legacyEventId
        && (int) ($replay['journal_entry_id'] ?? 0) === (int) $prior['journal_entry_id']);
    $expect('no_duplicate_journal', (int) $count->fetchColumn() === $journalsBefore);

    $changed = $event;
    $changed['payload']['alias_probe_change'] = true;
    $conflicted = false;
    try {
        accountingProcessEvent($tenantId, $changed);
    } catch (AccountingEventConflictException $e) {
        $conflicted = true;
    }
    $expect('changed_legacy_payload_rejected', $conflicted);

    $pdo->prepare(
        'INSERT INTO accounting_events
            (tenant_id, entity_id, event_type, source_module, source_record_id,
             event_date, payload, status, journal_entry_id, posted_at)
         VALUES (:t, :e, "ar.invoice.issued", :sm, :sr,
                 :ed, :pl, "posted", :je, NOW())'
    )->execute([
        't' => $tenantId,
        'e' => $event['entity_id'],
        'sm' => $event['source_module'],
        'sr' => $event['source_record_id'],
        'ed' => $event['event_date'],
        'pl' => json_encode($payload, JSON_THROW_ON_ERROR),
        'je' => (int) $prior['journal_entry_id'],
    ]);
    $canonicalEventId = (int) $pdo->lastInsertId();
    $conflicted = false;
    try {
        accountingProcessEvent($tenantId, $event);
    } catch (AccountingEventConflictException $e) {
        $conflicted = true;
    }
    $expect('dual_event_rows_require_review', $conflicted);
    $pdo->prepare('DELETE FROM accounting_events WHERE id = :id AND tenant_id = :t')
        ->execute(['id' => $canonicalEventId, 't' => $tenantId]);

    $pdo->prepare('UPDATE accounting_events SET status = "failed" WHERE id = :id AND tenant_id = :t')
        ->execute(['id' => $legacyEventId, 't' => $tenantId]);
    $conflicted = false;
    try {
        accountingProcessEvent($tenantId, $event);
    } catch (AccountingEventConflictException $e) {
        $conflicted = true;
    }
    $expect('unposted_legacy_event_requires_review', $conflicted);
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

echo json_encode(['checks' => $checks, 'passed' => count(array_filter($checks))], JSON_PRETTY_PRINT) . "\n";
