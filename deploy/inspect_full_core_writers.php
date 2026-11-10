<?php
/** Read-only native writer evidence. Never asserts a maintenance hold or changes a scheduler. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const CORE_WRITER_ROOT = '/home/1516771.cloudwaysapps.com/grcudkpvcd/public_html';

function coreWriterStat(string $bytes): array
{
    $end = strrpos($bytes, ') ');
    if ($end === false || !preg_match('/^([1-9][0-9]*) \(/', $bytes, $pid)) throw new RuntimeException('Invalid process identity.');
    $fields = preg_split('/\s+/', trim(substr($bytes, $end + 2)));
    if (count($fields) < 20 || !ctype_digit($fields[1]) || !ctype_digit($fields[19])) throw new RuntimeException('Incomplete process identity.');
    return ['pid' => (int)$pid[1], 'ppid' => (int)$fields[1], 'state' => $fields[0], 'start_ticks' => $fields[19]];
}

function coreWriterProcess(int $pid, callable $read): array
{
    $base = '/proc/' . $pid;
    $before = coreWriterStat($read($base . '/stat'));
    $status = $read($base . '/status');
    $command = $read($base . '/cmdline');
    $statusAfter = $read($base . '/status');
    $commandAfter = $read($base . '/cmdline');
    $after = coreWriterStat($read($base . '/stat'));
    // State may change normally. PID, start time and ancestry must remain stable.
    foreach (['pid', 'ppid', 'start_ticks'] as $key) {
        if ($before[$key] !== $after[$key]) throw new RuntimeException('Process changed during observation.');
    }
    if ($after['pid'] !== $pid || !preg_match('/^Uid:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*$/m', $status, $uids)
        || !preg_match('/^PPid:\s+(\d+)\s*$/m', $status, $ppid) || (int)$ppid[1] !== $after['ppid']) {
        throw new RuntimeException('Process owner or ancestry unavailable.');
    }
    if (!preg_match('/^Uid:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*$/m', $statusAfter, $uidsAfter)
        || array_slice($uids, 1) !== array_slice($uidsAfter, 1) || $command !== $commandAfter) {
        throw new RuntimeException('Process credentials or command changed during observation.');
    }
    $uidList = array_map('intval', array_slice($uids, 1));
    $owned = count(array_intersect($uidList, [1003, 1004])) > 0;
    $coreReference = str_contains($command, '/grcudkpvcd/') || str_contains($command, 'grcudkpvcd-');
    // Do not disclose arguments: they can contain credentials even on unrelated processes.
    return $after + ['uids' => $uidList, 'core_account' => $owned, 'core_reference' => $coreReference,
        'command_sha256' => hash('sha256', $command), 'command_bytes' => strlen($command),
        'empty_command' => $command === '',
        'core_pool_title' => rtrim($command, "\0") === 'php-fpm: pool grcudkpvcd-82'];
}

function coreWriterRead(string $path): string
{
    $bytes = @file_get_contents($path, false, null, 0, 1048577);
    if ($bytes === false || strlen($bytes) > 1048576) throw new RuntimeException('Bounded native evidence unavailable.');
    return $bytes;
}

/** Capture native output privately in memory. Return only fingerprints and counts. */
function coreWriterCommand(array $command): array
{
    if (!is_file($command[0]) || !is_executable($command[0])) return ['available' => false];
    $environment = ['LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin', 'XDG_RUNTIME_DIR' => '/run/user/' . posix_geteuid()];
    $account = posix_getpwuid(posix_geteuid());
    if (is_array($account)) $environment['HOME'] = $account['dir'];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Native observer command unavailable.');
    foreach ([1, 2] as $i) stream_set_blocking($pipes[$i], false);
    $bytes = [1 => '', 2 => '']; $exit = -1; $deadline = microtime(true) + 5;
    try {
        do {
            foreach ([1, 2] as $i) {
                $chunk = stream_get_contents($pipes[$i], 1048577 - strlen($bytes[$i]));
                if ($chunk === false) throw new RuntimeException('Native command output unreadable.');
                $bytes[$i] .= $chunk;
                if (strlen($bytes[$i]) > 1048576) throw new RuntimeException('Native command output exceeds limit.');
            }
            $state = proc_get_status($process);
            if (!$state['running']) { $exit = $state['exitcode']; break; }
            if (microtime(true) > $deadline) throw new RuntimeException('Native command observation timed out.');
            $ready = [$pipes[1], $pipes[2]]; $write = null; $except = null;
            if (stream_select($ready, $write, $except, 0, 50000) === false) throw new RuntimeException('Native command observation failed.');
        } while (true);
        // Read trailing bytes after process termination, still bounded.
        foreach ([1, 2] as $i) {
            $tail = stream_get_contents($pipes[$i], 1048577 - strlen($bytes[$i]));
            if ($tail === false) throw new RuntimeException('Native command tail unreadable.');
            $bytes[$i] .= $tail;
            if (strlen($bytes[$i]) > 1048576) throw new RuntimeException('Native command output exceeds limit.');
        }
    } finally {
        $state = proc_get_status($process);
        if ($state['running']) proc_terminate($process); // Only this observer's own read-only child.
        foreach ([1, 2] as $i) fclose($pipes[$i]);
        proc_close($process);
    }
    $absent = $command === ['/usr/bin/crontab', '-l'] && $exit === 1 && $bytes[1] === ''
        && $bytes[2] === 'no crontab for ' . ($account['name'] ?? '') . "\n";
    return ['available' => true, 'exit' => $exit, 'exact_absent_crontab' => $absent,
        'stdout_sha256' => hash('sha256', $bytes[1]), 'stderr_sha256' => hash('sha256', $bytes[2]),
        'stdout_bytes' => strlen($bytes[1]), 'stderr_bytes' => strlen($bytes[2]),
        'nonempty_lines' => count(array_filter(explode("\n", $bytes[1]), static fn(string $s): bool => trim($s) !== ''))];
}

/** Pin directory membership as well as file contents; missing and unreadable are different. */
function coreWriterRegistry(array $paths, array &$errors): array
{
    $out = []; $pending = $paths;
    while ($pending) {
        $path = array_shift($pending); clearstatcache(true, $path); $stat = @lstat($path);
        if (!$stat) {
            if (!is_readable(dirname($path))) { $out[$path] = ['state' => 'unreadable']; $errors[] = 'registry-unreadable'; }
            else $out[$path] = ['state' => 'absent'];
            continue;
        }
        $meta = ['uid' => $stat['uid'], 'gid' => $stat['gid'], 'mode' => $stat['mode'] & 07777];
        if (is_link($path)) {
            $target = @readlink($path);
            $out[$path] = $meta + ['state' => 'symlink', 'target_sha256' => is_string($target) ? hash('sha256', $target) : null];
            // A symlink pin alone does not pin its target's contents. Surface this limitation.
            $errors[] = 'registry-linked-target-needs-review';
        } elseif (is_dir($path)) {
            $items = @scandir($path);
            if ($items === false) { $out[$path] = $meta + ['state' => 'unreadable']; $errors[] = 'registry-unreadable'; continue; }
            $items = array_values(array_diff($items, ['.', '..'])); sort($items, SORT_STRING);
            $out[$path] = $meta + ['state' => 'directory', 'members_sha256' => hash('sha256', json_encode($items, JSON_THROW_ON_ERROR))];
            foreach ($items as $name) $pending[] = $path . '/' . $name;
        } elseif (is_file($path)) {
            try { $bytes = coreWriterRead($path); $out[$path] = $meta + ['state' => 'file', 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)]; }
            catch (Throwable) { $out[$path] = $meta + ['state' => 'unreadable']; $errors[] = 'registry-unreadable'; }
        } else { $out[$path] = $meta + ['state' => 'special']; $errors[] = 'registry-special-file'; }
        if (count($out) + count($pending) > 10000) throw new RuntimeException('Registry exceeds bounded inspection scope.');
    }
    ksort($out, SORT_STRING);
    return $out;
}

function coreWriterObserve(array $request): array
{
    if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || !in_array(posix_geteuid(), [1003, 1004], true)
        || ($request['version'] ?? null) !== 1 || ($request['action'] ?? null) !== 'inspect-writers'
        || ($request['owner_uid'] ?? null) !== posix_geteuid()
        || !preg_match('/^[a-f0-9]{64}$/D', $request['challenge'] ?? '')) throw new RuntimeException('Exact native owner observation required.');
    $start = microtime(true); $errors = []; $processes = []; $vanished = 0;
    $boot = trim(coreWriterRead('/proc/sys/kernel/random/boot_id'));
    if (!preg_match('/^[a-f0-9-]{36}$/D', $boot)) throw new RuntimeException('Host boot identity unavailable.');
    $registryPaths = ['/etc/crontab', '/etc/anacrontab', '/etc/cron.d', '/etc/cron.hourly', '/etc/cron.daily',
        '/etc/cron.weekly', '/etc/cron.monthly', '/etc/systemd/system', '/etc/systemd/user', '/etc/supervisor'];
    $before = coreWriterRegistry($registryPaths, $errors);
    $schedule = coreWriterCommand(['/usr/bin/crontab', '-l']);
    $timers = coreWriterCommand(['/usr/bin/systemctl', '--user', 'list-timers', '--all', '--no-legend', '--no-pager']);
    $services = coreWriterCommand(['/usr/bin/systemctl', '--user', 'list-units', '--type=service', '--state=running', '--no-legend', '--no-pager']);
    $at = coreWriterCommand(['/usr/bin/atq']);
    $paths = glob('/proc/[0-9]*', GLOB_ONLYDIR);
    if ($paths === false || !$paths) throw new RuntimeException('Native process inventory unavailable.');
    foreach ($paths as $path) {
        $pid = (int)basename($path);
        try { $record = coreWriterProcess($pid, 'coreWriterRead'); }
        catch (Throwable) {
            clearstatcache(true, $path);
            if (!is_dir($path)) { $vanished++; continue; }
            $errors[] = 'process-unreadable-or-changed:' . $pid; continue;
        }
        if ($record['core_account'] || $record['core_reference']) $processes[] = $record;
    }
    usort($processes, static fn(array $a, array $b): int => $a['pid'] <=> $b['pid']);
    $after = coreWriterRegistry($registryPaths, $errors);
    if ($before !== $after) $errors[] = 'registry-changed-during-observation';
    foreach (['schedule' => $schedule, 'timers' => $timers, 'services' => $services, 'at' => $at] as $kind => $result) {
        if (!($result['available'] ?? false) || (($result['exit'] ?? -1) !== 0 && !($result['exact_absent_crontab'] ?? false))) $errors[] = $kind . '-observation-unavailable';
    }
    // Enumerating process names cannot prove authenticated ancestry or exclude external schedulers.
    return ['version' => 1, 'scope' => 'native-writer-preparation-evidence', 'root' => CORE_WRITER_ROOT,
        'owner_uid' => posix_geteuid(), 'observer_pid' => getmypid(), 'challenge' => $request['challenge'],
        'started_at_unix' => $start, 'observed_at_unix' => microtime(true), 'boot_id' => $boot,
        'processes' => $processes, 'vanished_processes' => $vanished,
        'registry' => $after, 'registry_sha256' => hash('sha256', json_encode($after, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'current_user_cron' => $schedule, 'user_timers' => $timers, 'running_user_services' => $services, 'queued_at_jobs' => $at,
        'unresolved' => array_values(array_unique($errors)), 'business_writers_drained' => false,
        'limits' => 'Read-only evidence, not a hold witness. Requires pinned reviewed registry, authenticated worker ancestry, both held scheduler receipts, current FPM pool and DB checks, and external-scheduler coverage.'];
}

if (!defined('CORE_WRITER_LIBRARY_ONLY')) {
    try {
        if (count($argv) !== 3 || $argv[1] !== '--request-base64') throw new RuntimeException('Explicit observation request required.');
        $bytes = base64_decode($argv[2], true);
        if ($bytes === false || strlen($bytes) > 4096) throw new RuntimeException('Bounded observation request required.');
        $request = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        echo json_encode(coreWriterObserve($request), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable) { fwrite(STDERR, "Native writer observation unavailable; no hold is established.\n"); exit(2); }
}
