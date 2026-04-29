<?php
/**
 * People Module — Audit logger
 *
 * Thin wrapper around the platform audit_log mechanism. Modules call
 * peopleAudit('people.created', ['id' => 123, ...]) and the helper writes
 * a row to `audit_log` (or, if the table does not exist yet on a fresh
 * install, falls back to error_log). All people.* event slugs are declared
 * in /app/modules/people/manifest.php.
 *
 * SPEC: /app/modules/people/SPEC.md §7
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';

function peopleAudit(string $event, array $meta = [], ?int $targetId = null): void
{
    $pdo = getDB();
    if (!$pdo) {
        error_log("[people.audit] {$event} target={$targetId} meta=" . json_encode($meta));
        return;
    }

    $row = [
        'tenant_id'   => currentTenantId(),
        'actor_user_id' => $_SESSION['user']['id'] ?? null,
        'event'       => $event,
        'target_id'   => $targetId,
        'meta_json'   => $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        'request_id'  => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
    ];

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log
             (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, request_id, created_at)
             VALUES (:tenant_id, :actor_user_id, :event, :target_id, :meta_json, :ip_address, :request_id, NOW())'
        );
        $stmt->execute($row);
    } catch (\Throwable $e) {
        // Audit table may not exist on fresh install; never block the
        // calling request because audit failed.
        error_log("[people.audit] db-write-failed: " . $e->getMessage()
                . " event={$event} meta=" . json_encode($meta));
    }
}
