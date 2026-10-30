<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/tenant_scope.php';
require_once __DIR__ . '/lib/document_links.php';

$opts = getopt('', ['tenant:', 'apply']);
$tenantId = (int) ($opts['tenant'] ?? 0);
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php sim/repair_document_links.php --tenant=ID [--apply]\n");
    exit(2);
}

$pdo = getDB();
$check = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
$check->execute(['id' => $tenantId]);
if ((int) $check->fetchColumn() !== 1) {
    fwrite(STDERR, "Refusing to repair a non-simulation tenant.\n");
    exit(3);
}
setRequestTenantId($tenantId);

$events = $pdo->prepare(
    'SELECT e.id, e.event_type, e.payload, e.journal_entry_id, j.total_debit, j.entity_id
       FROM accounting_events e
       JOIN accounting_journal_entries j
         ON j.tenant_id = e.tenant_id AND j.id = e.journal_entry_id
      WHERE e.tenant_id = :tenant_id AND e.status = "posted" AND j.status = "posted"
        AND j.source_module = e.source_module
        AND e.source_record_id LIKE "sim:%"
        AND e.event_type IN ("billing.invoice.sent", "ar.invoice.issued", "ap.bill.approved")
      ORDER BY e.id'
);
$events->execute(['tenant_id' => $tenantId]);
$pending = [];
foreach ($events->fetchAll(PDO::FETCH_ASSOC) as $event) {
    $payload = json_decode((string) $event['payload'], true);
    if (!is_array($payload)) throw new RuntimeException("Event {$event['id']} has invalid payload");
    [$table, $idField] = simDocumentForEvent((string) $event['event_type']);
    $documentId = (int) ($payload[$idField] ?? 0);
    if ($documentId <= 0) throw new RuntimeException("Event {$event['id']} has no {$idField}");
    $doc = $pdo->prepare("SELECT journal_entry_id, entity_id, total FROM {$table} WHERE tenant_id = :tenant_id AND id = :id");
    $doc->execute(['tenant_id' => $tenantId, 'id' => $documentId]);
    $document = $doc->fetch(PDO::FETCH_ASSOC);
    if (!$document) throw new RuntimeException("Event {$event['id']} source document not found");
    if (round((float) $document['total'], 2) !== round((float) $event['total_debit'], 2)) {
        throw new RuntimeException("Event {$event['id']} document and journal amounts differ");
    }
    $linked = (int) ($document['journal_entry_id'] ?? 0);
    $jeId = (int) $event['journal_entry_id'];
    if ($linked !== 0 && $linked !== $jeId) {
        throw new RuntimeException("Event {$event['id']} source document has a conflicting journal entry");
    }
    $linkedEntity = (int) ($document['entity_id'] ?? 0);
    if ($linkedEntity !== 0 && $linkedEntity !== (int) $event['entity_id']) {
        throw new RuntimeException("Event {$event['id']} source document belongs to another entity");
    }
    if ($linked === 0 || $linkedEntity === 0) $pending[] = [$event['event_type'], $payload, $jeId, $documentId];
}

printf("Simulation tenant %d: %d missing document or entity links.\n", $tenantId, count($pending));
if (!isset($opts['apply'])) {
    echo "Dry run only. Add --apply to link these posted documents.\n";
    exit(0);
}
foreach ($pending as [$type, $payload, $jeId, $documentId]) {
    simLinkPostedDocument($tenantId, $type, $payload, $jeId);
    printf("Linked %s #%d to JE #%d.\n", $type, $documentId, $jeId);
}
