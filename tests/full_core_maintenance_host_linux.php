<?php
/** Actual crontab/file operations inside the dedicated disposable Docker fixture only. */
declare(strict_types=1);
require_once __DIR__ . '/../deploy/full_core_maintenance_host.php';

$fixture = getenv('CORE_MAINTENANCE_HOST_FIXTURE');
if (PHP_OS_FAMILY !== 'Linux' || !is_file('/.dockerenv') || !is_file('/run/core-maintenance-fixture')
    || (posix_getpwuid(posix_geteuid())['name'] ?? '') !== 'coremaintfixture'
    || !is_string($fixture) || !preg_match('~^/tmp/coreflux-host-maintenance-fixture-[a-f0-9]{16}$~D', $fixture)
    || realpath($fixture) !== $fixture) throw new RuntimeException('Dedicated disposable host fixture required.');
$root = $fixture . '/public_html'; $private = $fixture . '/private';
if (($argv[1] ?? '') === '--recover') {
    $host = new FullCoreMaintenanceHost($root, $private); $host->restore($argv[2]); exit(0);
}
if (($argv[1] ?? '') === '--lock') {
    try { $host = new FullCoreMaintenanceHost($root, $private); exit(3); }
    catch (RuntimeException $error) { exit(0); }
}

$checks = 0;
$assert = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    $checks++; echo "PASS $name\n";
};
$refuses = static function (callable $fn, string $name) use ($assert): void {
    try { $fn(); } catch (RuntimeException $error) { $assert(true, $name); return; }
    $assert(false, $name);
};
$command = static function (array $command): array {
    $process = proc_open($command, [0 => ['file','/dev/null','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!$process) throw new RuntimeException('Fixture process failed.');
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
$cronInstall = static function (string $bytes) use ($fixture, $command): void {
    file_put_contents($fixture . '/fixture.crontab', $bytes); chmod($fixture . '/fixture.crontab', 0600);
    if ($command(['/usr/bin/crontab', $fixture . '/fixture.crontab'])[0] !== 0) throw new RuntimeException('Fixture crontab install failed.');
};
$original = "# original synthetic config\nOptions -Indexes\n";
$candidateRouting = "# reviewed new synthetic routing\nOptions -Indexes\nDirectoryIndex reviewed.php\n";
$candidateRevision = str_repeat('c', 40); $candidateManifest = str_repeat('d', 64);
if (($argv[1] ?? '') === '--prepare-activation-exit') {
    $host = new FullCoreMaintenanceHost($root, $private);
    $host->prepareActivation($argv[2], $candidateRouting, hash('sha256', $candidateRouting), $candidateRevision, $candidateManifest);
    exit(74);
}
if (($argv[1] ?? '') === '--candidate-only-exit') {
    $host = new FullCoreMaintenanceHost($root, $private); $host->assertHeld($argv[2]);
    // Simulate the persisted first activation boundary: new web bytes, scheduler still held.
    // This exercises restart state handling; it does not claim to kill between the adapter's two native calls.
    file_put_contents($root . '/.htaccess', $candidateRouting);
    exit(75);
}
if (($argv[1] ?? '') === '--activate') {
    $host = new FullCoreMaintenanceHost($root, $private); $host->activate($argv[2], $candidateRevision, $candidateManifest); exit(0);
}
$cronLines = ['# synthetic only', 'MAILTO=""', 'SHELL=/bin/sh',
    '* * * * * php ' . $root . '/cron/a.php',
    '0 * * * * cd ' . $root . ' && php cron/b.php; printf "preserve\\%s" value',
    '0 0 * * * php /other-fixture/cron/keep.php'];
$cron = implode("\n", $cronLines) . "\n";
$review = [];
foreach ([4 => 'pause', 5 => 'pause', 6 => 'keep'] as $line => $action) {
    $review[$line] = ['sha256' => hash('sha256', $cronLines[$line - 1]), 'action' => $action];
}
if (($argv[1] ?? '') === '--pause-exit') {
    $host = new FullCoreMaintenanceHost($root, $private);
    $host->pause($argv[2], $host->webSnapshot(), hash('sha256', $cron), $review);
    exit(73); // No destructor restoration: process exit must leave the guard and durable recovery files.
}
if (($argv[1] ?? '') !== '--confirm-disposable-container') throw new RuntimeException('Explicit fixture confirmation required.');
set_exception_handler(static function (Throwable $error) use ($private): void {
    // This executable only runs with generated synthetic commands inside its isolated container.
    foreach (glob($private . '/scheduler-*.stderr') as $path) echo 'Fixture scheduler diagnostic: ', file_get_contents($path), "\n";
    fwrite(STDERR, (string)$error . "\n"); exit(1);
});
file_put_contents($root . '/.htaccess', $original); chmod($root . '/.htaccess', 0644);
// Use a supplemental group different from the process primary group; replacement must retain it.
if (!chgrp($root . '/.htaccess', 'coremaintweb')) throw new RuntimeException('Fixture supplemental group unavailable.');
$cronInstall($cron);
$host = new FullCoreMaintenanceHost($root, $private); $web = $host->webSnapshot();
$assert($web['gid'] !== posix_getegid(), 'fixture exercises different original file group');
$assert($host->crontab() === $cron, 'native crontab preserves exact original commands');
$assert($command([PHP_BINARY, __FILE__, '--lock'])[0] === 0, 'second process cannot enter locked maintenance');
$staleWeb = $web; $staleWeb['sha256'] = str_repeat('a', 64);
$refuses(fn() => $host->pause(str_repeat('a',32), $staleWeb, hash('sha256',$cron), $review), 'stale web review changes nothing');
$refuses(fn() => $host->pause(str_repeat('b',32), $web, str_repeat('a',64), $review), 'stale scheduler review changes nothing');
$assert($host->webSnapshot() === $web && $host->crontab() === $cron, 'failed review preserves both originals');
$operation = bin2hex(random_bytes(16));
$result = $host->pause($operation, $web, hash('sha256',$cron), $review);
$heldCron = $host->crontab(); $heldWeb = file_get_contents($root . '/.htaccess');
$assert($result['paused_jobs'] === 2 && $result['retained_jobs'] === 1 && $result['business_writers_drained'] === false,
    'real scheduler pause has reviewed scope and does not claim worker drain');
$host->assertHeld($operation);
$assert(str_contains($heldCron, '# coreflux-maintenance-' . $operation . ' ' . $cronLines[3])
    && str_contains($heldCron, "\n" . $cronLines[5] . "\n"), 'native scheduler disables Core lines and keeps unrelated line');
$metadata = $host->webSnapshot(); unset($metadata['sha256']); $originalMetadata = $web; unset($originalMetadata['sha256']);
$assert($metadata === $originalMetadata, 'temporary guard preserves UID GID and mode');
$opPath = $private . '/maintenance-' . $operation;
$assert((fileperms($opPath) & 0777) === 0700 && (fileperms($opPath . '/original.crontab') & 0777) === 0600
    && (fileperms($opPath . '/receipt.json') & 0777) === 0600, 'commands and recovery receipt remain private');
$cronInstall($heldCron . "# concurrent edit\n");
$refuses(fn() => $host->restore($operation), 'concurrent scheduler edit refuses recovery');
$assert(file_get_contents($root . '/.htaccess') === $heldWeb && str_ends_with($host->crontab(), "# concurrent edit\n"),
    'refused recovery retains web hold and concurrent edit');
$cronInstall($heldCron);
file_put_contents($root . '/.htaccess', $heldWeb . "# concurrent edit\n");
$refuses(fn() => $host->restore($operation), 'concurrent web edit refuses scheduler resume');
$assert($host->crontab() === $heldCron, 'scheduler remains held after web conflict');
file_put_contents($root . '/.htaccess', $heldWeb);
$receipt = file_get_contents($opPath . '/original.crontab');
file_put_contents($opPath . '/original.crontab', $receipt . "# corruption\n");
$refuses(fn() => $host->restore($operation), 'corrupted private recovery copy refuses resume');
file_put_contents($opPath . '/original.crontab', $receipt);
$host->restore($operation);
$assert($host->webSnapshot() === $web && $host->crontab() === $cron, 'exact native scheduler and file metadata restore');
$host->restore($operation);
$assert($host->webSnapshot() === $web && $host->crontab() === $cron, 'repeated explicit recovery is unchanged');
$refuses(fn() => $host->pause($operation, $web, hash('sha256',$cron), $review), 'completed operation cannot be reused');
unset($host);
$interrupted = bin2hex(random_bytes(16));
$assert($command([PHP_BINARY, __FILE__, '--pause-exit', $interrupted])[0] === 73, 'child exits after real pause with no automatic resume');
$host = new FullCoreMaintenanceHost($root, $private); $host->assertHeld($interrupted); unset($host);
$assert($command([PHP_BINARY, __FILE__, '--recover', $interrupted])[0] === 0, 'new process recovers using only durable receipt');
$host = new FullCoreMaintenanceHost($root, $private);
$assert($host->webSnapshot() === $web && $host->crontab() === $cron, 'process-loss recovery restores original scheduler and metadata');

$release = bin2hex(random_bytes(16)); $host->pause($release, $web, hash('sha256', $cron), $review);
$refuses(fn() => $host->activate($release, $candidateRevision, $candidateManifest), 'activation without prepared release refuses');
$refuses(fn() => $host->prepareActivation($release, $candidateRouting, str_repeat('0',64), $candidateRevision, $candidateManifest),
    'routing hash mismatch refuses preparation');
$refuses(fn() => $host->prepareActivation($release, $heldWeb, hash('sha256',$heldWeb), $candidateRevision, $candidateManifest),
    'maintenance routing cannot be prepared as a release');
$prepared = $host->prepareActivation($release, $candidateRouting, hash('sha256',$candidateRouting), $candidateRevision, $candidateManifest);
$assert($host->prepareActivation($release, $candidateRouting, hash('sha256',$candidateRouting), $candidateRevision, $candidateManifest) === $prepared,
    'identical preparation is idempotent while writers remain held');
$host->assertHeld($release);
$refuses(fn() => $host->prepareActivation($release, $candidateRouting, hash('sha256',$candidateRouting), str_repeat('e',40), $candidateManifest),
    'prepared release identity cannot be replaced');
$refuses(fn() => $host->activate($release, $candidateRevision, str_repeat('e',64)), 'different manifest refuses reopening');
$releasePath = $private . '/maintenance-' . $release;
file_put_contents($releasePath . '/candidate.htaccess', $candidateRouting . '# corruption');
$refuses(fn() => $host->activate($release, $candidateRevision, $candidateManifest), 'corrupted candidate routing refuses activation');
file_put_contents($releasePath . '/candidate.htaccess', $candidateRouting);
$releaseHeldCron = $host->crontab(); $releaseHeldWeb = file_get_contents($root . '/.htaccess');
$cronInstall($releaseHeldCron . "# concurrent edit\n");
$refuses(fn() => $host->activate($release, $candidateRevision, $candidateManifest), 'concurrent scheduler edit refuses activation');
$assert(file_get_contents($root . '/.htaccess') === $releaseHeldWeb, 'scheduler conflict leaves the web hold intact');
$cronInstall($releaseHeldCron);
file_put_contents($root . '/.htaccess', $releaseHeldWeb . "# concurrent edit\n");
$refuses(fn() => $host->activate($release, $candidateRevision, $candidateManifest), 'concurrent routing edit refuses activation');
$assert($host->crontab() === $releaseHeldCron, 'routing conflict leaves the scheduler held');
file_put_contents($root . '/.htaccess', $releaseHeldWeb);
$host->activate($release, $candidateRevision, $candidateManifest);
$candidateWeb = $web; $candidateWeb['sha256'] = hash('sha256',$candidateRouting);
$assert($host->webSnapshot() === $candidateWeb && $host->crontab() === $cron,
    'successful release installs new routing with original UID GID mode and exact scheduler');
$host->activate($release, $candidateRevision, $candidateManifest);
$assert($host->webSnapshot() === $candidateWeb && $host->crontab() === $cron, 'completed activation retries do not restore old routing');
$assert((fileperms($releasePath . '/activation.json') & 0777) === 0600
    && (fileperms($releasePath . '/candidate.htaccess') & 0777) === 0600, 'candidate binding and routing remain private');
$host->restore($release);
$assert($host->webSnapshot() === $web && $host->crontab() === $cron, 'explicit release recovery restores original routing and scheduler');
$refuses(fn() => $host->activate($release, $candidateRevision, $candidateManifest), 'stale activation cannot resurrect a recovered release');
$host->restore($release);
$assert($host->webSnapshot() === $web && $host->crontab() === $cron, 'repeated release recovery remains idempotent');

$preparedExit = bin2hex(random_bytes(16)); $host->pause($preparedExit, $web, hash('sha256',$cron), $review); unset($host);
$assert($command([PHP_BINARY, __FILE__, '--prepare-activation-exit', $preparedExit])[0] === 74,
    'child exits after durable preparation without reopening writers');
$host = new FullCoreMaintenanceHost($root, $private); $host->assertHeld($preparedExit); unset($host);
$assert($command([PHP_BINARY, __FILE__, '--candidate-only-exit', $preparedExit])[0] === 75,
    'fixture simulates process exit at candidate-routing boundary');
$host = new FullCoreMaintenanceHost($root, $private);
$assert($host->webSnapshot() === $candidateWeb && $host->crontab() !== $cron, 'partial activation contains new routing and held scheduler');
unset($host);
$assert($command([PHP_BINARY, __FILE__, '--activate', $preparedExit])[0] === 0, 'new process completes the exact prepared partial activation');
$host = new FullCoreMaintenanceHost($root, $private);
$assert($host->webSnapshot() === $candidateWeb && $host->crontab() === $cron, 'partial activation recovery finishes the expected release');
$host->restore($preparedExit);

$sameRouting = bin2hex(random_bytes(16)); $host->pause($sameRouting, $web, hash('sha256',$cron), $review);
$host->prepareActivation($sameRouting, $original, hash('sha256',$original), $candidateRevision, $candidateManifest);
$host->activate($sameRouting, $candidateRevision, $candidateManifest); $host->restore($sameRouting);
$refuses(fn() => $host->activate($sameRouting, $candidateRevision, $candidateManifest),
    'recovery intent fences stale activation even when old and new routing are identical');

$cronInstall('');
$empty = bin2hex(random_bytes(16)); $host->pause($empty, $web, hash('sha256',''), []); $host->restore($empty);
$assert($host->crontab() === '' && $host->webSnapshot() === $web, 'installed empty scheduler survives pause and recovery');
chmod($root . '/.htaccess', 0664); $groupWritable = $host->webSnapshot();
$groupOperation = bin2hex(random_bytes(16)); $host->pause($groupOperation, $groupWritable, hash('sha256',''), []);
$host->restore($groupOperation);
$assert($host->webSnapshot() === $groupWritable, 'existing 0664 web mode is preserved exactly');
unset($host);
rename($root . '/.htaccess', $root . '/original-file'); symlink($root . '/original-file', $root . '/.htaccess');
$host = new FullCoreMaintenanceHost($root, $private);
$refuses(fn() => $host->webSnapshot(), 'linked web configuration refused');
unlink($root . '/.htaccess'); rename($root . '/original-file', $root . '/.htaccess');
echo "PASS $checks real host maintenance checks; isolated container only, no database migration\n";
