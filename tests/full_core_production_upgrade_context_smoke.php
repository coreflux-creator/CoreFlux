<?php
declare(strict_types=1);
require __DIR__ . '/../deploy/full_core_production_upgrade_context.php';
$checks = 0;
$assert = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    $checks++;
};
$reject = static function (callable $fn, string $name) use ($assert): void {
    try { $fn(); } catch (Throwable $error) { $assert(true, $name); return; }
    $assert(false, $name);
};
$binding = ['version' => 1, 'action' => 'apply-reviewed-core-database-upgrade',
    'root' => FullCoreProductionUpgradeContext::ROOT, 'database' => FullCoreProductionUpgradeContext::DATABASE,
    'application_uid' => 1004, 'master_uid' => 1003, 'database_server_id' => 1,
    'operation' => str_repeat('a', 32), 'candidate_commit' => str_repeat('b', 40),
    'package_directory' => '/private/package', 'application_maintenance_parent' => '/private/maintenance'];
foreach (['manifest_sha256', 'migration_plan_sha256', 'application_maintenance_receipt_sha256', 'master_maintenance_receipt_sha256',
    'held_web_sha256', 'application_held_cron_sha256', 'master_held_cron_sha256', 'backup_pair_sha256', 'writer_inventory_sha256'] as $key) $binding[$key] = hash('sha256', $key);
FullCoreProductionUpgradeContext::validateBinding($binding); $assert(true, 'complete production binding accepted');
foreach (['root' => '/another/public_html', 'database' => 'muzqvdvqbx', 'application_uid' => 1003,
    'master_uid' => 1004, 'action' => 'rehearse', 'operation' => 'bad', 'migration_plan_sha256' => 'bad',
    'package_directory' => '/tmp/../unsafe', 'application_maintenance_parent' => '/tmp/space here'] as $key => $value) {
    $bad = $binding; $bad[$key] = $value;
    $reject(fn() => FullCoreProductionUpgradeContext::validateBinding($bad), 'different or malformed production binding refused');
}
$pair = ['version' => 1, 'source_writers_held' => true];
foreach (['operation', 'candidate_commit', 'root', 'database', 'migration_plan_sha256', 'held_web_sha256',
    'application_held_cron_sha256', 'master_held_cron_sha256', 'writer_inventory_sha256'] as $key) $pair[$key] = $binding[$key];
$pair['database_backup'] = ['native_full_schema_and_data' => true, 'encryption' => 'windows-dpapi-chunked-v1',
    'sql_bytes' => 1024, 'archive_bytes' => 500, 'restoring_database_user' => 'grcudkpvcd',
    'sql_sha256' => hash('sha256', 'sql'), 'readback_sql_sha256' => hash('sha256', 'sql'), 'archive_sha256' => hash('sha256', 'archive')];
$pair['file_owners'] = [];
foreach ([1003, 1004] as $uid) $pair['file_owners'][$uid] = ['owner_uid' => $uid, 'event' => 'prepared',
    'operation' => $binding['operation'], 'candidate_commit' => $binding['candidate_commit'],
    'plan_sha256' => hash('sha256', 'plan-' . $uid), 'prepared_receipt_sha256' => hash('sha256', 'receipt-' . $uid)];
FullCoreProductionUpgradeContext::validateBackupPair($pair, $binding); $assert(true, 'matching two-owner native backup pair contract accepted');
foreach (['source_writers_held' => false, 'operation' => str_repeat('c', 32), 'migration_plan_sha256' => str_repeat('d', 64)] as $key => $value) {
    $bad = $pair; $bad[$key] = $value;
    $reject(fn() => FullCoreProductionUpgradeContext::validateBackupPair($bad, $binding), 'live-source or other-operation backup refused');
}
foreach (['encryption' => 'none', 'sql_bytes' => 0, 'native_full_schema_and_data' => false,
    'readback_sql_sha256' => hash('sha256', 'different'), 'restoring_database_user' => 'other'] as $key => $value) {
    $bad = $pair; $bad['database_backup'][$key] = $value;
    $reject(fn() => FullCoreProductionUpgradeContext::validateBackupPair($bad, $binding), 'incomplete or unverifiable native backup refused');
}
$bad = $pair; unset($bad['file_owners'][1003]);
$reject(fn() => FullCoreProductionUpgradeContext::validateBackupPair($bad, $binding), 'missing other-owner file backup refused');

$challenge = str_repeat('e', 64); $requested = microtime(true) - .1;
$w = ['challenge' => $challenge, 'operation' => $binding['operation'], 'candidate_commit' => $binding['candidate_commit'],
    'migration_plan_sha256' => $binding['migration_plan_sha256'], 'observed_at_unix' => microtime(true),
    'backup_pair_sha256' => $binding['backup_pair_sha256'],
    'master' => ['scope' => 'current-user-scheduler', 'operation' => $binding['operation'], 'root' => $binding['root'],
        'cli_uid' => 1003, 'held_cron_sha256' => $binding['master_held_cron_sha256'], 'receipt_sha256' => $binding['master_maintenance_receipt_sha256']],
    'web' => ['scope' => 'core-web-pool-only', 'operation' => $binding['operation'], 'candidate_commit' => $binding['candidate_commit'],
        'held_web_sha256' => $binding['held_web_sha256'], 'observed_at' => gmdate('c'), 'pool' => [
            'pool_matches' => true, 'self_process_count' => 1, 'self_running' => true, 'self_script_matches' => true,
            'counts_consistent' => true, 'request_pool_quiet' => true, 'listen_queue' => 0, 'other_busy_processes' => 0,
            'active_processes' => 1, 'other_idle_processes' => 2, 'total_processes' => 3, 'accepted_connections' => 50]],
    'writers' => ['inventory_sha256' => $binding['writer_inventory_sha256'], 'registry_unchanged' => true,
        'core_cli_processes' => 0, 'unmanaged_writers' => 0]];
$verify = static fn(array $value, ?int $previous = 49) => FullCoreProductionUpgradeContext::validateWitness($value, $binding, $challenge, $requested, $previous);
$assert($verify($w) === 50, 'fresh observation with advancing pool counter accepted');
$reject(fn() => $verify($w, 50), 'same FPM observation cannot be replayed');
$reject(fn() => $verify($w, 51), 'pool counter reset requires review');
foreach (['challenge' => 'old', 'operation' => str_repeat('f', 32), 'migration_plan_sha256' => str_repeat('f', 64),
    'observed_at_unix' => microtime(true) - 30, 'backup_pair_sha256' => str_repeat('f', 64)] as $key => $value) {
    $bad = $w; $bad[$key] = $value; $reject(fn() => $verify($bad), 'stale or mismatched witness refused');
}
foreach ([NAN, INF, -INF] as $value) {
    $bad = $w; $bad['observed_at_unix'] = $value;
    $reject(fn() => $verify($bad), 'non-finite witness clock is refused');
}
foreach (['cli_uid' => 1004, 'held_cron_sha256' => str_repeat('f', 64), 'receipt_sha256' => str_repeat('f', 64)] as $key => $value) {
    $bad = $w; $bad['master'][$key] = $value; $reject(fn() => $verify($bad), 'other-owner schedule proof must match exactly');
}
foreach (['listen_queue' => 1, 'other_busy_processes' => 1, 'self_running' => false, 'self_script_matches' => false,
    'pool_matches' => false, 'counts_consistent' => false, 'total_processes' => 4, 'other_idle_processes' => -1] as $key => $value) {
    $bad = $w; $bad['web']['pool'][$key] = $value; $reject(fn() => $verify($bad), 'quiet flag cannot conceal busy or inconsistent pool state');
}
$bad = $w; $bad['web']['observed_at'] = gmdate('c', time() - 30);
$reject(fn() => $verify($bad), 'fresh wrapper cannot conceal cached web observation');
foreach (['registry_unchanged' => false, 'core_cli_processes' => 1, 'unmanaged_writers' => 1] as $key => $value) {
    $bad = $w; $bad['writers'][$key] = $value; $reject(fn() => $verify($bad), 'unknown or running writers refuse upgrade');
}
$reject(fn() => new FullCoreProductionUpgradeContext('/nonexistent', str_repeat('a', 64), static fn() => []),
    'ordinary local test process cannot create a production context');
echo "PASS $checks production upgrade binding and witness checks; no database or host changes\n";
