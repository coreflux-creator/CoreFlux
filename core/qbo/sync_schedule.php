<?php
/** Shared checkpoint helpers for the 15-minute QBO workers. */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function qboSyncScheduleEnsure(): void
{
    getDB()->exec(
        'CREATE TABLE IF NOT EXISTS tenant_qbo_sync_state (
            tenant_id       INT UNSIGNED NOT NULL,
            workflow        VARCHAR(64)  NOT NULL,
            last_success_at DATETIME     NULL,
            last_error      VARCHAR(500) NULL,
            updated_at      DATETIME     NOT NULL,
            PRIMARY KEY (tenant_id, workflow)
        )'
    );
}

function qboSyncScheduleSince(int $tenantId, string $workflow, int $overlapSeconds = 300): string
{
    $stmt = getDB()->prepare(
        'SELECT last_success_at FROM tenant_qbo_sync_state WHERE tenant_id = :t AND workflow = :w'
    );
    $stmt->execute(['t' => $tenantId, 'w' => $workflow]);
    $last = $stmt->fetchColumn();
    if (!$last) return '';
    return date('Y-m-d\TH:i:s', max(0, strtotime((string) $last) - max(0, $overlapSeconds)));
}

function qboSyncScheduleMark(int $tenantId, string $workflow, bool $ok, string $startedAt, ?string $error = null): void
{
    getDB()->prepare(
        'INSERT INTO tenant_qbo_sync_state
            (tenant_id, workflow, last_success_at, last_error, updated_at)
         VALUES (:t, :w, :last, :err, NOW())
         ON DUPLICATE KEY UPDATE
            last_success_at = IF(:ok = 1, :last2, last_success_at),
            last_error = :err2,
            updated_at = NOW()'
    )->execute([
        't' => $tenantId,
        'w' => $workflow,
        'last' => $ok ? $startedAt : null,
        'last2' => $startedAt,
        'ok' => $ok ? 1 : 0,
        'err' => $ok ? null : substr((string) $error, 0, 500),
        'err2' => $ok ? null : substr((string) $error, 0, 500),
    ]);
}
