<?php
/** Exercise real historical source retention and stale-review refusal without a database. */
declare(strict_types=1);
require_once __DIR__ . '/../deploy/full_core_migration_plan.php';

$checks = 0;
$assert = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    $checks++; echo "PASS $name\n";
};
$refuses = static function (callable $fn, string $name) use ($assert): void {
    try { $fn(); } catch (RuntimeException $e) { $assert(true, $name); return; }
    $assert(false, $name);
};
$fixture = sys_get_temp_dir() . '/core-migration-plan-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
$package = $fixture . '/candidate'; $live = $fixture . '/deployed';
mkdir($package, 0700); mkdir($live, 0700);
$created = [];
$write = static function (string $root, string $path, string $sql) use (&$created): void {
    $file = $root . '/' . $path;
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
    file_put_contents($file, $sql); $created[$file] = true;
};
$manifest = ['product' => 'full-core-staging', 'commit' => str_repeat('a', 40), 'files' => []];
$rows = [];
foreach (FULL_CORE_RETAINED_MIGRATION_BLOBS as $path => $blob) {
    $old = (string) file_get_contents(__DIR__ . '/fixtures/full-core-migrations/' . basename($path));
    $assert(hash('sha1', 'blob ' . strlen($old) . "\0" . $old) === $blob, 'reviewed historical fixture: ' . basename($path));
    $new = (string) file_get_contents(__DIR__ . '/../' . $path);
    $write($live, $path, $old); $write($package, $path, $new);
    $manifest['files'][$path] = hash('sha256', $new);
    $rows[] = ['filename' => basename($path), 'sha256' => hash('sha256', $old), 'last_error' => null];
}
$applied = 'core/migrations/002_existing.sql';
$pending = 'core/migrations/010_new.sql';
$module = 'modules/example/migrations/001_new.sql';
foreach ([$applied => 'SELECT 2;', $pending => 'SELECT 10;', 'core/migrations/100_later.sql' => 'SELECT 100;', $module => 'SELECT 200;'] as $path => $sql) {
    $write($package, $path, $sql); $manifest['files'][$path] = hash('sha256', $sql);
}
$write($live, $applied, 'SELECT 2;');
$rows[] = ['filename' => basename($applied), 'sha256' => hash('sha256', 'SELECT 2;'), 'last_error' => null];
$before = [];
foreach (array_keys($created) as $file) $before[$file] = hash_file('sha256', $file);
$make = static fn(array $m, array $r, array $l = []) => fullCoreMigrationPlan($m, $package, $live, $r, $l);
$blocked = static function (array $plan, string $reason) use ($assert): void {
    $assert(!$plan['source_review_ready'] && in_array($reason, array_column($plan['blockers'], 'reason'), true), $reason);
};
$plan = $make($manifest, $rows);
$assert($plan['source_review_ready'] && !$plan['activation_authorized'], 'reviewable source never grants activation');
$assert(count(array_filter($plan['ordered_migrations'], static fn($e) => $e['action'] === 'retain_deployed')) === 3,
    'all three known historical migrations retain their successful deployed bytes');
$assert(array_column($plan['ordered_migrations'], 'path') === [$applied,
    'core/migrations/007_subtenant_provisioning.sql', $pending, 'core/migrations/041_csv_mapping_presets.sql',
    'core/migrations/043_simulation_harness.sql', 'core/migrations/100_later.sql', $module], 'core natural order precedes module natural order');
$assert(count(array_filter($plan['ordered_migrations'], static fn($e) => $e['action'] === 'apply_pending')) === 3, 'only unrecorded migrations enter the pending set');
$assert(fullCoreMigrationVerifyPlan($plan['plan_sha256'], $manifest, $package, $live, $rows, []) === $plan, 'unchanged exact review verifies');
$reordered = $manifest; $reordered['files'] = array_reverse($manifest['files'], true);
$assert($make($reordered, array_reverse($rows)) === $plan, 'query and manifest enumeration order do not change the review');
$changedCommit = $manifest; $changedCommit['commit'] = str_repeat('b', 40);
$refuses(fn() => fullCoreMigrationVerifyPlan($plan['plan_sha256'], $changedCommit, $package, $live, $rows, []), 'a different candidate cannot reuse the review');
$runtimeChange = $manifest; $runtimeChange['files']['core/example.php'] = str_repeat('d', 64);
$refuses(fn() => fullCoreMigrationVerifyPlan($plan['plan_sha256'], $runtimeChange, $package, $live, $rows, []), 'changed runtime manifest invalidates the review');
$failed = $rows; $failed[0]['last_error'] = 'sensitive exception detail';
$failedPlan = $make($manifest, $failed);
$blocked($failedPlan, 'failed_migration_requires_recovery');
$assert(!str_contains(json_encode($failedPlan), 'sensitive exception detail'), 'failure evidence excludes raw database error text');
$failed = $rows; $failed[0]['sha256'] = 'FAIL:' . str_repeat('d', 59);
$blocked($make($manifest, $failed), 'failed_migration_requires_recovery');
$blocked($make($manifest, $rows, [$pending => hash('sha256', 'SELECT 10;')]), 'legacy_only_requires_review');
$blocked($make($manifest, $rows, [$pending => hash('sha256', 'SELECT 11;')]), 'legacy_history_conflict');
$blocked($make($manifest, $rows, [$applied => hash('sha256', 'SELECT 3;')]), 'migration_ledgers_disagree');
$assert($make($manifest, $rows, [$applied => hash('sha256', 'SELECT 2;')])['source_review_ready'], 'matching second-ledger evidence is accepted');
$history = fullCoreMigrationHistory();
$priorPath = 'core/migrations/003_mail_service.sql';
$currentSql = (string) file_get_contents(__DIR__ . '/../' . $priorPath);
$write($package, $priorPath, $currentSql); $write($live, $priorPath, $currentSql);
$priorManifest = $manifest; $priorManifest['files'][$priorPath] = hash('sha256', $currentSql);
$priorRows = $rows; $priorRows[] = ['filename' => basename($priorPath), 'sha256' => corefluxMigrationHash($currentSql), 'last_error' => null];
$priorLedger = [$priorPath => $history['prior_legacy'][$priorPath]['recorded_sha256']];
$priorPlan = $make($priorManifest, $priorRows, $priorLedger);
$priorEntry = array_values(array_filter($priorPlan['ordered_migrations'], static fn($e) => $e['path'] === $priorPath))[0];
$assert($priorPlan['source_review_ready'] && $priorEntry['action'] === 'keep_applied'
    && $priorEntry['legacy_history']['action'] === 'preserve_reviewed_prior_version', 'known older ledger version remains history without reapplying successful SQL');
$blocked($make($priorManifest, $priorRows, [$priorPath => str_repeat('c', 64)]), 'migration_ledgers_disagree');
$priorRows[count($priorRows)-1]['last_error'] = 'failed';
$blocked($make($priorManifest, $priorRows, $priorLedger), 'failed_migration_requires_recovery');
$retiredPath = 'core/migrations/112_people_graph.sql';
$retiredRows = $rows; $retiredRows[] = ['filename' => basename($retiredPath), 'sha256' => $history['retired'][$retiredPath]['recorded_sha256'], 'last_error' => null];
$retiredPlan = $make($manifest, $retiredRows);
$assert($retiredPlan['source_review_ready'] && count($retiredPlan['retired_history']) === 1
    && count($retiredPlan['ordered_migrations']) === count($plan['ordered_migrations']), 'known retired history is preserved without resurrecting its absent SQL');
$refuses(fn() => fullCoreMigrationVerifyPlan($plan['plan_sha256'], $manifest, $package, $live, $retiredRows, []), 'retired ledger history remains part of the exact review fingerprint');
$blocked($make($manifest, $retiredRows, [$retiredPath => str_repeat('d', 64)]), 'recorded_migration_not_packaged');
$badRetired = $retiredRows; $badRetired[count($badRetired)-1]['sha256'] = str_repeat('d', 64);
$blocked($make($manifest, $badRetired), 'recorded_migration_not_packaged');
$badRetired = $retiredRows; $badRetired[count($badRetired)-1]['last_error'] = 'failed';
$blocked($make($manifest, $badRetired), 'recorded_migration_not_packaged');
$write($live, $retiredPath, 'SELECT 112;');
$blocked($make($manifest, $retiredRows), 'recorded_migration_not_packaged');
unlink($live . '/' . $retiredPath); unset($created[$live . '/' . $retiredPath]);
$workflowPath = 'core/migrations/156_billing_invoice_approval_attempts.sql';
$workflowSuccessor = $history['retired'][$workflowPath]['superseded_by'];
$workflowOld = (string)file_get_contents(__DIR__.'/fixtures/full-core-migrations/'.basename($workflowPath));
$workflowNew = (string)file_get_contents(__DIR__.'/../'.$workflowSuccessor);
$workflowManifest = $manifest;
$write($package, $workflowSuccessor, $workflowNew);
$workflowManifest['files'][$workflowSuccessor] = hash('sha256', $workflowNew);
$workflowRows = $rows;
$workflowRows[] = ['filename'=>basename($workflowPath), 'sha256'=>$history['retired'][$workflowPath]['recorded_sha256'], 'last_error'=>null];
$write($live, $workflowPath, $workflowOld);
$workflowPlan = $make($workflowManifest, $workflowRows);
$assert($workflowPlan['source_review_ready'] && count($workflowPlan['retired_history']) === 1
    && $workflowPlan['retired_history'][0]['deployed_sha256'] === hash('sha256', $workflowOld),
    'known applied workflow source and successful history remain pinned while its successor is pending');
$blocked($make($manifest, $workflowRows), 'recorded_migration_not_packaged');
$blocked($make($workflowManifest, $rows), 'unrecorded_retired_source_requires_review');
$workflowBad = $workflowRows; $workflowBad[count($workflowBad)-1]['last_error'] = 'failed';
$blocked($make($workflowManifest, $workflowBad), 'recorded_migration_not_packaged');
$write($live, $workflowPath, $workflowOld."\n-- changed\n");
$blocked($make($workflowManifest, $workflowRows), 'recorded_migration_not_packaged');
$write($live, $workflowPath, str_replace("\n", "\r\n", $workflowOld));
$assert($make($workflowManifest, $workflowRows)['source_review_ready'], 'known applied retired workflow source accepts deployment line endings');
unlink($live.'/'.$workflowPath); unset($created[$live.'/'.$workflowPath]);
$assert($make($workflowManifest, $workflowRows)['source_review_ready'], 'removed retired workflow source retains its successful ledger history');
unlink($package.'/'.$workflowSuccessor); unset($created[$package.'/'.$workflowSuccessor]);
$unknown = $rows; $unknown[] = ['filename' => '099_missing.sql', 'sha256' => str_repeat('c', 64), 'last_error' => null];
$blocked($make($manifest, $unknown), 'recorded_migration_not_packaged');
$refuses(fn() => $make($manifest, array_merge($rows, [$rows[0]])), 'duplicate ledger identities refuse the plan');
$malformed = $rows; unset($malformed[0]['last_error']);
$refuses(fn() => $make($manifest, $malformed), 'incomplete ledger evidence refuses the plan');
$write($live, $applied, 'SELECT 3;');
$blocked($make($manifest, $rows), 'deployed_source_does_not_match_successful_history');
$forged = $rows; $forged[3]['sha256'] = hash('sha256', 'SELECT 3;');
$blocked($make($manifest, $forged), 'unreviewed_applied_source_change');
$write($live, $applied, 'SELECT 2;');
$write($package, $pending, 'SELECT 11;');
$refuses(fn() => $make($manifest, $rows), 'changed candidate bytes fail before a plan is emitted');
$write($package, $pending, 'SELECT 10;');
$adopted = $rows; $adopted[] = ['filename' => basename($pending), 'sha256' => hash('sha256', 'SELECT 10;'), 'last_error' => null];
$write($live, $pending, 'SELECT 10;');
$refuses(fn() => fullCoreMigrationVerifyPlan($plan['plan_sha256'], $manifest, $package, $live, $adopted, []), 'migration applied since review invalidates the plan');
unlink($live . '/' . $pending); unset($created[$live . '/' . $pending]);
$oldPath = array_key_first(FULL_CORE_RETAINED_MIGRATION_BLOBS);
$oldBytes = (string) file_get_contents($live . '/' . $oldPath);
$write($live, $oldPath, str_replace("\n", "\r\n", $oldBytes));
$crlfRows = $rows; $crlfRows[0]['sha256'] = hash_file('sha256', $live . '/' . $oldPath);
$crlf = $make($manifest, $crlfRows);
$assert($crlf['source_review_ready'], 'reviewed historical Git blob survives CRLF deployment packaging');
$refuses(fn() => fullCoreMigrationVerifyPlan($plan['plan_sha256'], $manifest, $package, $live, $crlfRows, []), 'changed exact deployed bytes still require a refreshed review');
$write($live, $oldPath, $oldBytes . "\n-- Unreviewed change\n");
$forged = $rows; $forged[0]['sha256'] = hash_file('sha256', $live . '/' . $oldPath);
$blocked($make($manifest, $forged), 'unreviewed_applied_source_change');
$write($live, $oldPath, $oldBytes);
$refuses(fn() => fullCoreMigrationRead($package, 'core/migrations/../../outside.sql'), 'path traversal is refused');
$unsafeManifest = $manifest; $unsafeManifest['files']['core/migrations/../outside.sql'] = str_repeat('e', 64);
$refuses(fn() => $make($unsafeManifest, $rows), 'unsafe manifest path cannot be silently omitted from review');
$refuses(fn() => fullCoreMigrationPlan($manifest, $package, $package, $rows, []), 'deployed and candidate roots cannot alias');
if (PHP_OS_FAMILY !== 'Windows') {
    $linked = $fixture . '/linked'; symlink($live, $linked);
    $refuses(fn() => fullCoreMigrationPlan($manifest, $package, $linked, $rows, []), 'linked deployed root is refused');
    unlink($linked);
    $linkedFile = $package . '/core/migrations/011_linked.sql'; symlink($live . '/' . $applied, $linkedFile);
    $linkedManifest = $manifest; $linkedManifest['files']['core/migrations/011_linked.sql'] = hash('sha256', 'SELECT 2;');
    $refuses(fn() => $make($linkedManifest, $rows), 'linked migration file is refused'); unlink($linkedFile);
}
$after = [];
foreach (array_keys($before) as $file) $after[$file] = hash_file('sha256', $file);
$assert($before === $after && $make($manifest, $rows) === $plan, 'inspection preserves all original source and ledger evidence');
foreach (array_keys($created) as $file) unlink($file);
$directories = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($directories as $entry) if ($entry->isDir()) rmdir($entry->getPathname());
rmdir($fixture);
echo "Migration plan: $checks checks passed.\n";
