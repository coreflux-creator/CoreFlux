<?php
declare(strict_types=1);

function simDocumentForEvent(string $eventType): ?array {
    return match ($eventType) {
        'billing.invoice.sent', 'ar.invoice.issued' => ['billing_invoices', 'invoice_id'],
        'ap.bill.approved' => ['ap_bills', 'bill_id'],
        default => null,
    };
}

function simLinkPostedDocument(int $tenantId, string $eventType, array $payload, int $jeId): void {
    $document = simDocumentForEvent($eventType);
    if ($document === null) return;

    [$table, $idField] = $document;
    $id = (int) ($payload[$idField] ?? 0);
    if ($id <= 0) throw new \RuntimeException("{$eventType} requires {$idField} to link its posted journal entry");

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $journal = $pdo->prepare(
            'SELECT entity_id, status FROM accounting_journal_entries WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE'
        );
        $journal->execute(['tenant_id' => $tenantId, 'id' => $jeId]);
        $posted = $journal->fetch(\PDO::FETCH_ASSOC);
        if (!$posted || $posted['status'] !== 'posted') throw new \RuntimeException('Source journal entry is not posted');
        $entityId = (int) $posted['entity_id'];

        $stmt = $pdo->prepare("SELECT journal_entry_id, entity_id FROM {$table} WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE");
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$existing) throw new \RuntimeException("{$eventType} source document {$id} was not found");
        $linkedJeId = (int) ($existing['journal_entry_id'] ?? 0);
        if ($linkedJeId !== 0 && $linkedJeId !== $jeId) {
            throw new \RuntimeException("{$eventType} source document {$id} is linked to a different journal entry");
        }
        $linkedEntityId = (int) ($existing['entity_id'] ?? 0);
        if ($linkedEntityId !== 0 && $linkedEntityId !== $entityId) {
            throw new \RuntimeException("{$eventType} source document {$id} belongs to a different entity");
        }
        if ($linkedJeId === 0 || $linkedEntityId === 0) {
            $pdo->prepare("UPDATE {$table} SET journal_entry_id = :je_id, entity_id = :entity_id WHERE tenant_id = :tenant_id AND id = :id")
                ->execute(['je_id' => $jeId, 'entity_id' => $entityId, 'tenant_id' => $tenantId, 'id' => $id]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
