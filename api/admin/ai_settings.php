<?php
/**
 * /api/admin/ai_settings.php — per-tenant AI toggles for the admin UI.
 *
 * GET  /api/admin/ai_settings.php?tenant_id=NN
 *   Returns:
 *     {
 *       tenant_id: int,
 *       tenant_name: string,
 *       ai_enabled: bool,
 *       ai_full_content_logging: bool,
 *       features: [
 *         { feature_class: 'classification', enabled: true, updated_at: '2026-…' },
 *         …
 *       ],
 *       known_feature_classes: [ 'classification', 'extraction', 'summary', 'narrative', 'draft', 'deep_reasoning' ],
 *     }
 *   When ai_enabled is false the per-feature toggles still load (so the
 *   admin can pre-configure them before flipping the master switch on).
 *
 * POST /api/admin/ai_settings.php
 *   Body: {
 *     tenant_id: int,
 *     ai_enabled?: bool,
 *     ai_full_content_logging?: bool,
 *     features?: { classification?: bool, extraction?: bool, summary?: bool, narrative?: bool, draft?: bool, deep_reasoning?: bool, …}
 *   }
 *   Each field is optional — only the keys present are written. Returns the
 *   refreshed GET payload.
 *
 * Auth: master_admin OR tenant_admin (constrained to their own tenant).
 *       The master_admin may pass any tenant_id; a tenant_admin gets a 403
 *       if they try to flip a different tenant's settings.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';

$ctx           = api_require_auth();
$user          = $ctx['user'] ?? [];
$role          = (string) ($ctx['role'] ?? 'employee');
$isGlobalAdmin = (bool) ($ctx['is_global_admin'] ?? false);
$activeTenant  = (int) (currentTenantId() ?? 0);

if (!$isGlobalAdmin && !in_array($role, ['master_admin', 'tenant_admin'], true)) {
    api_error('Forbidden — admin only', 403);
}

/** Canonical list — the migration comment + all aiGateForTenant() callers. */
const AI_KNOWN_FEATURE_CLASSES = [
    'classification',
    'extraction',
    'summary',
    'narrative',
    'draft',
    'deep_reasoning',
];

function aiSettingsResolveTenantId(array $ctx, bool $isGlobalAdmin, string $role, int $activeTenant, $requested): int {
    $tid = (int) ($requested ?: $activeTenant);
    if ($tid <= 0) api_error('tenant_id required', 400);
    if ($isGlobalAdmin) return $tid;
    if ($role === 'tenant_admin' && $tid !== $activeTenant) {
        api_error('Forbidden — tenant_admin may only edit own tenant', 403);
    }
    return $tid;
}

function aiSettingsLoadPayload(PDO $pdo, int $tenantId): array {
    // tenant-leak-allow: admin endpoint — tenant id is explicitly requested + RBAC-checked above
    $stmt = $pdo->prepare(
        'SELECT id, name, ai_enabled, ai_full_content_logging
           FROM tenants WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) api_error('Tenant not found', 404);

    $stmt = $pdo->prepare(
        'SELECT feature_class, enabled, updated_at
           FROM ai_tenant_features
          WHERE tenant_id = :t'
    );
    $stmt->execute(['t' => $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $features = [];
    $seen = [];
    foreach ($rows as $r) {
        $cls = (string) $r['feature_class'];
        $seen[$cls] = true;
        $features[] = [
            'feature_class' => $cls,
            'enabled'       => (bool) (int) $r['enabled'],
            'updated_at'    => $r['updated_at'],
        ];
    }
    // Surface known classes that haven't been written yet so the UI can
    // render every checkbox without an explicit insert per tenant. The gate
    // treats a missing row as "enabled" so we mirror that default in the UI.
    foreach (AI_KNOWN_FEATURE_CLASSES as $cls) {
        if (!isset($seen[$cls])) {
            $features[] = ['feature_class' => $cls, 'enabled' => true, 'updated_at' => null];
        }
    }

    return [
        'tenant_id'               => (int) $tenant['id'],
        'tenant_name'             => (string) $tenant['name'],
        'ai_enabled'              => (bool) (int) ($tenant['ai_enabled'] ?? 0),
        'ai_full_content_logging' => (bool) (int) ($tenant['ai_full_content_logging'] ?? 0),
        'features'                => $features,
        'known_feature_classes'   => AI_KNOWN_FEATURE_CLASSES,
    ];
}

$pdo = getDB();
if (!$pdo) api_error('Database unavailable', 500);

$method = api_method();

if ($method === 'GET') {
    $tid = aiSettingsResolveTenantId($ctx, $isGlobalAdmin, $role, $activeTenant, $_GET['tenant_id'] ?? null);
    api_json(['ok' => true] + aiSettingsLoadPayload($pdo, $tid));
}

if ($method === 'POST') {
    $body = api_json_body();
    $tid  = aiSettingsResolveTenantId($ctx, $isGlobalAdmin, $role, $activeTenant, $body['tenant_id'] ?? null);

    $pdo->beginTransaction();
    try {
        $updates = [];
        $params  = ['id' => $tid];
        if (array_key_exists('ai_enabled', $body)) {
            $updates[] = 'ai_enabled = :ai_enabled';
            $params['ai_enabled'] = (int) (bool) $body['ai_enabled'];
        }
        if (array_key_exists('ai_full_content_logging', $body)) {
            $updates[] = 'ai_full_content_logging = :ai_full_content_logging';
            $params['ai_full_content_logging'] = (int) (bool) $body['ai_full_content_logging'];
        }
        if ($updates) {
            // tenant-leak-allow: admin endpoint, tenant id resolved + RBAC-checked above
            $sql = 'UPDATE tenants SET ' . implode(', ', $updates) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
        }

        if (!empty($body['features']) && is_array($body['features'])) {
            // tenant-leak-allow: admin endpoint, tenant id resolved + RBAC-checked above
            $up = $pdo->prepare(
                'INSERT INTO ai_tenant_features (tenant_id, feature_class, enabled, updated_at)
                 VALUES (:t, :f, :e, NOW())
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = NOW()'
            );
            foreach ($body['features'] as $cls => $val) {
                $cls = (string) $cls;
                if (!in_array($cls, AI_KNOWN_FEATURE_CLASSES, true)) continue;
                $up->execute(['t' => $tid, 'f' => $cls, 'e' => (int) (bool) $val]);
            }
        }

        // Audit trail — these settings carry compliance weight (full content
        // logging) so we log every change against the actor.
        if (function_exists('audit_log')) {
            audit_log('admin.ai_settings.updated', [
                'tenant_id' => $tid,
                'actor'     => (int) ($user['id'] ?? 0),
                'changes'   => array_intersect_key($body, array_flip(['ai_enabled', 'ai_full_content_logging', 'features'])),
            ]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        api_error('Failed to save settings: ' . $e->getMessage(), 500);
    }

    api_json(['ok' => true] + aiSettingsLoadPayload($pdo, $tid));
}

api_error('Method not allowed', 405);
