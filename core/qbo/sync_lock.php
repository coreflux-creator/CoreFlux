<?php
/** MySQL advisory locks prevent manual and scheduled QBO runs overlapping. */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function qboSyncLockName(int $tenantId, string $workflow): string
{
    $safeWorkflow = preg_replace('/[^a-z0-9_:-]/i', '_', $workflow) ?: 'sync';
    return substr("coreflux:qbo:{$tenantId}:{$safeWorkflow}", 0, 64);
}

function qboSyncLockAcquire(int $tenantId, string $workflow): string
{
    $name = qboSyncLockName($tenantId, $workflow);
    $stmt = getDB()->prepare('SELECT GET_LOCK(:name, 0)');
    $stmt->execute(['name' => $name]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new \RuntimeException('This QuickBooks workflow is already running. Try again shortly.');
    }
    return $name;
}

function qboSyncLockRelease(string $name): void
{
    try {
        $stmt = getDB()->prepare('SELECT RELEASE_LOCK(:name)');
        $stmt->execute(['name' => $name]);
    } catch (\Throwable $e) {
        // Connection-scoped MySQL locks are released automatically if the
        // request exits; never mask the actual sync result with cleanup noise.
    }
}
