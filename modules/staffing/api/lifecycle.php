<?php
/**
 * /api/staffing/lifecycle — Timesheet & time_entry lifecycle resolver.
 *
 *   GET ?action=timesheet&id=<timesheet_id>
 *       → full cascade for every entry in the timesheet
 *
 *   GET ?action=entry&id=<time_entry_id>
 *       → cascade narrowed to artifacts that touched THIS entry
 *
 * Read-only; tenant-scoped via scopedFind/scopedQuery in the lib.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/lifecycle.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? 'timesheet';

rbac_legacy_require($user, 'staffing.timesheets.read');

if ($method !== 'GET') api_error('Method not allowed', 405);

$tid = currentTenantId();
$id  = (int) ($_GET['id'] ?? 0);
if ($id <= 0) api_error('id required', 400);

try {
    if ($action === 'timesheet') {
        $cascade = staffingTimesheetLifecycle($tid, $id);
        api_ok($cascade);
    }
    if ($action === 'entry') {
        $cascade = staffingTimeEntryLifecycle($tid, $id);
        api_ok($cascade);
    }
    api_error('Unknown action', 400);
} catch (\Throwable $e) {
    api_error($e->getMessage(), 404);
}
