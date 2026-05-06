<?php
/**
 * Unified audit-log API (Sprint 4 / A3).
 *
 *   GET /api/audit_log?event=&user_id=&from=&to=&limit=&format=json|csv
 *
 * Tenant-scoped. CSV export gated by the same permission as JSON read.
 * Existing `audit_log` table provides: tenant_id, user_id, event,
 * target_id, meta_json, ip_address, created_at.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$tenantId  = (int) $ctx['tenant_id'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);

// Anyone with any audit-view perm gets in (master_admin is global override).
$role = $user['role'] ?? '';
if (!in_array($role, ['master_admin', 'tenant_admin', 'admin'], true)) {
    api_error('Forbidden', 403);
}

$where  = ['tenant_id = :t'];
$params = ['t' => $tenantId];
if ($evt = api_query('event'))    { $where[] = 'event LIKE :e';      $params['e']  = '%' . $evt . '%'; }
if ($uid = api_query('user_id'))  { $where[] = 'user_id = :u';       $params['u']  = (int) $uid; }
if ($from = api_query('from'))    { $where[] = 'created_at >= :f';   $params['f']  = (string) $from; }
if ($to = api_query('to'))        { $where[] = 'created_at < :to';   $params['to'] = (string) $to; }
$limit  = min(5000, max(1, (int) (api_query('limit') ?? 200)));
$format = (string) (api_query('format') ?? 'json');

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

$sql = "SELECT al.id, al.event, al.user_id, al.target_id, al.meta_json,
               al.ip_address, al.created_at,
               u.name AS user_name, u.email AS user_email
          FROM audit_log al
          LEFT JOIN users u ON u.id = al.user_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY al.created_at DESC
         LIMIT {$limit}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($format === 'csv') {
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log-tenant-' . $tenantId . '-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','event','user_id','user_name','user_email','target_id','meta','ip','created_at']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['event'], $r['user_id'], $r['user_name'] ?? '', $r['user_email'] ?? '',
            $r['target_id'] ?? '', (string) ($r['meta_json'] ?? ''), $r['ip_address'] ?? '', $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

api_ok(['rows' => $rows, 'count' => count($rows), 'limit' => $limit]);
