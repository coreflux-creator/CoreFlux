<?php
/**
 * /api/ai/payroll_review.php — Slice E payroll-review packet endpoint.
 *
 *   GET ?week_start=YYYY-MM-DD  — current per-week packet:
 *                                  - timesheet anomalies (from
 *                                    detectTimesheetAnomalies)
 *                                  - aggregate stats
 *
 * RBAC: `staffing.read` OR `accounting.read` (CFOs / controllers).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../core/ai/timesheet_anomaly.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();

if ($method !== 'GET') api_error('GET only', 405);

$canRead = rbac_legacy_can($user, 'staffing.read') || rbac_legacy_can($user, 'accounting.read');
if (!$canRead) api_error('Forbidden', 403);

$weekStart = isset($_GET['week_start']) && $_GET['week_start']
    ? (string) $_GET['week_start']
    : date('Y-m-d', strtotime('monday last week'));

try {
    $result = detectTimesheetAnomalies($tid, ['week_start' => $weekStart]);
} catch (\InvalidArgumentException $e) {
    api_error($e->getMessage(), 422);
}

api_ok([
    'packet' => [
        'week_start'      => $result['window']['week_start'],
        'week_end'        => $result['window']['week_end'],
        'scanned_people'  => $result['scanned_people'],
        'findings'        => $result['findings']        ?? [],
        'summary_by_rule' => $result['summary_by_rule'] ?? [],
        'note'            => $result['note']            ?? null,
    ],
]);
