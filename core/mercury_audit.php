<?php
/**
 * Mercury audit helpers.
 *
 * Mercury is a Treasury rail. These helpers keep payment, recipient,
 * connection, and reconciliation events on the shared platform audit writer
 * while avoiding sensitive ciphertext in before/after snapshots.
 */
declare(strict_types=1);

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/db.php';

function mercuryAuditObjectType(string $event): string
{
    if (str_contains($event, '.payment.')) return 'mercury_payment';
    if (str_contains($event, '.funding_default.')) return 'mercury_connection';
    if (str_contains($event, '.recipient.') || str_contains($event, '.sweep_destination.')) {
        return 'mercury_recipient';
    }
    if (str_contains($event, '.connection.')) return 'mercury_connection';
    if (str_contains($event, '.reconciliation.')) return 'mercury_reconciliation';
    return 'mercury';
}

function mercuryAuditLogWrite(
    int $tenantId,
    ?int $actorUserId,
    string $event,
    ?int $targetId = null,
    array $meta = [],
    array $opts = []
): void {
    platformAuditLogWrite($tenantId, $actorUserId, $event, $targetId, $meta, array_merge([
        'object_type' => mercuryAuditObjectType($event),
        'source' => $meta['source'] ?? 'treasury',
    ], $opts));
}

function mercuryAuditPaymentInstructionRow(int $tenantId, int $instructionId): ?array
{
    try {
        $stmt = getDB()->prepare(
            'SELECT * FROM payment_instructions WHERE tenant_id = :t AND id = :id LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId, 'id' => $instructionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $_) {
        return null;
    }
}

function mercuryAuditRecipientRow(int $tenantId, int $recipientId): ?array
{
    try {
        $stmt = getDB()->prepare(
            'SELECT * FROM mercury_recipients WHERE tenant_id = :t AND id = :id LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId, 'id' => $recipientId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;

        try {
            $bm = getDB()->prepare(
                'SELECT id, account_number_last4, account_type, nickname, is_default, deleted_at
                   FROM mercury_recipient_bank_methods
                  WHERE tenant_id = :t AND recipient_id = :id
                  ORDER BY is_default DESC, id ASC'
            );
            $bm->execute(['t' => $tenantId, 'id' => $recipientId]);
            $row['bank_methods'] = $bm->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $_) {
            $row['bank_methods'] = [];
        }
        try {
            $map = getDB()->prepare(
                'SELECT id, mercury_id, mercury_kind, pushed_at, last_synced_at, last_sync_error
                   FROM mercury_recipient_mappings
                  WHERE tenant_id = :t AND recipient_id = :id
                  ORDER BY mercury_kind ASC, id ASC'
            );
            $map->execute(['t' => $tenantId, 'id' => $recipientId]);
            $row['mercury_mappings'] = $map->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $_) {
            $row['mercury_mappings'] = [];
        }
        return $row;
    } catch (\Throwable $_) {
        return null;
    }
}

function mercuryAuditConnectionRow(int $tenantId): ?array
{
    try {
        $stmt = getDB()->prepare(
            'SELECT * FROM mercury_connections WHERE tenant_id = :t LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        unset($row['api_token_ct']);
        return $row;
    } catch (\Throwable $_) {
        return null;
    }
}
