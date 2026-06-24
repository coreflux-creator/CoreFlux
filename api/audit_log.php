<?php
/**
 * Unified audit-log API (Sprint 4 / A3).
 *
 *   GET /api/audit_log?event=&user_id=&actor_type=&actor_email=&object_type=&target_id=&request_id=&source=&from=&to=&limit=&format=json|csv
 *
 * Tenant-scoped. CSV export gated by the same permission as JSON read.
 * Existing `audit_log` table variants are normalized at read time:
 * actor_user_id/user_id, event/action, target_id/entity_id, request_id/meta.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$tenantId  = (int) $ctx['tenant_id'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);

// Tenant-scoped audit evidence is readable by admins and read-only auditors.
$role = (string) ($ctx['role'] ?? $user['role'] ?? '');
$globalRole = (string) ($ctx['global_role'] ?? $user['global_role'] ?? $role);
$isGlobalAdmin = (bool) ($ctx['is_global_admin'] ?? false);
$allowedRoles = ['master_admin', 'tenant_admin', 'admin', 'auditor', 'external_auditor'];
if ($globalRole !== 'master_admin' && !$isGlobalAdmin && !in_array($role, $allowedRoles, true)) {
    api_error('Forbidden', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);
$cols = auditLogColumns($pdo);
$hasQuery = static fn($value): bool => $value !== null && $value !== '';
$actorExpr = auditLogCoalesce($cols, ['actor_user_id', 'user_id'], 'NULL');
$eventExpr = auditLogCoalesce($cols, ['event', 'action'], "''", true);
$targetExpr = auditLogCoalesce($cols, ['target_id', 'entity_id'], 'NULL');
$requestExpr = auditLogColumnExpr($cols, 'request_id', 'NULL');
$userAgentExpr = auditLogColumnExpr($cols, 'user_agent', 'NULL');
$actorTypeFallback = "CASE WHEN {$actorExpr} IS NULL THEN 'system' ELSE 'user' END";
$actorTypeExpr = auditLogCoalesce($cols, ['actor_type'], $actorTypeFallback, true);
$actorEmailExpr = in_array('actor_email', $cols, true)
    ? "COALESCE(NULLIF(al.actor_email, ''), u.email)"
    : 'u.email';
$objectTypeExpr = auditLogCoalesce($cols, ['object_type', 'entity'], 'NULL', true);
$beforeExpr = auditLogColumnExpr($cols, 'before_json', 'NULL');
$afterExpr = auditLogColumnExpr($cols, 'after_json', 'NULL');
$sourceExpr = auditLogColumnExpr($cols, 'source', 'NULL');

$where  = ['al.tenant_id = :t'];
$params = ['t' => $tenantId];
if ($hasQuery($evt = api_query('event'))) {
    $where[] = "{$eventExpr} LIKE :e";
    $params['e']  = '%' . $evt . '%';
}
if ($hasQuery($uid = api_query('user_id', api_query('actor_user_id')))) {
    $where[] = "{$actorExpr} = :u";
    $params['u'] = (int) $uid;
}
if ($hasQuery($actorType = api_query('actor_type'))) {
    $where[] = "{$actorTypeExpr} = :actor_type";
    $params['actor_type'] = (string) $actorType;
}
if ($hasQuery($actorEmail = api_query('actor_email'))) {
    $where[] = "{$actorEmailExpr} LIKE :actor_email";
    $params['actor_email'] = '%' . $actorEmail . '%';
}
if ($hasQuery($objectType = api_query('object_type'))) {
    $where[] = "{$objectTypeExpr} LIKE :object_type";
    $params['object_type'] = '%' . $objectType . '%';
}
if ($hasQuery($target = api_query('target_id', api_query('object_id')))) {
    $where[] = "{$targetExpr} = :target_id";
    $params['target_id'] = (int) $target;
}
if ($hasQuery($ip = api_query('ip'))) {
    $where[] = 'al.ip_address LIKE :ip';
    $params['ip'] = '%' . $ip . '%';
}
if ($hasQuery($requestId = api_query('request_id'))) {
    $where[] = in_array('request_id', $cols, true)
        ? "{$requestExpr} = :request_id"
        : 'meta_json LIKE :request_id_like';
    $params[in_array('request_id', $cols, true) ? 'request_id' : 'request_id_like'] =
        in_array('request_id', $cols, true) ? (string) $requestId : '%' . $requestId . '%';
}
if ($hasQuery($source = api_query('source'))) {
    $where[] = in_array('source', $cols, true)
        ? "{$sourceExpr} = :source"
        : 'meta_json LIKE :source_like';
    $params[in_array('source', $cols, true) ? 'source' : 'source_like'] =
        in_array('source', $cols, true) ? (string) $source : '%' . $source . '%';
}
if ($hasQuery($from = api_query('from'))) { $where[] = 'al.created_at >= :f';   $params['f']  = (string) $from; }
if ($hasQuery($to = api_query('to')))     { $where[] = 'al.created_at < :to';   $params['to'] = (string) $to; }
$limit  = min(5000, max(1, (int) (api_query('limit') ?? 200)));
$format = (string) (api_query('format') ?? 'json');

$sql = "SELECT al.id, al.tenant_id,
               {$eventExpr} AS event,
               {$actorExpr} AS actor_user_id,
               {$actorTypeExpr} AS actor_type,
               {$actorEmailExpr} AS actor_email,
               {$targetExpr} AS target_id,
               {$objectTypeExpr} AS object_type,
               {$requestExpr} AS request_id,
               {$sourceExpr} AS source,
               {$beforeExpr} AS before_json,
               {$afterExpr} AS after_json,
               al.meta_json,
               al.ip_address,
               {$userAgentExpr} AS user_agent,
               al.created_at,
               u.name AS user_name, u.email AS user_email
          FROM audit_log al
          LEFT JOIN users u ON u.id = {$actorExpr}
         WHERE " . implode(' AND ', $where) . "
         ORDER BY al.created_at DESC
         LIMIT {$limit}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = array_map('auditLogNormalizeRow', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

if ($format === 'csv') {
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log-tenant-' . $tenantId . '-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'id','tenant_id','event','actor_type','actor_user_id','actor_email',
        'user_name','user_email','object_type','target_id','request_id',
        'source','meta','before','after','ip','user_agent','created_at',
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['tenant_id'], $r['event'], $r['actor_type'], $r['actor_user_id'],
            $r['actor_email'], $r['user_name'] ?? '', $r['user_email'] ?? '',
            $r['object_type'], $r['target_id'] ?? '', $r['request_id'], $r['source'],
            auditLogCsvValue($r['meta_json'] ?? null),
            auditLogCsvValue($r['before_json'] ?? null),
            auditLogCsvValue($r['after_json'] ?? null),
            $r['ip_address'] ?? '', $r['user_agent'] ?? '', $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

api_ok([
    'rows' => $rows,
    'count' => count($rows),
    'limit' => $limit,
    'filters' => [
        'event' => api_query('event'),
        'user_id' => api_query('user_id', api_query('actor_user_id')),
        'actor_type' => api_query('actor_type'),
        'actor_email' => api_query('actor_email'),
        'object_type' => api_query('object_type'),
        'target_id' => api_query('target_id', api_query('object_id')),
        'request_id' => api_query('request_id'),
        'source' => api_query('source'),
        'ip' => api_query('ip'),
        'from' => api_query('from'),
        'to' => api_query('to'),
    ],
]);

function auditLogColumns(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SHOW COLUMNS FROM audit_log')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn($row) => (string) ($row['Field'] ?? ''), $rows);
    } catch (Throwable $e) {
        return ['id','tenant_id','actor_user_id','user_id','event','target_id','meta_json','ip_address','created_at'];
    }
}

function auditLogColumnExpr(array $cols, string $column, string $fallback, string $alias = 'al'): string
{
    return in_array($column, $cols, true) ? "{$alias}.{$column}" : $fallback;
}

function auditLogCoalesce(array $cols, array $columns, string $fallback, bool $nullIfEmpty = false): string
{
    $parts = [];
    foreach ($columns as $column) {
        if (!in_array($column, $cols, true)) continue;
        $expr = "al.{$column}";
        $parts[] = $nullIfEmpty ? "NULLIF({$expr}, '')" : $expr;
    }
    if (!$parts) return $fallback;
    $parts[] = $fallback;
    return 'COALESCE(' . implode(', ', $parts) . ')';
}

function auditLogNormalizeRow(array $row): array
{
    foreach (['id','tenant_id','actor_user_id','target_id'] as $key) {
        $row[$key] = isset($row[$key]) && $row[$key] !== null && $row[$key] !== ''
            ? (int) $row[$key]
            : null;
    }
    $row['actor_type'] = (string) ($row['actor_type'] ?: ($row['actor_user_id'] ? 'user' : 'system'));
    if (empty($row['actor_email'])) $row['actor_email'] = $row['user_email'] ?? null;
    foreach (['meta_json','before_json','after_json'] as $key) {
        if (is_string($row[$key] ?? null) && trim((string) $row[$key]) !== '') {
            $decoded = json_decode((string) $row[$key], true);
            if (json_last_error() === JSON_ERROR_NONE) $row[$key] = $decoded;
        }
    }
    if (empty($row['request_id']) && is_array($row['meta_json'] ?? null)) {
        $row['request_id'] = $row['meta_json']['request_id'] ?? null;
    }
    if (empty($row['source']) && is_array($row['meta_json'] ?? null)) {
        $row['source'] = $row['meta_json']['source'] ?? null;
    }
    return $row;
}

function auditLogCsvValue($value): string
{
    if (is_array($value)) return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
    return (string) ($value ?? '');
}
