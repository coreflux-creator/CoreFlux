<?php
/**
 * AP API — AP aging snapshot (computed on-read in Phase A0).
 *
 *   GET /api/ap/aging?as_of=YYYY-MM-DD
 *
 * SPEC: /app/modules/ap/SPEC.md §5.6.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/accounting/books_health_metrics.php';
require_once __DIR__ . '/../lib/ap.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() === 'GET') {
    rbac_legacy_require($user, 'ap.reports.view');
    $asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) api_error('as_of must be YYYY-MM-DD', 422);
    try {
        $entityId = booksHealthResolveEntity(getDB(), $tid, api_query('entity_id'));
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (OutOfBoundsException $e) {
        api_error($e->getMessage(), 404);
    }
    api_ok(['as_of' => $asOf, 'entity_id' => $entityId, 'rows' => apComputeAging($tid, $asOf, $entityId)]);
}
api_error('Method not allowed', 405);
