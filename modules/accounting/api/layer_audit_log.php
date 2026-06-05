<?php
/**
 * GET /api/accounting/layer-audit?limit=25
 *
 * Surface the LayerFi integration audit trail (integration_audit_log) for the
 * current tenant in the Accounting UI. Requires accounting.view. Rows are
 * already secret-scrubbed at write time.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/layer/layer_access.php';
require_once __DIR__ . '/../../../core/db.php';

if (!layer_enabled()) api_error('Not found', 404);

$ctx  = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);

rbac_legacy_require($user, 'accounting.view');

$tenantId = (int) ($ctx['tenant_id'] ?? 0);
$limit    = min(100, max(1, (int) ($_GET['limit'] ?? 25)));

$entries = [];
try {
    $st = getDB()->prepare(
        'SELECT id, action, status, external_object_type, external_object_id,
                error_code, error_message, metadata, created_at
         FROM integration_audit_log
         WHERE provider = :p AND tenant_id = :t
         ORDER BY id DESC
         LIMIT ' . $limit
    );
    $st->execute(['p' => 'layer', 't' => $tenantId]);
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $r['metadata'] = $r['metadata'] ? json_decode($r['metadata'], true) : null;
        $entries[] = $r;
    }
} catch (\Throwable $e) {
    /* table may not exist yet */
}

api_ok(['provider' => 'layer', 'tenantId' => $tenantId, 'entries' => $entries]);
