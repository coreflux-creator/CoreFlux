<?php
/** Classify authenticated native observations against an explicitly reviewed, operation-bound policy. */
declare(strict_types=1);

function coreWriterPolicySame(array $actual, array $pin, bool $command = true): bool
{
    $keys = ['pid', 'ppid', 'start_ticks', 'uids'];
    if ($command) $keys[] = 'command_title_sha256';
    foreach ($keys as $key) if (!array_key_exists($key, $pin) || ($actual[$key] ?? null) !== $pin[$key]) return false;
    return true;
}

function coreWriterPolicyClassify(array $report, string $policyBytes, string $policyHash, array $runtime): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $policyHash) || !hash_equals($policyHash, hash('sha256', $policyBytes))) {
        throw new RuntimeException('Reviewed writer policy changed.');
    }
    $policy = json_decode($policyBytes, true, 64, JSON_THROW_ON_ERROR);
    if (!is_int($runtime['owner_pid'] ?? null) || $runtime['owner_pid'] < 1
        || !is_string($runtime['owner_start_ticks'] ?? null) || !ctype_digit($runtime['owner_start_ticks'])) {
        throw new RuntimeException('Authenticated active process start identity required.');
    }
    foreach (['operation'=>32, 'candidate_commit'=>40] as $key=>$length) {
        if (!is_string($policy[$key] ?? null) || !preg_match('/^[a-f0-9]{'.$length.'}$/D', $policy[$key])
            || $policy[$key] !== ($runtime[$key] ?? null)) throw new RuntimeException('Writer operation differs.');
    }
    if (($policy['version'] ?? null) !== 1 || ($report['version'] ?? null) !== 1
        || ($policy['root'] ?? null) !== CORE_WRITER_ROOT || ($report['root'] ?? null) !== CORE_WRITER_ROOT
        || ($report['scope'] ?? null) !== 'native-writer-preparation-evidence'
        || !in_array($report['owner_uid'] ?? null, [1003,1004], true)
        || ($report['boot_id'] ?? null) !== ($policy['boot_id'] ?? null)
        || !is_string($policy['boot_id'] ?? null) || $policy['boot_id'] === ''
        || ($report['registry_sha256'] ?? null) !== ($policy['registry_sha256'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/D', $policy['registry_sha256'] ?? '')) throw new RuntimeException('Native registry or host differs.');
    $limits = $report['unresolved'] ?? null; $reviewedLimits = $policy['reviewed_limits'] ?? null;
    if (!is_array($limits) || !is_array($reviewedLimits)) throw new RuntimeException('Explicit visibility review required.');
    foreach ($reviewedLimits as $limit) {
        // Process visibility failures are never waived. Registry exceptions remain tied to the exact registry digest.
        if (!in_array($limit, ['registry-unreadable','at-observation-unavailable'], true)) throw new RuntimeException('Unreviewable writer visibility gap.');
    }
    sort($limits); sort($reviewedLimits);
    if ($limits !== $reviewedLimits || ($limits && trim($policy['platform_scope_evidence'] ?? '') === '')) throw new RuntimeException('Writer visibility review differs.');
    foreach (['user_timers','running_user_services','queued_at_jobs'] as $key) {
        $expected = $policy['schedulers'][(string)$report['owner_uid']][$key] ?? null;
        if (!is_array($expected) || ($report[$key] ?? null) !== $expected) throw new RuntimeException('Native scheduler state differs.');
        if ($key === 'queued_at_jobs' && $expected === ['available'=>false]
            && in_array('at-observation-unavailable', $reviewedLimits, true)) continue;
        if (($expected['available'] ?? null) !== true || ($expected['exit'] ?? null) !== 0
            || ($expected['nonempty_lines'] ?? null) !== 0 || ($expected['stdout_bytes'] ?? null) !== 0
            || ($expected['stdout_sha256'] ?? null) !== hash('sha256','')) throw new RuntimeException('Unheld native scheduler remains.');
    }
    $cron = $report['current_user_cron'] ?? [];
    $expectedCron = $runtime['held_cron_sha256'][(string)$report['owner_uid']] ?? null;
    if (($cron['available'] ?? null) !== true || !preg_match('/^[a-f0-9]{64}$/D', $expectedCron ?? '')
        || ($cron['stdout_sha256'] ?? null) !== $expectedCron
        || (($cron['exit'] ?? null) !== 0 && ($cron['exact_absent_crontab'] ?? null) !== true)) throw new RuntimeException('Held native cron differs.');
    $records = [];
    foreach (array_merge($report['processes'] ?? [], $report['ancestors'] ?? []) as $record) {
        $pid = $record['pid'] ?? null;
        if (!is_int($pid) || $pid < 1 || (isset($records[$pid]) && $records[$pid] !== $record)) throw new RuntimeException('Native process identities inconsistent.');
        $records[$pid] = $record;
    }
    $allowed = [];
    $listener = $policy['ssh_listener'] ?? [];
    $listenerRecord = $records[$listener['pid'] ?? 0] ?? [];
    if (($listener['uids'] ?? null) !== [0,0,0,0] || !coreWriterPolicySame($listenerRecord, $listener, false)) throw new RuntimeException('Pinned SSH listener differs.');
    $actors = [[$runtime['owner_pid'] ?? null, $runtime['owner_uid'] ?? null, $runtime['owner_start_ticks'] ?? null],
        [$report['observer_pid'] ?? null, $report['owner_uid'], null]];
    if ($actors[0][0] === $actors[1][0]) throw new RuntimeException('Independent native observer required.');
    foreach ($actors as [$pid,$uid,$ticks]) {
        $record = $records[$pid ?? 0] ?? []; $owner = $policy['owners'][(string)$uid] ?? [];
        $workerHash = $runtime['worker_commands'][(string)$uid] ?? '';
        if (!in_array($uid,[1003,1004],true) || ($record['uids'] ?? null) !== [$uid,$uid,$uid,$uid]
            || !is_string($workerHash) || !preg_match('/^[a-f0-9]{64}$/D',$workerHash)
            || ($record['command_title_sha256'] ?? null) !== $workerHash
            || ($ticks !== null && ($record['start_ticks'] ?? null) !== $ticks)) throw new RuntimeException('Authenticated owner process differs.');
        $session = $records[$record['ppid'] ?? 0] ?? [];
        $priv = $records[$session['ppid'] ?? 0] ?? [];
        $user = $owner['ssh_user'] ?? '';
        if (!is_string($user) || !preg_match('/^[a-z_][a-z0-9_-]*$/D', $user)
            || ($session['uids'] ?? null) !== [$uid,$uid,$uid,$uid]
            || ($session['command_title_sha256'] ?? null) !== hash('sha256','sshd: '.$user.'@notty')
            || ($priv['uids'] ?? null) !== [0,0,0,0]
            || ($priv['command_title_sha256'] ?? null) !== hash('sha256','sshd: '.$user.' [priv]')
            || ($priv['ppid'] ?? null) !== $listener['pid']) throw new RuntimeException('Authenticated SSH ancestry differs.');
        $allowed[$pid] = true; $allowed[$session['pid']] = true; $allowed[$priv['pid']] = true;
    }
    $poolPids = $runtime['pool_pids'] ?? null; $poolMaster = $policy['pool_master'] ?? [];
    if (!is_array($poolPids) || !$poolPids || count(array_unique($poolPids)) !== count($poolPids)
        || ($poolMaster['uids'] ?? null) !== [0,0,0,0]
        || !coreWriterPolicySame($records[$poolMaster['pid'] ?? 0] ?? [], $poolMaster)
        || !is_string($policy['pool_name'] ?? null) || $policy['pool_name'] === '') throw new RuntimeException('Authenticated Core pool identity required.');
    foreach ($poolPids as $pid) {
        $record = $records[$pid] ?? [];
        if (!is_int($pid) || isset($allowed[$pid]) || ($record['uids'] ?? null) !== [1004,1004,1004,1004]
            || ($record['ppid'] ?? null) !== $poolMaster['pid']
            || ($record['command_title_sha256'] ?? null) !== hash('sha256','php-fpm: pool '.$policy['pool_name'])) throw new RuntimeException('Native Core web worker differs.');
        $allowed[$pid] = true;
    }
    $retained = $policy['retained_processes'] ?? [];
    if (!is_array($retained)) throw new RuntimeException('Reviewed retained processes required.');
    foreach ($retained as $pin) {
        if (trim($pin['reason'] ?? '') === '' || ($pin['core_reference'] ?? null) !== false) throw new RuntimeException('Only reviewed non-Core retained processes allowed.');
        $pid = $pin['pid'] ?? 0;
        if (isset($records[$pid])) {
            if (isset($allowed[$pid]) || !coreWriterPolicySame($records[$pid], $pin) || ($records[$pid]['core_reference'] ?? null) !== false) throw new RuntimeException('Retained process identity changed.');
            $allowed[$pid] = true;
        }
    }
    $commands = $runtime['database_children'] ?? [];
    if (!is_array($commands) || ($commands && (array_keys($commands) !== ['timeout','client']
        || ($runtime['owner_uid'] ?? null) !== 1004))) throw new RuntimeException('Native transfer allowance differs.');
    $parents = ['owner' => $runtime['owner_pid']];
    foreach ($commands as $role => $command) {
        if (!in_array($role,['timeout','client'],true) || ($command['parent'] ?? null) !== ($role === 'timeout' ? 'owner' : 'timeout')
            || !preg_match('/^[a-f0-9]{64}$/D',$command['command_title_sha256'] ?? '')) throw new RuntimeException('Exact native transfer command required.');
        $matches = [];
        foreach ($report['processes'] as $record) {
            if (($record['uids'] ?? null) === [1004,1004,1004,1004]
                && $record['command_title_sha256'] === $command['command_title_sha256']
                && ($record['ppid'] ?? null) === ($parents[$command['parent']] ?? -1)) $matches[] = $record['pid'];
        }
        if (count($matches) > 1) throw new RuntimeException('Multiple native transfer processes.');
        if ($matches) { $parents[$role] = $matches[0]; $allowed[$matches[0]] = true; }
    }
    foreach ($report['processes'] as $record) {
        if (!isset($allowed[$record['pid']])) {
            // Private diagnostics retain identities and fingerprints, never command arguments.
            $identity = array_intersect_key($record, array_flip(['pid','ppid','start_ticks','uids','command_title_sha256','core_reference','state']));
            throw new RuntimeException('Unreviewed native process remains; retain hold. ' . json_encode($identity, JSON_THROW_ON_ERROR));
        }
    }
    return ['inventory_sha256'=>$policyHash,'registry_unchanged'=>true,'core_cli_processes'=>0,'unmanaged_writers'=>0,
        'owner_uid'=>$report['owner_uid'],'observed_at_unix'=>$report['observed_at_unix'],
        'observation_sha256'=>hash('sha256',json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
}
