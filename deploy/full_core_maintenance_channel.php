<?php
/** Operation-scoped web diagnostics; never bootstrap Core, open a database or reset shared OPcache. */
declare(strict_types=1);

function fullCoreMaintenanceChannelValidate(array $spec): void
{
    foreach (['operation' => 32, 'candidate_commit' => 40, 'token_sha256' => 64,
        'held_web_sha256' => 64, 'library_sha256' => 64] as $key => $length) {
        if (!is_string($spec[$key] ?? null) || !preg_match('/^[a-f0-9]{' . $length . '}$/D', $spec[$key])) {
            throw new RuntimeException('Invalid diagnostic binding.');
        }
    }
    if (!is_string($spec['path'] ?? null)
        || !preg_match('/^core-maintenance-' . $spec['operation'] . '-[a-f0-9]{32}\\.php$/D', $spec['path'])
        || !is_string($spec['root'] ?? null) || !preg_match('~^/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)+public_html$~D', $spec['root'])
        || !is_string($spec['private_directory'] ?? null) || !preg_match('~^/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*$~D', $spec['private_directory'])
        || str_starts_with($spec['private_directory'] . '/', $spec['root'] . '/')
        || !is_string($spec['host'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9.-]*(?::[0-9]{1,5})?$/D', $spec['host'])
        || !is_string($spec['pool'] ?? null) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $spec['pool'])
        || !is_int($spec['expires'] ?? null) || !is_int($spec['issued_at'] ?? null)
        || $spec['expires'] <= $spec['issued_at'] || $spec['expires'] - $spec['issued_at'] > 14400) {
        throw new RuntimeException('Invalid diagnostic target or lifetime.');
    }
}

/** The public template has no application includes, credentials, or caller-selected file paths. */
function fullCoreMaintenanceChannelRender(array $spec): string
{
    fullCoreMaintenanceChannelValidate($spec);
    $template = file_get_contents(__DIR__ . '/full_core_maintenance_channel_web.php');
    if ($template === false || substr_count($template, '__COREFLUX_MAINTENANCE_SPEC__') !== 1) {
        throw new RuntimeException('Diagnostic template unavailable.');
    }
    return str_replace('__COREFLUX_MAINTENANCE_SPEC__', base64_encode(json_encode($spec, JSON_THROW_ON_ERROR)), $template);
}

/** Fail closed on incomplete/inconsistent status; Finishing and every non-Idle peer remain busy. */
function fullCoreMaintenanceChannelPool(array $raw, string $pool, int $selfPid, string $selfScript): array
{
    foreach (['listen-queue', 'idle-processes', 'active-processes', 'total-processes', 'accepted-conn'] as $key) {
        if (!is_int($raw[$key] ?? null) || $raw[$key] < 0) throw new RuntimeException('Invalid pool counts.');
    }
    if (!is_array($raw['procs'] ?? null) || !is_string($raw['pool'] ?? null)) throw new RuntimeException('Missing pool inventory.');
    $self = 0; $busy = 0; $idle = 0; $running = false; $scriptMatches = false; $seen = [];
    foreach ($raw['procs'] as $proc) {
        if (!is_int($proc['pid'] ?? null) || $proc['pid'] < 1 || isset($seen[$proc['pid']])
            || !is_string($proc['state'] ?? null)) throw new RuntimeException('Invalid process inventory.');
        $seen[$proc['pid']] = true;
        if ($proc['pid'] === $selfPid) {
            $self++; $running = $proc['state'] === 'Running';
            $scriptMatches = ($proc['script'] ?? null) === $selfScript;
        } elseif ($proc['state'] === 'Idle') { $idle++; }
        else { $busy++; }
    }
    $consistent = $self === 1 && $running && $scriptMatches
        && count($raw['procs']) === $raw['total-processes']
        && $raw['idle-processes'] === $idle && $raw['active-processes'] === $busy + 1;
    return ['pool_matches' => $raw['pool'] === $pool, 'listen_queue' => $raw['listen-queue'],
        'self_process_count' => $self, 'self_running' => $running, 'self_script_matches' => $scriptMatches,
        'other_busy_processes' => $busy, 'other_idle_processes' => $idle,
        'active_processes' => $raw['active-processes'], 'total_processes' => $raw['total-processes'],
        'accepted_connections' => $raw['accepted-conn'], 'counts_consistent' => $consistent,
        'request_pool_quiet' => $consistent && $raw['pool'] === $pool && $busy === 0 && $raw['listen-queue'] === 0];
}

function fullCoreMaintenanceChannelPrivate(string $path, int $uid): string
{
    clearstatcache(true, $path); $stat = @lstat($path);
    if (!$stat || is_link($path) || !is_file($path) || realpath($path) !== $path
        || $stat['uid'] !== $uid || $stat['nlink'] !== 1 || ($stat['mode'] & 07777) !== 0600 || $stat['size'] > 4194304) {
        throw new RuntimeException('Private channel material differs.');
    }
    $bytes = file_get_contents($path);
    if ($bytes === false) throw new RuntimeException('Private channel material unavailable.');
    return $bytes;
}

function fullCoreMaintenanceChannelHeld(array $spec): void
{
    $path = $spec['root'] . '/.htaccess'; clearstatcache(true, $path); $stat = @lstat($path);
    if (!$stat || is_link($path) || !is_file($path) || realpath($path) !== $path || $stat['nlink'] !== 1
        || $stat['uid'] !== posix_geteuid() || !in_array($stat['mode'] & 07777, [0644, 0664], true)
        || !hash_equals($spec['held_web_sha256'], hash_file('sha256', $path))) {
        throw new RuntimeException('Exact web hold is not installed.');
    }
}

/** Validate all reviewed bytes before invalidating anything. No arbitrary request-supplied path is accepted. */
function fullCoreMaintenanceChannelCacheFiles(array $plan, array $spec): array
{
    foreach (['operation', 'candidate_commit', 'root', 'held_web_sha256'] as $key) {
        if (($plan[$key] ?? null) !== $spec[$key]) throw new RuntimeException('Cache plan binding differs.');
    }
    if (($plan['version'] ?? null) !== 1 || !in_array($plan['phase'] ?? null, ['candidate', 'recovery'], true)
        || !is_array($plan['files'] ?? null) || !$plan['files'] || count($plan['files']) > 10000) {
        throw new RuntimeException('Reviewed cache plan required.');
    }
    $files = []; $seen = [];
    foreach ($plan['files'] as $entry) {
        $relative = $entry['path'] ?? null; $hash = $entry['sha256'] ?? null;
        if (!is_string($relative) || strlen($relative) > 512
            || !preg_match('~^(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*\\.php$~D', $relative)
            || isset($seen[$relative]) || $relative === $spec['path']
            || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new RuntimeException('Invalid reviewed cache path.');
        $seen[$relative] = true; $path = $spec['root'] . '/' . $relative;
        clearstatcache(true, $path); $stat = @lstat($path);
        if (!$stat || !is_file($path) || is_link($path) || realpath($path) !== $path || $stat['nlink'] !== 1
            || !hash_equals($hash, hash_file('sha256', $path))) throw new RuntimeException('Reviewed PHP bytes differ.');
        $files[] = $path;
    }
    return $files;
}

/** Called only by the authenticated inert template. The caller holds all other writer/scheduler guards. */
function fullCoreMaintenanceChannelRun(array $spec, array $request): array
{
    fullCoreMaintenanceChannelValidate($spec);
    if (PHP_SAPI !== 'fpm-fcgi' || !function_exists('fpm_get_status') || !function_exists('posix_geteuid')
        || realpath($spec['root']) !== $spec['root'] || realpath($spec['private_directory']) !== $spec['private_directory']
        || is_link($spec['private_directory']) || fileowner($spec['private_directory']) !== posix_geteuid()
        || (fileperms($spec['private_directory']) & 07777) !== 0700) throw new RuntimeException('Channel host prerequisite differs.');
    fullCoreMaintenanceChannelHeld($spec);
    $status = fpm_get_status();
    if (!is_array($status)) throw new RuntimeException('Pool status unavailable.');
    $pool = fullCoreMaintenanceChannelPool($status, $spec['pool'], getmypid(), $spec['root'] . '/' . $spec['path']);
    unset($status);
    $result = ['version' => 1, 'scope' => 'core-web-pool-only', 'operation' => $spec['operation'],
        'candidate_commit' => $spec['candidate_commit'], 'held_web_sha256' => $spec['held_web_sha256'],
        'observed_at' => gmdate('c'), 'business_writers_drained' => false, 'pool' => $pool];
    if ($request === ['action' => 'status']) return $result;
    $keys = array_keys($request); sort($keys);
    if ($keys !== ['action', 'plan_sha256'] || ($request['action'] ?? null) !== 'invalidate'
        || !is_string($request['plan_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $request['plan_sha256'])
        || !$pool['request_pool_quiet']) throw new RuntimeException('Quiet pool and exact cache request required.');
    $planBytes = fullCoreMaintenanceChannelPrivate($spec['private_directory'] . '/cache-plan.json', posix_geteuid());
    if (!hash_equals($request['plan_sha256'], hash('sha256', $planBytes))) throw new RuntimeException('Cache plan digest differs.');
    $plan = json_decode($planBytes, true, 512, JSON_THROW_ON_ERROR);
    $files = fullCoreMaintenanceChannelCacheFiles($plan, $spec);
    if (!function_exists('opcache_get_status') || !function_exists('opcache_invalidate')) throw new RuntimeException('Cache controls unavailable.');
    $cache = opcache_get_status(false);
    if (!is_array($cache) || ($cache['opcache_enabled'] ?? false) !== true
        || ($cache['restart_pending'] ?? true) || ($cache['restart_in_progress'] ?? true)) throw new RuntimeException('Cache state unavailable or restarting.');
    foreach ($files as $file) {
        fullCoreMaintenanceChannelHeld($spec);
        if (!opcache_invalidate($file, true)) throw new RuntimeException('Targeted cache invalidation failed.');
    }
    fullCoreMaintenanceChannelHeld($spec);
    return $result + ['cache_plan_sha256' => $request['plan_sha256'], 'cache_phase' => $plan['phase'],
        'invalidated_files' => count($files), 'global_cache_reset' => false];
}
