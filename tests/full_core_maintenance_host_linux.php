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
$cronInstall('');
$empty = bin2hex(random_bytes(16)); $host->pause($empty, $web, hash('sha256',''), []); $host->restore($empty);
$assert($host->crontab() === '' && $host->webSnapshot() === $web, 'installed empty scheduler survives pause and recovery');
unset($host);
rename($root . '/.htaccess', $root . '/original-file'); symlink($root . '/original-file', $root . '/.htaccess');
$host = new FullCoreMaintenanceHost($root, $private);
$refuses(fn() => $host->webSnapshot(), 'linked web configuration refused');
unlink($root . '/.htaccess'); rename($root . '/original-file', $root . '/.htaccess');
echo "PASS $checks real host maintenance checks; isolated container only, no database migration\n";
