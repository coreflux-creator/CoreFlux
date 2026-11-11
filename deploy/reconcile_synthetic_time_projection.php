<?php
/** Repair only the existing isolated synthetic week's projection, preserving its held source. */
declare(strict_types=1);
const RP_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const RP_PRIVATE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const RP_ARTIFACT = '92419366-4b21-4f06-a946-8a8c1fc8a55b';
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging' || getcwd() !== RP_ROOT || posix_geteuid() !== 1003
    || realpath(RP_PRIVATE) !== RP_PRIVATE || ($argv[1] ?? '') !== '--reconcile-original-synthetic-week'
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) exit(2);
$packageFile = __DIR__ . '/time-projection-package.json';
if (is_link($packageFile) || !hash_equals($argv[2], hash_file('sha256', $packageFile))) exit(2);
$package = json_decode(file_get_contents($packageFile), true, 64, JSON_THROW_ON_ERROR);
foreach ($package['files'] as $file => $record) {
    if (!in_array($file, ['modules/time/lib/time.php','modules/staffing/lib/timesheets.php','modules/time/api/entries.php','modules/time/api/csv_import.php'], true)
        || hash_file('sha256', RP_ROOT . '/' . $file) !== $record['sha256']) exit(2);
}
require_once RP_ROOT . '/core/db.php';
require_once RP_ROOT . '/core/integrations/identity.php';
require_once RP_ROOT . '/modules/staffing/lib/timesheets.php';
$pdo = getDB();
if (!$pdo || !COREFLUX_STAGING || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx'
    || integrationInstanceId() !== '9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    || COREFLUX_INTEGRATION_DELIVERY_TENANTS !== '2002'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn() !== 1) exit(2);
setRequestTenantId(2002);
$state = RP_PRIVATE . '/synthetic-time-projection-reconcile-20261111.json';
if (is_link($state) || file_exists($state)) { fwrite(STDERR, "Inspect the existing reconciliation receipt; do not repeat an uncertain operation.\n"); exit(2); }
$lock = fopen(RP_PRIVATE . '/contact-install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
$report = ['checked_at' => gmdate('c'), 'passed' => false, 'tenant_id' => 2002, 'timesheet_id' => 7, 'time_entry_id' => 14,
    'source_commit' => $package['source_commit'], 'production_changed' => false, 'provider_changed' => false,
    'financial_postings' => false, 'step' => 'prepared'];
$old = umask(0077); $file = fopen($state, 'x'); umask($old);
if (!$file) exit(2); fwrite($file, json_encode($report)); fclose($file);

function rpSnapshot(PDO $pdo, bool $normalize): array {
    $result = [];
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Unexpected table');
        $hashes = [];
        foreach ($pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($normalize && $table === 'staffing_timesheets' && (int) $row['tenant_id'] === 2002 && (int) $row['id'] === 7) {
                unset($row['approved_by_user_id'], $row['updated_at']);
            }
            if ($normalize && $table === 'artifact_objects' && (int) $row['tenant_id'] === 2002 && $row['id'] === RP_ARTIFACT) {
                unset($row['status'], $row['version'], $row['payload_json'], $row['updated_at']);
            }
            $hashes[] = hash('sha256', serialize($row));
        }
        sort($hashes); $result[$table] = $hashes;
    }
    ksort($result); return $result;
}
try {
    $entry = timeEntryGet(14); $header = staffingTimesheetRow(2002, 7); $artifact = artifactGet(2002, RP_ARTIFACT);
    if (!$entry || (int) $entry['person_id'] !== 368 || (int) $entry['placement_id'] !== 874 || (int) $entry['timesheet_id'] !== 7
        || $entry['status'] !== 'approved' || (float) $entry['hours'] !== 1.0 || (int) $entry['approved_by_user_id'] !== 71
        || (int) $entry['rate_snapshot_id'] !== 3 || $entry['approved_at'] !== '2026-11-11 14:40:16'
        || !$header || $header['status'] !== 'approved' || $header['approved_by_user_id'] !== null || $header['artifact_id'] !== RP_ARTIFACT
        || !$artifact || $artifact['status'] !== 'review' || (int) $artifact['version'] !== 3) throw new RuntimeException('Synthetic case changed; inspect before repair');
    $claim = $pdo->query('SELECT * FROM payroll_source_claims WHERE time_tenant_id=2002 AND time_entry_id=14')->fetch(PDO::FETCH_ASSOC);
    if (!$claim || $claim['authority_id'] !== 'cpa_92285a297913dd5feab0e0a3ed148ba14866619c2a01e790e0394a5c57931954') throw new RuntimeException('Held source reservation changed');
    $featureHash = hash_file('sha256', dirname(RP_ROOT) . '/private_html/coreflux.thunderhawk-pilot.php');
    $before = rpSnapshot($pdo, true);
    file_put_contents(RP_PRIVATE . '/synthetic-time-projection-before.private.json', json_encode($before, JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();
    timeReconcileTimesheetHeader(7);
    $updated = staffingTimesheetRow(2002, 7); $nextArtifact = artifactGet(2002, RP_ARTIFACT);
    if ((int) $updated['approved_by_user_id'] !== 71 || $updated['approved_at'] !== $entry['approved_at']
        || $updated['status'] !== 'approved' || $nextArtifact['status'] !== 'approved' || (int) $nextArtifact['version'] !== 5
        || $nextArtifact['payload'] !== json_decode(json_encode(staffingTimesheetArtifactPayload($updated)), true)
        || timeEntryGet(14) !== $entry) throw new RuntimeException('Projection or source verification failed');
    $after = rpSnapshot($pdo, true);
    if (array_keys($after) !== array_keys($before)) throw new RuntimeException('Table set changed');
    foreach ($before as $table => $hashes) {
        if (in_array($table, ['artifact_events','audit_log'], true)) {
            $delta = count($after[$table]) - count($hashes);
            if (array_diff($hashes, $after[$table]) || $delta !== ($table === 'artifact_events' ? 3 : 1)) throw new RuntimeException('Unexpected audit changes');
        } elseif ($after[$table] !== $hashes) throw new RuntimeException('Unexpected changes in ' . $table);
    }
    $repeatBefore = rpSnapshot($pdo, false);
    timeReconcileTimesheetHeader(7);
    if (rpSnapshot($pdo, false) !== $repeatBefore) throw new RuntimeException('Reconciliation replay changed data');
    if (hash_file('sha256', dirname(RP_ROOT) . '/private_html/coreflux.thunderhawk-pilot.php') !== $featureHash) throw new RuntimeException('Configuration changed');
    $report['step'] = 'validated_before_commit'; file_put_contents($state, json_encode($report, JSON_THROW_ON_ERROR));
    $pdo->commit();
    if (rpSnapshot($pdo, false) !== $repeatBefore) throw new RuntimeException('Committed state differs');
    $report += ['tables_verified' => count($before), 'existing_rows_verified' => array_sum(array_map('count', $before)),
        'header_status' => $updated['status'], 'artifact_status' => $nextArtifact['status'], 'artifact_version' => 5,
        'original_approver_user_id' => 71, 'original_approval_at' => $entry['approved_at'], 'source_entry_unchanged' => true,
        'reservation_unchanged' => true, 'replay_unchanged' => true, 'configuration_preserved' => true];
    $report['step'] = 'complete'; $report['passed'] = true;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); $report['rolled_back'] = true; }
    $report['failure'] = $e->getMessage();
}
file_put_contents($state, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo json_encode($report, JSON_THROW_ON_ERROR); exit($report['passed'] ? 0 : 1);
