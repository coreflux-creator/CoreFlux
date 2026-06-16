<?php
/**
 * /api/staffing/readiness — Payroll & Billing readiness queues.
 *
 * Approved hours that haven't been pushed downstream yet, broken down by
 * worker (for Payroll) or by client (for Billing). Used by the
 * /modules/staffing/payroll-readiness and /billing-readiness pages.
 *
 *   GET ?action=payroll&period_start=&period_end=
 *       → { groups: [{ person_id, name, hours, cost, timesheet_ids[], status }] }
 *
 *   GET ?action=billing&period_start=&period_end=
 *       → { groups: [{ client_id, client_name, hours, revenue, placement_ids[], timesheet_ids[] }] }
 *
 *   POST ?action=mark_payroll_pushed   body: { timesheet_ids: [] }
 *   POST ?action=mark_billing_invoiced body: { timesheet_ids: [], invoice_id }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../../../core/audit.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

// Cross-module entity tenants. Timesheets live under the staffing
// module scope; People + Placements + Companies live under their own
// scopes. For a sub-tenant in `'people' => 'shared'` mode, the people
// row's tenant_id != the timesheet's tenant_id, which would silently
// kill the LEFT JOIN and surface as "Person #5 / Person #7" in the UI.
// Resolving each FK target tenant explicitly fixes both the payroll
// readiness (worker names) and the billing readiness (client names).
$peopleTid     = effectiveTenantIdForModule('people')     ?? currentTenantId();
$placementsTid = effectiveTenantIdForModule('placements') ?? currentTenantId();

if ($method === 'GET' && $action === 'payroll') {
    rbac_legacy_require_any($user, ['payroll.view', 'staffing.payroll.view']);
    $ps = (string) ($_GET['period_start'] ?? date('Y-m-d', strtotime('-14 days')));
    $pe = (string) ($_GET['period_end']   ?? date('Y-m-d'));

    // Approved timesheet headers in the window that haven't been flipped
    // to payroll_ready / locked yet.
    $rows = scopedQuery(
        "SELECT t.id, t.person_id, t.period_start, t.period_end, t.total_hours, t.status,
                p.first_name, p.last_name, p.email_primary
           FROM staffing_timesheets t
           LEFT JOIN people p ON p.id = t.person_id AND p.tenant_id = :people_tid
          WHERE t.tenant_id = :tenant_id
            AND t.status = 'approved'
            AND t.period_start BETWEEN :ps AND :pe
          ORDER BY t.period_start DESC, p.last_name, p.first_name",
        ['ps' => $ps, 'pe' => $pe, 'people_tid' => $peopleTid]
    );

    // Group by person for the readiness view.
    $byPerson = [];
    foreach ($rows as $r) {
        $pid = (int) $r['person_id'];
        if (!isset($byPerson[$pid])) {
            $byPerson[$pid] = [
                'person_id'      => $pid,
                'name'           => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ('Person #' . $pid),
                'email'          => $r['email_primary'],
                'hours'          => 0.0,
                'timesheet_ids'  => [],
                'periods'        => [],
            ];
        }
        $byPerson[$pid]['hours']         += (float) $r['total_hours'];
        $byPerson[$pid]['timesheet_ids'][] = (int) $r['id'];
        $byPerson[$pid]['periods'][]      = $r['period_start'] . '→' . $r['period_end'];
    }
    api_ok(['groups' => array_values($byPerson), 'period_start' => $ps, 'period_end' => $pe]);
}

if ($method === 'GET' && $action === 'billing') {
    rbac_legacy_require_any($user, ['billing.view', 'staffing.billing.view']);
    $ps = (string) ($_GET['period_start'] ?? date('Y-m-d', strtotime('-14 days')));
    $pe = (string) ($_GET['period_end']   ?? date('Y-m-d'));

    // Approved hours grouped by client. Reads revenue from the staffing
    // reports view when available; falls back to hours-only when missing.
    try {
        $rows = scopedQuery(
            "SELECT pl.client_id, c.name AS client_name,
                    SUM(v.hours)   AS hours,
                    SUM(v.revenue) AS revenue,
                    COUNT(DISTINCT t.id) AS timesheet_count,
                    GROUP_CONCAT(DISTINCT t.id) AS timesheet_ids,
                    GROUP_CONCAT(DISTINCT pl.id) AS placement_ids
               FROM staffing_timesheets t
               JOIN time_entries te ON te.timesheet_id = t.id
               JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = :placements_tid
               LEFT JOIN staffing_clients c ON c.id = pl.client_id AND c.tenant_id = :placements_tid_c
               LEFT JOIN v_timesheet_day_fin v ON v.entry_id = te.id
              WHERE t.tenant_id = :tenant_id
                AND t.status = 'approved'
                AND t.period_start BETWEEN :ps AND :pe
              GROUP BY pl.client_id, c.name
              ORDER BY revenue DESC, hours DESC",
            ['ps' => $ps, 'pe' => $pe, 'placements_tid' => $placementsTid, 'placements_tid_c' => $placementsTid]
        );
    } catch (\Throwable $_) {
        // v_timesheet_day_fin missing — fall back without revenue.
        $rows = scopedQuery(
            "SELECT pl.client_id, c.name AS client_name,
                    SUM(te.hours)   AS hours,
                    0 AS revenue,
                    COUNT(DISTINCT t.id) AS timesheet_count,
                    GROUP_CONCAT(DISTINCT t.id) AS timesheet_ids,
                    GROUP_CONCAT(DISTINCT pl.id) AS placement_ids
               FROM staffing_timesheets t
               JOIN time_entries te ON te.timesheet_id = t.id
               JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = :placements_tid
               LEFT JOIN staffing_clients c ON c.id = pl.client_id AND c.tenant_id = :placements_tid_c
              WHERE t.tenant_id = :tenant_id
                AND t.status = 'approved'
                AND t.period_start BETWEEN :ps AND :pe
              GROUP BY pl.client_id, c.name
              ORDER BY hours DESC",
            ['ps' => $ps, 'pe' => $pe, 'placements_tid' => $placementsTid, 'placements_tid_c' => $placementsTid]
        );
    }

    foreach ($rows as &$r) {
        $r['hours']          = (float) ($r['hours'] ?? 0);
        $r['revenue']        = (float) ($r['revenue'] ?? 0);
        $r['timesheet_ids']  = array_map('intval', array_filter(explode(',', (string) $r['timesheet_ids'])));
        $r['placement_ids']  = array_map('intval', array_filter(explode(',', (string) $r['placement_ids'])));
    }
    api_ok(['groups' => $rows, 'period_start' => $ps, 'period_end' => $pe]);
}

if ($method === 'POST' && in_array($action, ['mark_payroll_pushed','mark_billing_invoiced'], true)) {
    rbac_legacy_require_any(
        $user,
        $action === 'mark_payroll_pushed'
            ? ['payroll.run.create', 'staffing.payroll.manage']
            : ['billing.invoice.draft', 'staffing.billing.manage']
    );
    $b   = api_json_body();
    $ids = array_map('intval', $b['timesheet_ids'] ?? []);
    if (!$ids) api_error('timesheet_ids required', 422);
    $target = $action === 'mark_payroll_pushed' ? 'payroll_ready' : 'billing_ready';

    $pdo = getDB();
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $upd = $pdo->prepare("UPDATE staffing_timesheets SET status = ? WHERE tenant_id = ? AND status = 'approved' AND id IN ($in)");
    $upd->execute(array_merge([$target, currentTenantId()], $ids));
    staffingReadinessAudit(
        currentTenantId(),
        (int) ($user['id'] ?? 0),
        $action === 'mark_payroll_pushed' ? 'staffing.readiness.payroll_marked' : 'staffing.readiness.billing_marked',
        [
            'target' => $target,
            'timesheet_ids' => $ids,
            'updated' => $upd->rowCount(),
            'invoice_id' => $b['invoice_id'] ?? null,
        ]
    );
    api_ok(['ok' => true, 'updated' => $upd->rowCount(), 'target' => $target]);
}

api_error('Unknown action', 404);

function staffingReadinessAudit(int $tenantId, ?int $actorUserId, string $event, array $meta): void
{
    try {
        platformAuditLogWrite(
            $tenantId,
            $actorUserId,
            $event,
            null,
            $meta,
            [
                'source' => 'staffing',
                'object_type' => 'staffing_readiness',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (\Throwable $e) {
        error_log('[staffing.readiness.audit] ' . $event . ' failed: ' . $e->getMessage());
    }
}
