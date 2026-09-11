<?php
/**
 * /api/staffing/clients — Clients CRUD.
 *
 *   GET    list   ?q=&status=&limit=
 *   GET    get    ?id=N
 *   POST   create body: { name, legal_name, industry, primary_contact_*, billing_*, status, payment_terms_days, notes }
 *   POST   update body: { id, ...fields }
 *   POST   bulk_update body: { ids[], field, value }
 *   POST   delete body: { id } → status=closed (soft delete)
 *
 *   GET    stats  ?id=N → { active_placements, mtd_revenue, ar_outstanding }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../lib/client_audit.php';
require_once __DIR__ . '/../lib/clients.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$activeTenantId = (int) ($ctx['tenant_id'] ?? currentTenantId());
$tenantId = staffingClientCatalogTenantId($activeTenantId);
$placementsTenantId = $tenantId;
setRequestModuleScope('placements');
$actorUserId = isset($user['id']) ? (int) $user['id'] : null;
$method = api_method();
$action = $_GET['action'] ?? 'list';

if ($method === 'GET' && $action === 'list') {
    api_require_legacy_permission($ctx, 'placements.view');
    $where  = ['c.tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['status'])) { $where[] = 'c.status = :s'; $params['s'] = $_GET['status']; }
    if (!empty($_GET['q']))      {
        // Distinct placeholders required by PDO_MYSQL native prepares.
        $where[] = '(c.name LIKE :q_name
                  OR c.legal_name LIKE :q_legal
                  OR c.industry LIKE :q_industry
                  OR c.primary_contact_name LIKE :q_contact
                  OR c.primary_contact_email LIKE :q_email
                  OR c.primary_contact_phone LIKE :q_phone
                  OR c.billing_city LIKE :q_city
                  OR c.billing_state LIKE :q_state
                  OR c.billing_country LIKE :q_country
                  OR c.external_id LIKE :q_external
                  OR c.source_system LIKE :q_source
                  OR c.status LIKE :q_status
                  OR c.msa_status LIKE :q_msa
                  OR CAST(c.payment_terms_days AS CHAR) LIKE :q_terms)';
        $like = '%' . trim((string) $_GET['q']) . '%';
        foreach (['q_name','q_legal','q_industry','q_contact','q_email','q_phone','q_city','q_state','q_country','q_external','q_source','q_status','q_msa','q_terms'] as $key) {
            $params[$key] = $like;
        }
    }
    $source = strtolower(trim((string) ($_GET['source'] ?? '')));
    if ($source === 'placement') {
        $where[] = 'EXISTS (
            SELECT 1 FROM placements sp
             WHERE sp.tenant_id = :source_placement_tid
               AND sp.end_client_company_id = c.company_id
               AND sp.status = \'active\'
               AND sp.deleted_at IS NULL
        )';
        $params['source_placement_tid'] = $placementsTenantId;
    } elseif ($source === 'accounting') {
        $where[] = 'EXISTS (
            SELECT 1 FROM external_entity_mappings sem
             WHERE sem.tenant_id = c.tenant_id
               AND sem.internal_entity_type = \'customer\'
               AND sem.internal_entity_id = c.id
        )';
    } elseif ($source === 'jobdiva') {
        $where[] = 'LOWER(c.source_system) = \'jobdiva\'';
    } elseif ($source === 'manual') {
        $where[] = 'COALESCE(NULLIF(LOWER(c.source_system), \'\'), \'manual\') = \'manual\'
                    AND NOT EXISTS (
                        SELECT 1 FROM placements smp
                         WHERE smp.tenant_id = :source_manual_tid
                           AND smp.end_client_company_id = c.company_id
                           AND smp.status = \'active\'
                           AND smp.deleted_at IS NULL
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM external_entity_mappings sme
                         WHERE sme.tenant_id = c.tenant_id
                           AND sme.internal_entity_type = \'customer\'
                           AND sme.internal_entity_id = c.id
                    )';
        $params['source_manual_tid'] = $placementsTenantId;
    }
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $sortMap = [
        'name'                  => 'c.name',
        'industry'              => 'c.industry',
        'active_placements'     => 'active_placements',
        'primary_contact_email' => 'c.primary_contact_email',
        'payment_terms_days'    => 'c.payment_terms_days',
        'msa_status'            => 'c.msa_status',
        'source'                => 'source_label',
        'status'                => 'c.status',
        'created_at'            => 'c.created_at',
    ];
    $sortKey = (string) ($_GET['sort'] ?? 'name');
    $sortExpr = $sortMap[$sortKey] ?? $sortMap['name'];
    $sortDir = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $sql = "SELECT c.id, c.company_id, c.name, c.legal_name, c.industry, c.status, c.payment_terms_days,
                   c.primary_contact_name, c.primary_contact_email, c.primary_contact_phone,
                   c.billing_city, c.billing_state, c.billing_country,
                   c.msa_status, c.created_at, c.source_system, c.external_id,
                   CASE
                     WHEN COALESCE(p.cnt, 0) > 0 THEN 'Placement billing company'
                     WHEN EXISTS (
                       SELECT 1 FROM external_entity_mappings em
                        WHERE em.tenant_id = c.tenant_id
                          AND em.internal_entity_type = 'customer'
                          AND em.internal_entity_id = c.id
                     ) THEN 'Accounting customer'
                     WHEN c.source_system <> 'manual' THEN c.source_system
                     ELSE 'Manual'
                   END AS source_label,
                   COALESCE(p.cnt, 0) AS active_placements
              FROM staffing_clients c
              LEFT JOIN (
                  SELECT end_client_company_id, COUNT(*) AS cnt
                    FROM placements
                   WHERE tenant_id = :placements_tid AND status = 'active'
                     AND deleted_at IS NULL
                     AND end_client_company_id IS NOT NULL
                   GROUP BY end_client_company_id
              ) p ON p.end_client_company_id = c.company_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY {$sortExpr} {$sortDir}, c.id DESC
             LIMIT " . $limit;
    api_ok(['rows' => staffingClientCatalogQuery(
        $tenantId,
        $sql,
        array_merge($params, ['placements_tid' => $placementsTenantId])
    )]);
}

if ($method === 'POST' && $action === 'bulk_update') {
    api_require_legacy_permission($ctx, 'placements.manage');
    $b = api_json_body();
    $ids = is_array($b['ids'] ?? null) ? array_values(array_unique(array_map('intval', $b['ids']))) : [];
    $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
    $field = (string) ($b['field'] ?? '');
    $value = $b['value'] ?? null;
    $allowed = ['status','payment_terms_days','industry','msa_status'];
    if (!$ids) api_error('ids[] required', 422);
    if (count($ids) > 500) api_error('Too many ids (max 500 per call)', 422);
    if (!in_array($field, $allowed, true)) api_error('Invalid bulk field', 422, ['allowed' => $allowed]);

    if ($field === 'status') {
        $value = strtolower(trim((string) $value));
        $statuses = ['active','prospect','on_hold','inactive','closed'];
        if (!in_array($value, $statuses, true)) api_error('Invalid status', 422, ['allowed' => $statuses]);
    } elseif ($field === 'msa_status') {
        $value = strtolower(trim((string) $value));
        $msaStatuses = ['none','draft','executed','expired'];
        if (!in_array($value, $msaStatuses, true)) api_error('Invalid msa_status', 422, ['allowed' => $msaStatuses]);
    } elseif ($field === 'payment_terms_days') {
        if (!is_numeric($value)) api_error('payment_terms_days must be numeric', 422);
        $value = (int) $value;
        if ($value < 0 || $value > 365) api_error('payment_terms_days must be between 0 and 365', 422);
    } else {
        $value = trim((string) $value);
        if ($value === '') api_error('industry cannot be blank', 422);
        if (strlen($value) > 120) api_error('industry is too long', 422);
    }

    $updated = 0; $skipped = 0; $failed = 0; $results = [];
    foreach ($ids as $id) {
        try {
            $existing = staffingClientCatalogFind(
                $tenantId,
                'SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :id',
                ['id' => $id]
            );
            if (!$existing) {
                $skipped++;
                $results[] = ['id' => $id, 'ok' => false, 'reason' => 'not_found'];
                continue;
            }
            if ($field === 'status' && in_array($value, ['inactive','closed'], true) && !empty($existing['company_id'])) {
                $active = staffingClientCatalogFind(
                    $tenantId,
                    'SELECT COUNT(*) AS c FROM placements
                      WHERE tenant_id = :tenant_id
                        AND end_client_company_id = :company_id
                        AND status = \'active\'
                        AND deleted_at IS NULL',
                    ['company_id' => (int) $existing['company_id']]
                );
                if ((int) ($active['c'] ?? 0) > 0) {
                    throw new \RuntimeException('Client has active placements');
                }
            }
            if ((string) ($existing[$field] ?? '') === (string) $value) {
                $skipped++;
                $results[] = ['id' => $id, 'ok' => true, 'reason' => 'no_change'];
                continue;
            }
            $before = staffingClientAuditSnapshot($existing);
            staffingClientCatalogUpdate($tenantId, $id, [$field => $value]);
            if (!empty($existing['company_id']) && in_array($field, ['industry','payment_terms_days'], true)) {
                staffingClientApplyCompanyPatch($tenantId, (int) $existing['company_id'], [$field => $value]);
            }
            $after = staffingClientCatalogFind(
                $tenantId,
                'SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :id',
                ['id' => $id]
            );
            staffingClientAudit($tenantId, $actorUserId, 'staffing.client.updated', $id, [
                'source' => 'bulk_update',
                'changed_fields' => [$field],
                'before' => $before,
                'after' => staffingClientAuditSnapshot($after ?: []),
            ]);
            $updated++;
            $results[] = ['id' => $id, 'ok' => true];
        } catch (\Throwable $e) {
            $failed++;
            $results[] = ['id' => $id, 'ok' => false, 'reason' => $e->getMessage()];
        }
    }
    api_ok([
        'ok' => $failed === 0,
        'updated' => $updated,
        'skipped' => $skipped,
        'failed' => $failed,
        'field' => $field,
        'results' => $results,
    ]);
}

if ($method === 'GET' && $action === 'get') {
    api_require_legacy_permission($ctx, 'placements.view');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $row = staffingClientCatalogFind(
        $tenantId,
        'SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$row) api_error('Not found', 404);
    api_ok(['client' => $row]);
}

if ($method === 'POST' && $action === 'create') {
    api_require_legacy_permission($ctx, 'placements.manage');
    $b = api_json_body();
    $name = trim((string) ($b['name'] ?? ''));
    if ($name === '') api_error('name required', 422);

    // Reject duplicate names.
    $existing = staffingClientCatalogFind(
        $tenantId,
        'SELECT id FROM staffing_clients WHERE tenant_id = :tenant_id AND name = :n',
        ['n' => $name]
    );
    if ($existing) api_error("Client '{$name}' already exists", 409, ['existing_id' => $existing['id']]);

    $clientRef = staffingClientEnsureForCompany($tenantId, null, $name, [
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
        'created_by_user_id'    => $actorUserId,
    ]);
    $id = (int) $clientRef['client_id'];
    $postCreatePatch = array_filter([
        'notes'      => $b['notes']      ?? null,
        'msa_status' => $b['msa_status'] ?? 'none',
    ], static fn($v) => $v !== null);
    if ($postCreatePatch) staffingClientCatalogUpdate($tenantId, $id, $postCreatePatch);
    $client = staffingClientCatalogFind(
        $tenantId,
        'SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    staffingClientAudit($tenantId, $actorUserId, 'staffing.client.created', $id, [
        'source' => 'staffing_clients_api',
        'after' => staffingClientAuditSnapshot($client ?: ['id' => $id, 'name' => $name]),
    ]);
    api_ok(['client' => $client]);
}

if ($method === 'POST' && $action === 'update') {
    api_require_legacy_permission($ctx, 'placements.manage');
    $b  = api_json_body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $existing = staffingClientCatalogFind(
        $tenantId,
        'SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :i',
        ['i' => $id]
    );
    if (!$existing) api_error('Not found', 404);

    $allowed = [
        'name','legal_name','industry','primary_contact_name','primary_contact_email','primary_contact_phone',
        'billing_address_line1','billing_address_line2','billing_city','billing_state','billing_postal_code','billing_country',
        'payment_terms_days','status','notes','msa_status','msa_executed_at','msa_expires_at',
    ];
    $patch = [];
    foreach ($allowed as $k) { if (array_key_exists($k, $b)) $patch[$k] = $b[$k]; }
    if (!$patch) api_error('No updatable fields supplied', 422);

    if (array_key_exists('name', $patch) || empty($existing['company_id'])) {
        $clientRef = staffingClientEnsureForCompany(
            $tenantId,
            !empty($existing['company_id']) ? (int) $existing['company_id'] : null,
            (string) ($patch['name'] ?? $existing['name']),
            $patch + ['sync_company_patch' => true]
        );
        $patch['company_id'] = $clientRef['company_id'];
        $patch['name'] = $clientRef['name'];
    } elseif (!empty($existing['company_id'])) {
        staffingClientApplyCompanyPatch($tenantId, (int) $existing['company_id'], $patch + ['name' => $existing['name']]);
    }
    staffingClientCatalogUpdate($tenantId, $id, $patch);
    $client = staffingClientCatalogFind(
        $tenantId,
        'SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    staffingClientAudit($tenantId, $actorUserId, 'staffing.client.updated', $id, [
        'source' => 'staffing_clients_api',
        'changed_fields' => array_keys($patch),
        'before' => staffingClientAuditSnapshot($existing),
        'after' => staffingClientAuditSnapshot($client ?: []),
    ]);
    api_ok(['client' => $client]);
}

if ($method === 'POST' && $action === 'delete') {
    api_require_legacy_permission($ctx, 'placements.manage');
    $b  = api_json_body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    // Soft delete — flip status to closed. Keeps FK links intact for history.
    $existing = staffingClientCatalogFind(
        $tenantId,
        'SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :i',
        ['i' => $id]
    );
    if (!$existing) api_error('Not found', 404);
    staffingClientCatalogUpdate($tenantId, $id, ['status' => 'closed']);
    $client = staffingClientCatalogFind(
        $tenantId,
        'SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    staffingClientAudit($tenantId, $actorUserId, 'staffing.client.closed', $id, [
        'source' => 'staffing_clients_api',
        'before' => staffingClientAuditSnapshot($existing),
        'after' => staffingClientAuditSnapshot($client ?: []),
    ]);
    api_ok(['ok' => true, 'closed_id' => $id]);
}

if ($method === 'GET' && $action === 'stats') {
    api_require_legacy_permission($ctx, 'placements.view');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);

    $activeStmt = getDB()->prepare(
        "SELECT COUNT(*) AS c
           FROM placements p
           JOIN staffing_clients sc
             ON sc.tenant_id = p.tenant_id
            AND sc.company_id = p.end_client_company_id
          WHERE p.tenant_id = :placements_tid
            AND sc.id = :id
            AND p.status = 'active'
            AND p.deleted_at IS NULL"
    );
    $activeStmt->execute(['placements_tid' => $placementsTenantId, 'id' => $id]);
    $active = $activeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats = ['active_placements' => (int) ($active['c'] ?? 0)];

    // MTD revenue from the staffing reports view if it exists.
    try {
        $stmt = getDB()->prepare(
            "SELECT COALESCE(SUM(v.revenue), 0) AS r
               FROM v_timesheet_day_fin v
               JOIN placements p ON p.id = v.placement_id AND p.tenant_id = :placements_tid
               JOIN staffing_clients sc ON sc.tenant_id = p.tenant_id AND sc.company_id = p.end_client_company_id
              WHERE v.tenant_id = :tenant_id
                AND sc.id = :id
                AND v.work_date >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')"
        );
        $stmt->execute(['tenant_id' => $activeTenantId, 'placements_tid' => $placementsTenantId, 'id' => $id]);
        $rev = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['mtd_revenue'] = (float) ($rev['r'] ?? 0);
    } catch (\Throwable $_) {
        $stats['mtd_revenue'] = null;
    }
    api_ok($stats);
}

api_error('Unknown action', 404);
