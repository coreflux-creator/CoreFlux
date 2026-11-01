<?php
/** Read-only SSH preparation worker. Does not instantiate a release controller or open Core/DB. */
declare(strict_types=1);
require_once __DIR__ . '/full_core_release_control.php';
require_once __DIR__ . '/full_core_maintenance.php';

function ownerInspectPath(string $base, string $relative): string
{
    if ($relative === '' || str_starts_with($relative, '/') || preg_match('/[\x00-\x1f\x7f\\\\:]/', $relative)
        || array_intersect(explode('/', $relative), ['', '.', '..'])) throw new RuntimeException('Unsafe inspection path.');
    $path = $base;
    foreach (explode('/', $relative) as $part) {
        $path .= '/' . $part;
        if (is_link($path)) throw new RuntimeException('Linked inspection path.');
    }
    return $path;
}

function ownerInspectMetadata(string $path, bool $file): ?array
{
    clearstatcache(true, $path); $s = @lstat($path);
    if (!$s) return null;
    if (is_link($path) || realpath($path) !== $path || ($file ? !is_file($path) || $s['nlink'] !== 1 : !is_dir($path))) {
        throw new RuntimeException('Ordinary inspection target required.');
    }
    $result = ['uid' => $s['uid'], 'gid' => $s['gid'], 'mode' => $s['mode'] & 07777];
    if ($file) $result += ['sha256' => hash_file('sha256', $path), 'size' => $s['size'], 'mtime' => $s['mtime']];
    return $result;
}

function ownerInspectPrivate(string $path, int $uid): string
{
    $s = ownerInspectMetadata($path, true);
    if (!$s || $s['uid'] !== $uid || $s['mode'] !== 0600 || $s['size'] > 8388608) throw new RuntimeException('Private reviewed input required.');
    return (string)file_get_contents($path);
}

function ownerInspectSame(?array $a, ?array $b): bool
{
    if ($a !== null) ksort($a);
    if ($b !== null) ksort($b);
    return $a === $b;
}

function ownerInspectDigest(string $bytes, string $hash): void
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || !hash_equals(hash('sha256', $bytes), $hash)) {
        throw new RuntimeException('Reviewed inspection input changed.');
    }
}

function ownerInspectSchedule(): array
{
    $p = proc_open(['/usr/bin/crontab', '-l'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, null, array_merge(getenv(), ['LC_ALL' => 'C']));
    if (!is_resource($p)) throw new RuntimeException('Scheduler inspection unavailable.');
    $bytes = stream_get_contents($pipes[1], 1048577); $diagnostic = stream_get_contents($pipes[2], 4097);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($p);
    $account = posix_getpwuid(posix_geteuid());
    $absent = fullCoreMaintenanceCrontabAbsent($exit, $bytes, $diagnostic, $account['name'] ?? '');
    if ((!$absent && $exit !== 0) || strlen($bytes) > 1048576) throw new RuntimeException('Scheduler inspection failed.');
    $jobs = array_filter(explode("\n", $bytes), static fn(string $line): bool => trim($line) !== ''
        && !str_starts_with(ltrim($line), '#') && !preg_match('/^\s*[A-Za-z_][A-Za-z_0-9]*\s*=/', $line));
    return ['sha256' => hash('sha256', $bytes), 'native_state' => $absent ? 'absent' : 'installed', 'job_count' => count($jobs)];
}

function ownerInspectRelease(array $request): array
{
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid')) throw new RuntimeException('Native CLI required.');
    $uid = posix_geteuid(); $root = FullCoreReleaseControl::ROOT;
    if (($request['version'] ?? null) !== 1 || ($request['action'] ?? null) !== 'inspect'
        || !in_array($uid, [1003, 1004], true) || ($request['owner_uid'] ?? null) !== $uid
        || !preg_match('/^[a-f0-9]{64}$/D', $request['challenge'] ?? '')) throw new RuntimeException('Exact owner inspection request required.');
    $spec = $request['control_spec'] ?? []; FullCoreReleaseControl::validateSpec($spec);
    ownerInspectDigest(FullCoreReleaseControl::encode($spec), $request['control_spec_sha256'] ?? '');
    $rootMeta = ownerInspectMetadata($root, false);
    if (!$rootMeta || $rootMeta['uid'] !== 1004 || $rootMeta['gid'] !== 33) throw new RuntimeException('Canonical Core root required.');
    $package = $request['package_directory'] ?? '';
    $packageMeta = ownerInspectMetadata($package, false);
    if (!$packageMeta || $packageMeta['uid'] !== $uid || $packageMeta['mode'] !== 0700
        || str_starts_with($package . '/', $root . '/')) throw new RuntimeException('Owner-private package outside webroot required.');
    $manifestPath = ownerInspectPath($package, 'integration-manifest.json');
    $manifestMeta = ownerInspectMetadata($manifestPath, true);
    if (!$manifestMeta || $manifestMeta['uid'] !== $uid) throw new RuntimeException('Owner-staged manifest required.');
    $manifestBytes = (string)file_get_contents($manifestPath); ownerInspectDigest($manifestBytes, $spec['manifest_sha256']);
    $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['product'] ?? null) !== 'full-core-staging' || ($manifest['commit'] ?? null) !== $spec['candidate_commit']
        || !is_array($manifest['files'] ?? null) || !$manifest['files']) throw new RuntimeException('Full candidate manifest required.');
    foreach ($manifest['files'] as $relative => $sha) {
        $s = ownerInspectMetadata(ownerInspectPath($package, $relative), true);
        if (!$s || $s['uid'] !== $uid || $s['sha256'] !== $sha) throw new RuntimeException('Staged candidate differs.');
    }
    $planPath = $request['owner_plan_path'] ?? '';
    if (str_starts_with($planPath, $root . '/')) throw new RuntimeException('Owner plan must be outside webroot.');
    $planBytes = ownerInspectPrivate($planPath, $uid); ownerInspectDigest($planBytes, $spec['owner_plan_sha256'][$uid]);
    $plan = json_decode($planBytes, true, 512, JSON_THROW_ON_ERROR);
    foreach (['version', 'operation', 'candidate_commit', 'manifest_sha256', 'root'] as $key) {
        if (($plan[$key] ?? null) !== $spec[$key]) throw new RuntimeException('Owner plan identity differs.');
    }
    if (($plan['owner_uid'] ?? null) !== $uid || !is_array($plan['files'] ?? null) || !is_array($plan['directories'] ?? null)) {
        throw new RuntimeException('Owner file plan required.');
    }
    $drift = []; $access = []; $accessChecked = [];
    foreach (['directories' => false, 'files' => true] as $key => $file) foreach ($plan[$key] as $relative => $entry) {
        $path = ownerInspectPath($root, $relative);
        if (!array_key_exists('before', $entry) || !is_array($entry['after'] ?? null)) throw new RuntimeException('Plan entry is incomplete.');
        $actual = ownerInspectMetadata($path, $file);
        if (!ownerInspectSame($actual, $entry['before'])) $drift[] = $relative;
        if ($file) {
            if ($entry['after']['uid'] !== $uid || ($manifest['files'][$relative] ?? null) !== ($entry['after']['sha256'] ?? null)) {
                throw new RuntimeException('File owner or candidate differs from plan.');
            }
            if ($entry['before'] !== null && !is_readable($path)) $access[] = $relative;
        }
        if ($file || $entry['before'] === null) {
            $parent = dirname($path);
            while (!is_dir($parent) && $parent !== $root) $parent = dirname($parent);
            if (!isset($accessChecked[$parent])) {
                $accessChecked[$parent] = true;
                if (!is_writable($parent) || !is_executable($parent)) $access[] = substr($parent, strlen($root) + 1);
            }
        }
    }
    // Observe only. Existing control state is not instantiated, reset or interpreted as permission.
    $controlPath = dirname($root) . '/private_html/core-release-control-' . $uid;
    $controlExists = file_exists($controlPath) || is_link($controlPath);
    $active = null;
    if ($controlExists) {
        $meta = ownerInspectMetadata($controlPath, false);
        if (!$meta || $meta['uid'] !== $uid || $meta['mode'] !== 0700) throw new RuntimeException('Unexpected control directory.');
        $path = $controlPath . '/active.json';
        if (file_exists($path) || is_link($path)) $active = hash('sha256', ownerInspectPrivate($path, $uid));
    }
    return ['version' => 1, 'action' => 'inspect', 'challenge' => $request['challenge'], 'owner_uid' => $uid,
        'root' => $root, 'operation' => $spec['operation'], 'candidate_commit' => $spec['candidate_commit'],
        'manifest_sha256' => $spec['manifest_sha256'], 'control_spec_sha256' => $request['control_spec_sha256'],
        'owner_plan_sha256' => $spec['owner_plan_sha256'][$uid], 'observed_at' => gmdate('c'),
        'candidate_files_verified' => count($manifest['files']), 'planned_file_changes' => count($plan['files']),
        'planned_directories' => count($plan['directories']), 'preimage_drift' => $drift, 'inaccessible_parents_or_files' => array_values(array_unique($access)),
        'schedule' => ownerInspectSchedule(), 'control_directory_exists' => $controlExists, 'active_control_sha256' => $active,
        'free_bytes' => (int)disk_free_space($root), 'production_changed' => false, 'database_opened' => false,
        'writer_hold_active' => false, 'ready_for_cutover' => false];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $input = stream_get_contents(STDIN, 1048577);
        if ($input === false || strlen($input) > 1048576) throw new RuntimeException('Bounded request required.');
        $request = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
        echo json_encode(ownerInspectRelease($request), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Core release inspection refused: " . $e->getMessage() . "\n"); exit(2);
    }
}
