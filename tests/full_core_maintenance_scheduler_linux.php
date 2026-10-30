<?php
/** Native scheduler operations as a different UID from the web owner, in disposable Docker only. */
declare(strict_types=1);
require_once __DIR__ . '/../deploy/full_core_maintenance_host.php';
$fixture = getenv('CORE_MAINTENANCE_HOST_FIXTURE');
if (PHP_OS_FAMILY !== 'Linux' || !is_file('/.dockerenv') || !is_file('/run/core-maintenance-fixture')
    || (posix_getpwuid(posix_geteuid())['name'] ?? '') !== 'coremaintscheduler'
    || !is_string($fixture) || !preg_match('~^/tmp/coreflux-host-maintenance-fixture-[a-f0-9]{16}$~D', $fixture)
    || realpath($fixture) !== $fixture) throw new RuntimeException('Dedicated two-account fixture required.');
$root = $fixture . '/public_html'; $private = $fixture . '/scheduler-private';
$command = static function (array $args): array {
    $process = proc_open($args, [0 => ['file','/dev/null','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!$process) throw new RuntimeException('Fixture process failed.');
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
$install = static function (string $bytes) use ($private, $command): void {
    $file = $private . '/fixture.crontab'; file_put_contents($file, $bytes); chmod($file, 0600);
    if ($command(['/usr/bin/crontab', $file])[0] !== 0) throw new RuntimeException('Fixture schedule install failed.');
};
$lines = ['# synthetic schedule owned separately from web files', 'MAILTO=""',
    '* * * * * php ' . $root . '/cron/a.php',
    '0 * * * * cd ' . $root . ' && php cron/b.php; printf "preserve\\%s" value',
    '0 0 * * * php /other-fixture/keep.php'];
$cron = implode("\n", $lines) . "\n";
$review = [];
foreach ([3 => 'pause', 4 => 'pause', 5 => 'keep'] as $line => $action) {
    $review[$line] = ['sha256' => hash('sha256', $lines[$line - 1]), 'action' => $action];
}
if (($argv[1] ?? '') === '--pause-exit') {
    $host = new FullCoreMaintenanceHost($root, $private);
    $host->pauseScheduler($argv[2], hash('sha256', $cron), $review); exit(73);
}
if (($argv[1] ?? '') === '--recover') {
    $host = new FullCoreMaintenanceHost($root, $private); $host->restoreScheduler($argv[2]); exit(0);
}
if (($argv[1] ?? '') === '--lock') {
    try { new FullCoreMaintenanceHost($root, $private); exit(3); }
    catch (RuntimeException $error) { exit(0); }
}
if (($argv[1] ?? '') !== '--confirm-disposable-container') throw new RuntimeException('Explicit fixture confirmation required.');
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $message);
    $checks++; echo "PASS $message\n";
};
$refuses = static function (callable $call, string $message) use ($assert): void {
    try { $call(); } catch (RuntimeException $error) { $assert(true, $message); return; }
    $assert(false, $message);
};
$webState = static function () use ($root): array {
    clearstatcache(true, $root . '/.htaccess'); $stat = stat($root . '/.htaccess');
    return ['uid' => $stat['uid'], 'gid' => $stat['gid'], 'mode' => $stat['mode'] & 07777,
        'sha256' => hash_file('sha256', $root . '/.htaccess')];
};
$webBefore = $webState();
$assert($webBefore['uid'] !== posix_geteuid() && $webBefore['mode'] === 0664,
    'fixture has a distinct web owner and group-writable web configuration');
$install($cron);
$host = new FullCoreMaintenanceHost($root, $private);
$refuses(fn() => $host->webSnapshot(), 'scheduler account cannot claim web-file ownership');
$assert($command([PHP_BINARY, __FILE__, '--lock'])[0] === 0, 'second scheduler controller cannot bypass the account lock');
$refuses(fn() => $host->pauseScheduler(bin2hex(random_bytes(16)), str_repeat('a',64), $review),
    'stale schedule review refuses any change');
$assert($host->crontab() === $cron && $webState() === $webBefore, 'stale review preserves both accounts');
$operation = bin2hex(random_bytes(16));
$result = $host->pauseScheduler($operation, hash('sha256', $cron), $review);
$held = $host->crontab();
$assert($result['cli_uid'] === posix_geteuid() && $result['root'] === $root && $result['operation'] === $operation
    && $result['scope'] === 'current-user-scheduler' && $result['paused_jobs'] === 2 && $result['retained_jobs'] === 1
    && $result['web_ingress_held'] === false && $result['business_writers_drained'] === false,
    'separate schedule hold identifies its exact owner and does not claim web hold or worker drain');
$assert(str_contains($held, '# coreflux-maintenance-' . $operation . ' ' . $lines[2])
    && str_contains($held, "\n" . $lines[4] . "\n"), 'native schedule holds reviewed Core jobs and preserves unrelated work');
$assert($webState() === $webBefore, 'scheduler-only pause leaves web bytes and metadata untouched');
$refuses(fn() => $host->pauseScheduler(bin2hex(random_bytes(16)), hash('sha256', $held), []),
    'unresolved hold cannot be adopted as another operation baseline');
$opPath = $private . '/scheduler-' . $operation;
$assert((fileperms($opPath) & 0777) === 0700 && (fileperms($opPath . '/original.crontab') & 0777) === 0600
    && (fileperms($opPath . '/receipt.json') & 0777) === 0600, 'separate account commands and receipts remain private');
$install($held . "# concurrent edit\n");
$refuses(fn() => $host->restoreScheduler($operation), 'concurrent scheduler edit blocks restoration');
$assert(str_ends_with($host->crontab(), "# concurrent edit\n"), 'restoration preserves the concurrent edit');
$install($held);
$receiptBytes = file_get_contents($opPath . '/receipt.json');
foreach (['cli_uid' => $webBefore['uid'], 'root' => '/other/public_html', 'scope' => 'web', 'paused_jobs' => 999] as $field => $value) {
    $changed = json_decode($receiptBytes, true, 512, JSON_THROW_ON_ERROR); $changed[$field] = $value;
    file_put_contents($opPath . '/receipt.json', json_encode($changed));
    $refuses(fn() => $host->restoreScheduler($operation), 'mismatched scheduler receipt refuses restoration: ' . $field);
}
file_put_contents($opPath . '/receipt.json', $receiptBytes);
file_put_contents($opPath . '/original.crontab', $cron . "# corrupted\n");
$refuses(fn() => $host->restoreScheduler($operation), 'corrupt original schedule cannot be restored');
file_put_contents($opPath . '/original.crontab', $cron);
$host->restoreScheduler($operation); $host->restoreScheduler($operation);
$assert($host->crontab() === $cron && $webState() === $webBefore, 'exact scheduler restoration is idempotent and preserves the other owner');
$refuses(fn() => $host->pauseScheduler($operation, hash('sha256', $cron), $review), 'completed scheduler operation cannot be reused');
unset($host);
$interrupted = bin2hex(random_bytes(16));
$assert($command([PHP_BINARY, __FILE__, '--pause-exit', $interrupted])[0] === 73, 'controller exit leaves native schedule held');
$host = new FullCoreMaintenanceHost($root, $private); $host->assertSchedulerHeld($interrupted); unset($host);
$assert($command([PHP_BINARY, __FILE__, '--recover', $interrupted])[0] === 0, 'new process restores the exact prior account schedule');
$host = new FullCoreMaintenanceHost($root, $private);
$assert($host->crontab() === $cron && $webState() === $webBefore, 'process-loss recovery preserves original schedule and web ownership');
$install(''); $empty = bin2hex(random_bytes(16));
$emptyResult = $host->pauseScheduler($empty, hash('sha256',''), []); $host->restoreScheduler($empty);
$assert($emptyResult['paused_jobs'] === 0 && $host->crontab() === '', 'installed empty scheduler remains empty after explicit recovery');
$assert($command(['/usr/bin/crontab', '-l'])[0] === 0, 'separate-owner installed empty table remains installed');
$assert($command(['/usr/bin/crontab', '-r'])[0] === 0, 'remove only the disposable scheduler-account fixture table');
$absent = bin2hex(random_bytes(16));
$absentResult = $host->pauseScheduler($absent, hash('sha256',''), []);
$host->assertSchedulerHeld($absent);
$assert($absentResult['paused_jobs'] === 0 && $command(['/usr/bin/crontab', '-l'])[0] === 1,
    'separate-owner pause leaves native absent scheduler untouched');
$host->restoreScheduler($absent); $host->restoreScheduler($absent);
$assert($command(['/usr/bin/crontab', '-l'])[0] === 1 && $webState() === $webBefore,
    'separate-owner recovery preserves absence and unrelated web ownership');
$install('* * * * * true' . "\n");
$refuses(fn() => $host->restoreScheduler($absent), 'new separate-owner schedule blocks absent-table recovery');
$assert($host->crontab() === '* * * * * true' . "\n", 'new separate-owner schedule remains intact');
echo "PASS $checks separate-owner native scheduler checks; disposable container only\n";
