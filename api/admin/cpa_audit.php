<?php
/**
 * /api/admin/cpa_audit.php — CPA-portfolio-scoped audit feed.
 *
 *   GET ?since=YYYY-MM-DD&action=…&limit=200
 *
 * Surfaces every row from `cross_tenant_accounting_audit` AND
 * `membership_audit` where either the acting tenant OR the left/right
 * tenant is in the caller's CPA portfolio (client tenants linked to any
 * firm they belong to).
 *
 * Unlike `/api/admin/cross_tenant_audit.php` which is a platform-wide
 * surface gated to master_admin/tenant_admin, this endpoint:
 *   - Allows ANY firm-side persona (cpa / cpa_partner / cpa_staff /
 *     bookkeeper / client_advisor) — the portfolio resolver already
 *     enforces the membership constraint.
 *   - Unions accounting audits + membership audits so a CPA partner
 *     sees module-grant changes alongside JE/payment events.
 *   - Returns zero rows when the user has no firm memberships — no 403.
 *
 * tenant-leak-allow: cross-tenant by design; the portfolio resolver
 * scopes to the caller's own firm memberships above.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/rbac/cpa_firms.php';

$ctx    = api_require_auth(false);
$userId = (int) ($ctx['user']['id'] ?? 0);
if (!$userId) api_error('Authentication required', 401);

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

try {
    $pdo->query('SELECT 1 FROM cpa_firm_client_links LIMIT 0');
} catch (\Throwable $_) {
    // Migration 100 not applied — return empty, not 503, so a fresh
    // tenant with no CPA scope doesn't error-banner.
    api_ok(['rows' => [], 'tenant_ids' => [], 'count' => 0, 'limit' => 0]);
}

$clientIds = CpaFirmService::linkedClientTenantIdsForUser($userId);
if (!$clientIds) {
    api_ok(['rows' => [], 'tenant_ids' => [], 'count' => 0, 'limit' => 0]);
}

$since  = (string) api_query('since',  '');
$filter = (string) api_query('action', '');
$limit  = max(1, min(500, (int) api_query('limit', 200)));

if ($since !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
    api_error("Invalid 'since' date", 422);
}

// Build the IN-list of tenant ids. We splice integers directly because the
// list comes from a trusted PHP integer cast in the resolver, not user input.
$idsCsv = implode(',', array_map('intval', $clientIds));

// ── Branch 1: accounting cross-tenant audit ────────────────────────────────
$rowsA = [];
try {
    $sqlA = "SELECT 'accounting' AS source, a.id, a.acting_tenant_id, a.actor_user_id,
                   a.actor_label, a.left_tenant_id, a.right_tenant_id,
                   a.left_entity_id, a.right_entity_id,
                   a.action, a.payload, a.occurred_at,
                   lt.name AS left_tenant_name, rt.name AS right_tenant_name,
                   at.name AS acting_tenant_name
              FROM cross_tenant_accounting_audit a
         LEFT JOIN tenants lt ON lt.id = a.left_tenant_id
         LEFT JOIN tenants rt ON rt.id = a.right_tenant_id
         LEFT JOIN tenants at ON at.id = a.acting_tenant_id
             WHERE (a.acting_tenant_id IN ($idsCsv)
                 OR a.left_tenant_id   IN ($idsCsv)
                 OR a.right_tenant_id  IN ($idsCsv))";
    $params = [];
    if ($since !== '')  { $sqlA .= ' AND a.occurred_at >= :since'; $params['since'] = $since . ' 00:00:00'; }
    if ($filter !== '') { $sqlA .= ' AND a.action = :act';         $params['act']   = $filter; }
    $sqlA .= ' ORDER BY a.occurred_at DESC LIMIT ' . $limit;
    $stA = $pdo->prepare($sqlA);
    $stA->execute($params);
    $rowsA = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $_) { /* table absent → empty branch */ }

// ── Branch 2: membership-grant audit on linked tenants ─────────────────────
$rowsB = [];
try {
    $sqlB = "SELECT 'membership' AS source, ma.id, ma.tenant_id AS acting_tenant_id,
                   ma.actor_user_id, NULL AS actor_label,
                   ma.tenant_id AS left_tenant_id, NULL AS right_tenant_id,
                   NULL AS left_entity_id, NULL AS right_entity_id,
                   ma.action, ma.detail AS payload, ma.occurred_at,
                   lt.name AS left_tenant_name, NULL AS right_tenant_name,
                   at.name AS acting_tenant_name
              FROM membership_audit ma
         LEFT JOIN tenants lt ON lt.id = ma.tenant_id
         LEFT JOIN tenants at ON at.id = ma.tenant_id
             WHERE ma.tenant_id IN ($idsCsv)";
    $paramsB = [];
    if ($since !== '')  { $sqlB .= ' AND ma.occurred_at >= :since'; $paramsB['since'] = $since . ' 00:00:00'; }
    if ($filter !== '') { $sqlB .= ' AND ma.action = :act';         $paramsB['act']   = $filter; }
    $sqlB .= ' ORDER BY ma.occurred_at DESC LIMIT ' . $limit;
    $stB = $pdo->prepare($sqlB);
    $stB->execute($paramsB);
    $rowsB = $stB->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $_) { /* table absent → empty branch */ }

// Merge + sort + cap.
$rows = array_merge($rowsA, $rowsB);
usort($rows, fn($x, $y) => strcmp((string) $y['occurred_at'], (string) $x['occurred_at']));
if (count($rows) > $limit) $rows = array_slice($rows, 0, $limit);

foreach ($rows as &$r) {
    foreach (['id','acting_tenant_id','actor_user_id','left_tenant_id','right_tenant_id','left_entity_id','right_entity_id'] as $k) {
        if (isset($r[$k]) && $r[$k] !== null) $r[$k] = (int) $r[$k];
    }
    if (!empty($r['payload'])) {
        $decoded = json_decode((string) $r['payload'], true);
        $r['payload'] = is_array($decoded) ? $decoded : null;
    }
}
unset($r);

api_ok([
    'rows'       => $rows,
    'tenant_ids' => $clientIds,
    'count'      => count($rows),
    'limit'      => $limit,
]);
