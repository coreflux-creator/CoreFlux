<?php
/**
 * C4 — Evidence bundle builder (Sprint 3 / Industry Layer 1).
 *
 * Assembles the "show your work" packet attached to every AP bill:
 *   • source timesheet period IDs (so the bill links back to approved hours)
 *   • placement IDs the bill draws from
 *   • approval-trail snapshot (who approved, when)
 *   • payroll run IDs (for W-2 → Gusto cross-reference)
 *   • SHA-256 audit hash of the canonical summary
 *
 * Stored in `ap_bill_evidence_bundles`. Idempotent — rebuilds replace.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';

/**
 * Build (or rebuild) the evidence bundle for a bill.
 * @return array bundle row, or empty array if bill not found.
 */
function apBuildEvidenceBundle(int $tenantId, int $billId, ?int $actorUserId = null): array {
    $pdo = getDB();
    if (!$pdo) return [];

    // Source-of-truth lookup. Defensive — different deployments have
    // different bill schemas.
    $bill = null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM ap_bills WHERE tenant_id = :t AND id = :id LIMIT 1");
        $stmt->execute(['t' => $tenantId, 'id' => $billId]);
        $bill = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { $bill = null; }
    if (!$bill) return [];

    // Pull related references.
    $timesheetPeriodIds = _apEvidenceTimesheetPeriodIds($pdo, $tenantId, $billId);
    $placementIds       = _apEvidencePlacementIds($pdo, $tenantId, $billId);
    $approvalTrail      = _apEvidenceApprovalTrail($pdo, $tenantId, $billId);
    $payrollRunIds      = _apEvidencePayrollRunIds($pdo, $tenantId, $billId);

    $summary = [
        'bill_id'            => $billId,
        'vendor_id'          => isset($bill['vendor_id']) ? (int) $bill['vendor_id'] : null,
        'total_amount'       => (float) ($bill['total_amount'] ?? 0),
        'currency'           => (string) ($bill['currency'] ?? 'USD'),
        'bill_date'          => $bill['bill_date']  ?? null,
        'due_date'           => $bill['due_date']   ?? null,
        'timesheet_periods'  => $timesheetPeriodIds,
        'placement_ids'      => $placementIds,
        'approvers'          => array_column($approvalTrail, 'approver_user_id'),
        'payroll_run_ids'    => $payrollRunIds,
        'built_at'           => date('Y-m-d H:i:s'),
    ];
    ksort($summary);
    $hash = hash('sha256', json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_SORT_KEYS));

    $upsert = $pdo->prepare(
        "INSERT INTO ap_bill_evidence_bundles
           (tenant_id, bill_id, timesheet_period_ids_json, placement_ids_json, approval_trail_json, payroll_run_ids_json, audit_hash, bundle_summary_json, built_by_user_id, built_at)
         VALUES
           (:t, :b, :tp, :pl, :at, :pr, :ah, :bs, :u, NOW())
         ON DUPLICATE KEY UPDATE
           timesheet_period_ids_json = VALUES(timesheet_period_ids_json),
           placement_ids_json        = VALUES(placement_ids_json),
           approval_trail_json       = VALUES(approval_trail_json),
           payroll_run_ids_json      = VALUES(payroll_run_ids_json),
           audit_hash                = VALUES(audit_hash),
           bundle_summary_json       = VALUES(bundle_summary_json),
           built_by_user_id          = VALUES(built_by_user_id),
           built_at                  = NOW()"
    );
    $upsert->execute([
        't'  => $tenantId,
        'b'  => $billId,
        'tp' => json_encode($timesheetPeriodIds, JSON_UNESCAPED_SLASHES),
        'pl' => json_encode($placementIds, JSON_UNESCAPED_SLASHES),
        'at' => json_encode($approvalTrail, JSON_UNESCAPED_SLASHES),
        'pr' => json_encode($payrollRunIds, JSON_UNESCAPED_SLASHES),
        'ah' => $hash,
        'bs' => json_encode($summary, JSON_UNESCAPED_SLASHES),
        'u'  => $actorUserId,
    ]);

    return [
        'bill_id'            => $billId,
        'audit_hash'         => $hash,
        'summary'            => $summary,
        'timesheet_periods'  => $timesheetPeriodIds,
        'placement_ids'      => $placementIds,
        'approval_trail'     => $approvalTrail,
        'payroll_run_ids'    => $payrollRunIds,
    ];
}

/**
 * Read the bundle for a bill. Returns array or null.
 */
function apGetEvidenceBundle(int $tenantId, int $billId): ?array {
    $pdo = getDB();
    if (!$pdo) return null;
    $stmt = $pdo->prepare(
        "SELECT * FROM ap_bill_evidence_bundles WHERE tenant_id = :t AND bill_id = :b LIMIT 1"
    );
    $stmt->execute(['t' => $tenantId, 'b' => $billId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) return null;
    return [
        'bill_id'            => (int) $row['bill_id'],
        'audit_hash'         => (string) $row['audit_hash'],
        'built_at'           => $row['built_at'],
        'summary'            => json_decode((string) ($row['bundle_summary_json'] ?? '[]'), true) ?: [],
        'timesheet_periods'  => json_decode((string) ($row['timesheet_period_ids_json'] ?? '[]'), true) ?: [],
        'placement_ids'      => json_decode((string) ($row['placement_ids_json'] ?? '[]'), true) ?: [],
        'approval_trail'     => json_decode((string) ($row['approval_trail_json'] ?? '[]'), true) ?: [],
        'payroll_run_ids'    => json_decode((string) ($row['payroll_run_ids_json'] ?? '[]'), true) ?: [],
    ];
}

/* ---------------------------------------------------------------------- */
/** @internal Find timesheet periods backing time-source lines on this bill. */
function _apEvidenceTimesheetPeriodIds(PDO $pdo, int $tenantId, int $billId): array {
    try {
        $s = $pdo->prepare(
            "SELECT DISTINCT te.period_id
               FROM ap_bill_lines bl
               JOIN ap_bills      b  ON b.id = bl.bill_id
               JOIN time_entries  te ON te.id = bl.source_ref_id
              WHERE b.tenant_id = :t AND bl.bill_id = :b
                AND bl.source_type = 'time'
                AND te.period_id IS NOT NULL"
        );
        $s->execute(['t' => $tenantId, 'b' => $billId]);
        return array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'period_id'));
    } catch (\Throwable $_) { return []; }
}

/** @internal Distinct placements drawn into bill lines. */
function _apEvidencePlacementIds(PDO $pdo, int $tenantId, int $billId): array {
    try {
        $s = $pdo->prepare(
            "SELECT DISTINCT bl.placement_id
               FROM ap_bill_lines bl
               JOIN ap_bills      b ON b.id = bl.bill_id
              WHERE b.tenant_id = :t AND bl.bill_id = :b AND bl.placement_id IS NOT NULL"
        );
        $s->execute(['t' => $tenantId, 'b' => $billId]);
        return array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'placement_id'));
    } catch (\Throwable $_) { return []; }
}

/** @internal Approval rows (ap_bill_approvals lacks tenant_id; scope via bill). */
function _apEvidenceApprovalTrail(PDO $pdo, int $tenantId, int $billId): array {
    try {
        $s = $pdo->prepare(
            "SELECT a.approver_user_id, a.state, a.created_at, a.updated_at
               FROM ap_bill_approvals a
               JOIN ap_bills b ON b.id = a.bill_id
              WHERE b.tenant_id = :t AND a.bill_id = :b
              ORDER BY a.created_at ASC"
        );
        $s->execute(['t' => $tenantId, 'b' => $billId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
}

/** @internal Payroll runs whose pay-period overlaps any time-source line. */
function _apEvidencePayrollRunIds(PDO $pdo, int $tenantId, int $billId): array {
    try {
        $s = $pdo->prepare(
            "SELECT DISTINCT pr.id
               FROM ap_bill_lines bl
               JOIN ap_bills          b   ON b.id = bl.bill_id
               JOIN time_entries      te  ON te.id = bl.source_ref_id
               JOIN payroll_pay_periods pp ON te.work_date BETWEEN pp.period_start AND pp.period_end
                                          AND pp.tenant_id = b.tenant_id
               JOIN payroll_runs      pr  ON pr.pay_period_id = pp.id AND pr.tenant_id = b.tenant_id
              WHERE b.tenant_id = :t AND bl.bill_id = :b
                AND bl.source_type = 'time'"
        );
        $s->execute(['t' => $tenantId, 'b' => $billId]);
        return array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'id'));
    } catch (\Throwable $_) { return []; }
}
