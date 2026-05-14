<?php
/**
 * Module Emission Discipline Log (Phase 2a — 2026-02-XX).
 *
 * Records every time a module's posting path falls back to a legacy
 * direct accountingPostJe() instead of routing through the event engine
 * (accountingProcessEvent). Designed to be the telemetry that proves
 * "zero fallback fires for N consecutive days" before Phase 2a step 5
 * flips the fallback to a hard error.
 *
 * Never throws. Audit-write is non-critical.
 *
 * Usage:
 *     require_once __DIR__ . '/../../../core/module_emission_discipline.php';
 *     moduleEmissionDisciplineLog('ap', 'ap.bill.approved', [
 *         'bill_id'      => $id,
 *         'je_id'        => $res['je_id'],
 *         'event_error'  => $eventError,
 *         'event_status' => $eventResult['status'] ?? null,
 *     ]);
 *
 * Query the trail:
 *     SELECT source_module, event_type, COUNT(*), MAX(created_at)
 *       FROM module_emission_discipline_log
 *      WHERE tenant_id = ? AND created_at > NOW() - INTERVAL 7 DAY
 *      GROUP BY source_module, event_type;
 *
 * Migration 044 creates the table (idempotent).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function moduleEmissionDisciplineLog(string $sourceModule, string $eventType, array $context = []): void {
    try {
        $pdo = getDB();
        if (!$pdo) return;

        $tenantId = function_exists('currentTenantId') ? (int) currentTenantId() : 0;
        $userId   = $_SESSION['user']['id'] ?? null;

        $stmt = $pdo->prepare(
            'INSERT INTO module_emission_discipline_log
               (tenant_id, source_module, event_type, context, created_by_user_id)
             VALUES (:t, :sm, :et, :cx, :u)'
        );
        $stmt->execute([
            't'  => $tenantId,
            'sm' => $sourceModule,
            'et' => $eventType,
            'cx' => json_encode($context, JSON_UNESCAPED_SLASHES),
            'u'  => $userId ? (int) $userId : null,
        ]);
    } catch (\Throwable $e) {
        // Migration may not have run yet — log to error_log as a backup
        // signal so we don't lose violations even when the audit table
        // is missing.
        error_log('[module-emission-discipline] ' . $sourceModule . '/' . $eventType
                . ' fallback fired (table missing? ' . $e->getMessage() . ')');
    }
}
