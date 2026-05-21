<?php
/**
 * Integration schema health probe — admin diagnostic.
 *
 *   GET /api/admin/schema_health.php
 *     → { status: 'green'|'amber'|'red',
 *         counts: { ok, undersized, missing, unknown },
 *         columns: [
 *           { integration, integration_label, table, column,
 *             stores, min_bytes, actual_bytes, data_type,
 *             verdict, message } …
 *         ],
 *         generated_at: ISO-8601 }
 *
 * Reports the width of every encrypted-credential column on every
 * integration against its recommended minimum, so the operator can
 * spot drifts before they surface as `1406 Data too long for column`
 * errors at runtime (the JobDiva session_token_enc case that
 * triggered this whole thing).
 *
 * RBAC: `tenant.manage` — same gate as other integration settings.
 * Idempotent / side-effect free / safe to call repeatedly.
 */
require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/integrations/schema_health.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$method = api_method();

if ($method !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'tenant.manage');

$rows  = cf_schema_health_check();
$counts = ['ok' => 0, 'undersized' => 0, 'missing' => 0, 'unknown' => 0];
foreach ($rows as $r) {
    if (isset($counts[$r['verdict']])) $counts[$r['verdict']]++;
}

api_ok([
    'status'       => cf_schema_health_status($rows),
    'counts'       => $counts,
    'columns'      => $rows,
    'generated_at' => gmdate('c'),
]);
