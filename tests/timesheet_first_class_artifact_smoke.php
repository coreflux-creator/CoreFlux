<?php
/**
 * First-class weekly timesheet artifact contract.
 *
 * Every person/week must resolve to one durable staffing_timesheets header and
 * one artifact object, no matter whether its entries arrive from the weekly
 * editor, the time API, CSV/document import, or JobDiva.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    {$label}\n"; }
    else { $fail++; echo "  FAIL  {$label}\n"; }
};
$lint = static function (string $path) use ($root): bool {
    $output = [];
    $status = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $path) . ' 2>&1', $output, $status);
    return $status === 0;
};

$migration = $read('core/migrations/144_staffing_timesheet_artifacts.sql');
$schema = $read('modules/staffing/migrations/001_timesheets.sql');
$artifacts = $read('core/ai/artifacts.php');
$timesheets = $read('modules/staffing/lib/timesheets.php');
$staffingApi = $read('modules/staffing/api/timesheets.php');
$entryApi = $read('modules/time/api/entries.php');
$csvApi = $read('modules/time/api/csv_import.php');
$jobdiva = $read('core/jobdiva/sync_time.php');
$tokenApi = $read('modules/staffing/api/timesheet_email_approver.php');
$tokenConsumer = $read('core/staffing_email_approval.php');
$listUi = $read('modules/staffing/ui/TimesheetsList.jsx');
$detailUi = $read('modules/staffing/ui/TimesheetDetail.jsx');
$deployPath = $root . '/.github/workflows/deploy-light-workspace.yml';
$deploy = is_file($deployPath) ? (string) file_get_contents($deployPath) : null;

echo "Schema and historical upgrade\n";
$assert('weekly header owns a durable UUID and provenance',
    str_contains($schema, 'artifact_id CHAR(36) NULL')
    && str_contains($schema, 'origin_source VARCHAR(40)')
    && str_contains($schema, 'origin_system VARCHAR(80)')
    && str_contains($schema, 'created_by_user_id BIGINT UNSIGNED NULL'));
$assert('one artifact identity is unique inside a tenant',
    str_contains($schema, 'UNIQUE KEY uq_sts_tenant_artifact (tenant_id, artifact_id)')
    && str_contains($migration, 'ADD UNIQUE KEY uq_sts_tenant_artifact'));
$assert('upgrade backfills identity without assuming time_entries exists',
    str_contains($migration, "table_name = 'time_entries'")
    && str_contains($migration, '@te_exists = 1')
    && str_contains($migration, 'LOWER(UUID())'));
$assert('upgrade materializes object, creation event, and domain relationship',
    str_contains($migration, 'INSERT IGNORE INTO artifact_objects')
    && str_contains($migration, 'INSERT INTO artifact_events')
    && str_contains($migration, 'INSERT INTO artifact_relationships')
    && str_contains($migration, "'represents'"));

echo "\nCanonical materializer\n";
$assert('artifact factory accepts an existing canonical UUID',
    str_contains($artifacts, "\$opts['id'] ?? ''")
    && str_contains($artifacts, 'id must be a valid UUID'));
$assert('one person/week is locked and reused',
    str_contains($timesheets, 'function staffingTimesheetUpsert(')
    && str_contains($timesheets, 'person_id = :person_id AND period_start = :period_start')
    && str_contains($timesheets, 'LIMIT 1 FOR UPDATE'));
$assert('materializer owns its transaction dependency and validates a seven-day week',
    str_contains($timesheets, "require_once __DIR__ . '/../../../core/tx_helpers.php'")
    && str_contains($timesheets, 'must cover exactly 7 consecutive days'));
$assert('new weekly header receives UUID and artifact object atomically',
    str_contains($timesheets, '$artifactId = artifactGenerateUuid()')
    && str_contains($timesheets, 'staffingTimesheetEnsureArtifact($header')
    && str_contains($timesheets, "artifactCreate(\$tenantId, 'staffing_timesheet'"));
$assert('artifact carries display ID, payload, lifecycle, and provenance',
    str_contains($timesheets, "return 'TS-' . \$timesheetId")
    && str_contains($timesheets, 'staffingTimesheetArtifactPayload')
    && str_contains($timesheets, 'staffingTimesheetArtifactTransitionPath')
    && str_contains($timesheets, "'origin_source'"));
$assert('domain changes append immutable artifact and platform audit events',
    str_contains($timesheets, 'artifactWriteEvent($tenantId, $artifactId, $eventType')
    && str_contains($timesheets, 'platformAuditLogWrite($tenantId, $actorUserId'));

echo "\nEvery intake path uses the same weekly object\n";
$assert('weekly editor uses canonical materializer',
    str_contains($timesheets, "'source' => 'manual_entry'")
    && str_contains($timesheets, 'staffingTimesheetUpsert($personId, $periodStart, $periodEnd'));
$assert('single-entry and document APIs use canonical materializer',
    str_contains($entryApi, 'timeApiEnsureDraftTimesheet(')
    && str_contains($entryApi, 'staffingTimesheetUpsert($personId, $start, $end')
    && str_contains($entryApi, "'document_import' : 'bulk_create'"));
$assert('CSV import uses canonical materializer and returns artifact identities',
    str_contains($csvApi, 'staffingTimesheetUpsert($personId, $bounds')
    && str_contains($csvApi, "'source' => 'bulk_upload'")
    && str_contains($csvApi, "'artifact_id' => \$header['artifact_id']"));
$assert('JobDiva uses canonical materializer with integration provenance',
    str_contains($jobdiva, 'staffingTimesheetUpsert($personId, $periodStart, $periodEnd')
    && str_contains($jobdiva, "'source' => 'integration'")
    && str_contains($jobdiva, "'source_system' => 'jobdiva'"));

echo "\nApproval credentials and safe API metadata\n";
$assert('approval link issuance is attached to weekly header and recorded',
    str_contains($tokenApi, 'staffingTimesheetEnsureArtifact($header')
    && str_contains($tokenApi, 'array_merge($header, staffingTimesheetEnsureArtifact')
    && str_contains($tokenApi, "'timesheet.approval_token_issued'")
    && str_contains($tokenApi, "'token_id' => (int) \$tokens['token_id']")
    && str_contains($tokenApi, "consumed_via_action = 'superseded'"));
$assert('approval link use is captured in artifact history',
    str_contains($tokenConsumer, "'timesheet.approval_token_consumed'")
    && str_contains($tokenConsumer, "'approval_token_id'")
    && str_contains($tokenConsumer, "'sibling_tokens_closed'"));
$assert('timesheet API exposes token state but never raw secrets',
    str_contains($staffingApi, 'active_approval_token_count')
    && str_contains($staffingApi, 'latest_approval_token_expires_at')
    && !str_contains($staffingApi, 'token_hash'));

echo "\nOperator visibility and deployment\n";
$assert('list and detail show the human timesheet ID',
    str_contains($listUi, 'display_id') && str_contains($detailUi, 'display_id'));
$assert('detail exposes durable record provenance and link state',
    str_contains($detailUi, 'artifact_id')
    && str_contains($detailUi, 'artifact_version')
    && str_contains($detailUi, 'approval link active'));
$assert('light deployment includes code, migration, and this gate',
    $deploy === null || (
        str_contains($deploy, 'core/ai/artifacts.php')
        && str_contains($deploy, 'core/migrations/144_staffing_timesheet_artifacts.sql')
        && str_contains($deploy, 'core/jobdiva/sync_time.php')
        && str_contains($deploy, 'tests/timesheet_first_class_artifact_smoke.php')
    ));

foreach ([
    'core/ai/artifacts.php',
    'core/jobdiva/sync_time.php',
    'core/staffing_email_approval.php',
    'modules/staffing/api/timesheet_email_approver.php',
    'modules/staffing/api/timesheets.php',
    'modules/staffing/lib/timesheets.php',
    'modules/time/api/csv_import.php',
    'modules/time/api/entries.php',
] as $path) {
    $assert("{$path} parses", $lint($path));
}

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
