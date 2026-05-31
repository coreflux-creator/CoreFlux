<?php
/**
 * /api/ai/dashboard.php — AI Reviewer Dashboard data feed (Slice 5).
 *
 *   GET — returns a single envelope:
 *       {
 *         open_exceptions: {count, recent: [...]},
 *         pending_approvals: {count, recent: [...]},
 *         recent_drafts: {count, recent: [...]},     // AI-drafted JEs
 *         counts_by_severity: {low, medium, high, critical}
 *       }
 *
 *   The dashboard is the reviewer cockpit — one page that surfaces
 *   everything the AI gateway has parked for human attention.
 *
 *   RBAC: `ai.audit.view` (Slice 1 admin permission). Slice 5 adds
 *   `accounting.review` as the module-specific reviewer permission;
 *   both grant read access here.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('method not allowed', 405);

// Either ai.audit.view OR accounting.review opens this page.
if (!rbac_legacy_can($user, 'ai.audit.view') && !rbac_legacy_can($user, 'accounting.review')) {
    api_error('Forbidden', 403);
}

$pdo = getDB();

// ── Open exceptions ────────────────────────────────────────────────
$openExceptionsCount = 0;
$openExceptions      = [];
$bySeverity          = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM accounting_exceptions
          WHERE tenant_id = :t AND status = 'open'"
    );
    $stmt->execute(['t' => $tid]);
    $openExceptionsCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, exception_type, severity, summary, related_ref_type,
                related_ref_id, workflow_run_id, created_at
           FROM accounting_exceptions
          WHERE tenant_id = :t AND status = 'open'
          ORDER BY
              FIELD(severity, 'critical','high','medium','low'),
              id DESC
          LIMIT 10"
    );
    $stmt->execute(['t' => $tid]);
    $openExceptions = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($openExceptions as &$r) { $r['id'] = (int) $r['id']; } unset($r);

    $stmt = $pdo->prepare(
        "SELECT severity, COUNT(*) c
           FROM accounting_exceptions
          WHERE tenant_id = :t AND status = 'open'
          GROUP BY severity"
    );
    $stmt->execute(['t' => $tid]);
    foreach (($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $row) {
        if (isset($bySeverity[$row['severity']])) {
            $bySeverity[$row['severity']] = (int) $row['c'];
        }
    }
} catch (\Throwable $e) { /* schema-not-ready tolerated */ }

// ── Pending workflow approvals ─────────────────────────────────────
$pendingApprovalsCount = 0;
$pendingApprovals      = [];
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM workflow_approvals
          WHERE tenant_id = :t AND status = 'pending'"
    );
    $stmt->execute(['t' => $tid]);
    $pendingApprovalsCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT a.id, a.workflow_run_id, a.node_name, a.approval_type,
                a.risk_level, a.assigned_to_role, a.created_at,
                w.graph_name
           FROM workflow_approvals a
           LEFT JOIN workflow_runs w
                  ON w.id = a.workflow_run_id AND w.tenant_id = a.tenant_id
          WHERE a.tenant_id = :t AND a.status = 'pending'
          ORDER BY a.risk_level DESC, a.id DESC
          LIMIT 10"
    );
    $stmt->execute(['t' => $tid]);
    $pendingApprovals = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($pendingApprovals as &$r) {
        $r['id']         = (int) $r['id'];
        $r['risk_level'] = (int) $r['risk_level'];
    } unset($r);
} catch (\Throwable $e) {}

// ── AI-drafted JEs (recent) ────────────────────────────────────────
$recentDraftsCount = 0;
$recentDrafts      = [];
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM accounting_journal_entries
          WHERE tenant_id = :t
            AND status = 'draft'
            AND source_ref_type IN ('ai_workflow','workflow_run')"
    );
    $stmt->execute(['t' => $tid]);
    $recentDraftsCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, je_number, entity_id, period_id, posting_date,
                total_debit, total_credit, memo, source_ref_type,
                created_at
           FROM accounting_journal_entries
          WHERE tenant_id = :t
            AND status = 'draft'
            AND source_ref_type IN ('ai_workflow','workflow_run')
          ORDER BY id DESC
          LIMIT 10"
    );
    $stmt->execute(['t' => $tid]);
    $recentDrafts = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($recentDrafts as &$r) {
        $r['id']           = (int) $r['id'];
        $r['entity_id']    = (int) $r['entity_id'];
        $r['period_id']    = (int) $r['period_id'];
        $r['total_debit']  = (float) $r['total_debit'];
        $r['total_credit'] = (float) $r['total_credit'];
    } unset($r);
} catch (\Throwable $e) {}

api_ok([
    'open_exceptions'    => ['count' => $openExceptionsCount,   'recent' => $openExceptions],
    'pending_approvals'  => ['count' => $pendingApprovalsCount, 'recent' => $pendingApprovals],
    'recent_drafts'      => ['count' => $recentDraftsCount,     'recent' => $recentDrafts],
    'counts_by_severity' => $bySeverity,
]);
