<?php
/**
 * Placements API — reports (Phase A: expiring + active-by-client).
 * SPEC §6.3.
 *
 *   GET /api/placements/reports?type=expiring&days=30
 *   GET /api/placements/reports?type=active_by_client
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';

$ctx = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'placements.view');

$type = $_GET['type'] ?? '';

if ($type === 'expiring') {
    $days = max(1, (int) ($_GET['days'] ?? 30));
    $cutoff = date('Y-m-d', strtotime("+{$days} days"));
    $rows = scopedQuery(
        'SELECT p.id, p.title, p.status, p.start_date, p.end_date, p.due_date,
                p.end_client_name, p.engagement_type,
                pe.first_name, pe.last_name, pe.email_primary
         FROM placements p
         LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
         WHERE p.tenant_id = :tenant_id AND p.deleted_at IS NULL
           AND p.status IN ("active", "pending_start", "on_hold")
           AND ((p.end_date  IS NOT NULL AND p.end_date  <= :cutoff_end)
             OR (p.due_date  IS NOT NULL AND p.due_date  <= :cutoff_due))
         ORDER BY COALESCE(p.due_date, p.end_date) ASC',
        ['cutoff_end' => $cutoff, 'cutoff_due' => $cutoff]
    );
    api_ok(['rows' => $rows, 'cutoff' => $cutoff, 'days' => $days]);
}

if ($type === 'active_by_client') {
    $rows = scopedQuery(
        'SELECT COALESCE(end_client_name, "(unset)") AS end_client_name,
                COUNT(*) AS active_count
         FROM placements
         WHERE tenant_id = :tenant_id AND deleted_at IS NULL AND status = "active"
         GROUP BY end_client_name
         ORDER BY active_count DESC, end_client_name ASC'
    );
    api_ok(['rows' => $rows]);
}

api_error('Unknown report type. Use ?type=expiring or ?type=active_by_client', 400);
