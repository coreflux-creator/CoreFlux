<?php
/** First-class artifact projection for bank reconciliations. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/ai/artifacts.php';

function accountingReconciliationSyncArtifact(
    int $tenantId,
    int $reconciliationId,
    ?int $actorUserId = null,
    ?string $targetStatus = null,
    ?array $packet = null
): array {
    $stmt = getDB()->prepare(
        'SELECT * FROM accounting_reconciliations
          WHERE tenant_id = :tenant_id AND id = :id LIMIT 1'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'id' => $reconciliationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException("Reconciliation {$reconciliationId} not found");

    $payload = [
        'reconciliation_id' => $reconciliationId,
        'bank_account_id' => (int) $row['bank_account_id'],
        'period_end' => $row['period_end'],
        'statement_balance' => (float) $row['statement_balance'],
        'gl_balance' => (float) $row['gl_balance'],
        'difference' => (float) $row['difference'],
        'status' => $row['status'],
        'notes' => $row['notes'] ?? null,
        'ai_narrative' => $row['ai_narrative'] ?? null,
    ];
    if ($packet !== null) {
        $payload['packet'] = [
            'bank_account' => $packet['bank_account'] ?? null,
            'totals' => $packet['totals'] ?? null,
            'matched' => $packet['matched'] ?? [],
            'unmatched' => $packet['unmatched'] ?? [],
        ];
    }

    $artifact = artifactEnsureForSource(
        $tenantId,
        'accounting_reconciliation',
        'accounting',
        'accounting_reconciliation',
        $reconciliationId,
        [
            'title' => "Bank reconciliation #{$reconciliationId} through {$row['period_end']}",
            'payload' => $payload,
            'created_by_user_id' => $actorUserId,
            'initial_status' => 'draft',
        ]
    );
    getDB()->prepare(
        'UPDATE accounting_reconciliations SET artifact_id = :artifact_id
          WHERE tenant_id = :tenant_id AND id = :id'
    )->execute([
        'artifact_id' => $artifact['id'],
        'tenant_id' => $tenantId,
        'id' => $reconciliationId,
    ]);
    artifactUpdate($tenantId, (string) $artifact['id'], ['payload' => $payload], $actorUserId);
    artifactLink(
        $tenantId,
        (string) $artifact['id'],
        'represents',
        null,
        'accounting_reconciliations',
        $reconciliationId,
        [],
        $actorUserId
    );
    if ($targetStatus !== null) {
        $artifact = artifactTransitionTo(
            $tenantId,
            (string) $artifact['id'],
            $targetStatus,
            $actorUserId,
            null,
            ['domain_status' => $row['status']]
        );
    }
    return $artifact;
}
