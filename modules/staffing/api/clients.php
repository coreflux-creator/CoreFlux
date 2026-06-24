<?php
/**
 * /api/staffing/clients — Clients CRUD.
 *
 *   GET    list   ?q=&status=&limit=
 *   GET    get    ?id=N
 *   POST   create body: { name, legal_name, industry, primary_contact_*, billing_*, status, payment_terms_days, notes }
 *   POST   update body: { id, ...fields }
 *   POST   delete body: { id } → status=closed (soft delete)
 *
 *   GET    stats  ?id=N → { active_placements, mtd_revenue, ar_outstanding }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/client_audit.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tenantId = (int) ($ctx['tenant_id'] ?? currentTenantId());
$actorUserId = isset($user['id']) ? (int) $user['id'] : null;
$method = api_method();
$action = $_GET['action'] ?? 'list';

if ($method === 'GET' && $action === 'list') {
    rbac_legacy_require($user, 'staffing.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['status'])) { $where[] = 'status = :s'; $params['s'] = $_GET['status']; }
    if (!empty($_GET['q']))      {
        // Distinct placeholders required by PDO_MYSQL native prepares.
        $where[]      = '(name LIKE :q OR legal_name LIKE :q2 OR primary_contact_email LIKE :q3)';
        $params['q']  = '%' . $_GET['q'] . '%';
        $params['q2'] = $params['q'];
        $params['q3'] = $params['q'];
    }
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $sql = "SELECT c.id, c.name, c.legal_name, c.industry, c.status, c.payment_terms_days,
                   c.primary_contact_name, c.primary_contact_email,
                   c.billing_city, c.billing_state, c.billing_country,
                   c.msa_status, c.created_at,
                   COALESCE(p.cnt, 0) AS active_placements
              FROM staffing_clients c
              LEFT JOIN (
                  SELECT tenant_id, client_id, COUNT(*) AS cnt
                    FROM placements
                   WHERE status = 'active'
                   GROUP BY tenant_id, client_id
              ) p ON p.tenant_id = c.tenant_id AND p.client_id = c.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY c.name
             LIMIT " . $limit;
    api_ok(['rows' => scopedQuery($sql, $params)]);
}

if ($method === 'GET' && $action === 'get') {
    rbac_legacy_require($user, 'staffing.view');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $row = scopedFind('SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    api_ok(['client' => $row]);
}

if ($method === 'POST' && $action === 'create') {
    rbac_legacy_require($user, 'staffing.clients.manage');
    $b = api_json_body();
    $name = trim((string) ($b['name'] ?? ''));
    if ($name === '') api_error('name required', 422);

    // Reject duplicate names.
    $existing = scopedFind('SELECT id FROM staffing_clients WHERE tenant_id = :tenant_id AND name = :n', ['n' => $name]);
    if ($existing) api_error("Client '{$name}' already exists", 409, ['existing_id' => $existing['id']]);

    $id = scopedInsert('staffing_clients', [
        'name'                  => $name,
        'legal_name'            => $b['legal_name']            ?? null,
        'industry'              => $b['industry']              ?? null,
        'primary_contact_name'  => $b['primary_contact_name']  ?? null,
        'primary_contact_email' => $b['primary_contact_email'] ?? null,
        'primary_contact_phone' => $b['primary_contact_phone'] ?? null,
        'billing_address_line1' => $b['billing_address_line1'] ?? null,
        'billing_address_line2' => $b['billing_address_line2'] ?? null,
        'billing_city'          => $b['billing_city']          ?? null,
        'billing_state'         => $b['billing_state']         ?? null,
        'billing_postal_code'   => $b['billing_postal_code']   ?? null,
        'billing_country'       => $b['billing_country']       ?? 'US',
        'payment_terms_days'    => isset($b['payment_terms_days']) ? (int) $b['payment_terms_days'] : 30,
        'status'                => $b['status']                ?? 'active',
        'notes'                 => $b['notes']                 ?? null,
        'msa_status'            => $b['msa_status']            ?? 'none',
    ]);
    $client = scopedFind('SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    staffingClientAudit($tenantId, $actorUserId, 'staffing.client.created', $id, [
        'source' => 'staffing_clients_api',
        'after' => staffingClientAuditSnapshot($client ?: ['id' => $id, 'name' => $name]),
    ]);
    api_ok(['client' => $client]);
}

if ($method === 'POST' && $action === 'update') {
    rbac_legacy_require($user, 'staffing.clients.manage');
    $b  = api_json_body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $existing = scopedFind('SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :i', ['i' => $id]);
    if (!$existing) api_error('Not found', 404);

    $allowed = [
        'name','legal_name','industry','primary_contact_name','primary_contact_email','primary_contact_phone',
        'billing_address_line1','billing_address_line2','billing_city','billing_state','billing_postal_code','billing_country',
        'payment_terms_days','status','notes','msa_status','msa_executed_at','msa_expires_at',
    ];
    $patch = [];
    foreach ($allowed as $k) { if (array_key_exists($k, $b)) $patch[$k] = $b[$k]; }
    if (!$patch) api_error('No updatable fields supplied', 422);

    scopedUpdate('staffing_clients', $id, $patch);
    $client = scopedFind('SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    staffingClientAudit($tenantId, $actorUserId, 'staffing.client.updated', $id, [
        'source' => 'staffing_clients_api',
        'changed_fields' => array_keys($patch),
        'before' => staffingClientAuditSnapshot($existing),
        'after' => staffingClientAuditSnapshot($client ?: []),
    ]);
    api_ok(['client' => $client]);
}

if ($method === 'POST' && $action === 'delete') {
    rbac_legacy_require($user, 'staffing.clients.manage');
    $b  = api_json_body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    // Soft delete — flip status to closed. Keeps FK links intact for history.
    $existing = scopedFind('SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :i', ['i' => $id]);
    if (!$existing) api_error('Not found', 404);
    scopedUpdate('staffing_clients', $id, ['status' => 'closed']);
    $client = scopedFind('SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    staffingClientAudit($tenantId, $actorUserId, 'staffing.client.closed', $id, [
        'source' => 'staffing_clients_api',
        'before' => staffingClientAuditSnapshot($existing),
        'after' => staffingClientAuditSnapshot($client ?: []),
    ]);
    api_ok(['ok' => true, 'closed_id' => $id]);
}

if ($method === 'GET' && $action === 'stats') {
    rbac_legacy_require($user, 'staffing.view');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);

    $active = scopedFind("SELECT COUNT(*) AS c FROM placements WHERE tenant_id = :tenant_id AND client_id = :id AND status = 'active'", ['id' => $id]);
    $stats = ['active_placements' => (int) ($active['c'] ?? 0)];

    // MTD revenue from the staffing reports view if it exists.
    try {
        $rev = scopedFind(
            "SELECT COALESCE(SUM(v.revenue), 0) AS r
               FROM v_timesheet_day_fin v
               JOIN placements p ON p.id = v.placement_id AND p.tenant_id = v.tenant_id
              WHERE v.tenant_id = :tenant_id AND p.client_id = :id
                AND v.work_date >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')",
            ['id' => $id]
        );
        $stats['mtd_revenue'] = (float) ($rev['r'] ?? 0);
    } catch (\Throwable $_) {
        $stats['mtd_revenue'] = null;
    }
    api_ok($stats);
}

api_error('Unknown action', 404);
