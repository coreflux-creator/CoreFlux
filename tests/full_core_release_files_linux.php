<?php
/** Actual candidate contents and real mixed owners; only a disposable container is accepted. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !is_file('/run/core-release-files-fixture') || realpath('/fixture') !== '/fixture') {
    throw new RuntimeException('Disposable file-release container required.');
}
require __DIR__ . '/../deploy/full_core_release_files.php';
if (($argv[1] ?? '') === '--owner') {
    $base = $argv[2] ?? ''; $action = $argv[3] ?? ''; $uid = posix_geteuid();
    if (!preg_match('~^/fixture/[a-z0-9-]+$~D', $base) || !in_array($uid, [1003, 1004], true)) exit(80);
    $private = $base . '/private-' . $uid; $bytes = file_get_contents($base . '/plan-' . $uid . '.json');
    $calls = 0;
    $guard = static function (string $phase) use ($action, &$calls): bool {
        if ($action === 'refuse-guard') return false;
        if ($action === 'crash-rename' && $phase === 'file-rename' && ++$calls === 2) exit(73);
        if ($action === 'crash-directory' && $phase === 'directory-rename') exit(74);
        return true;
    };
    try {
        $adapter = new FullCoreReleaseFiles($base . '/public_html', $base . '/package-' . $uid, $private,
            $bytes, hash('sha256', $bytes), $guard);
        if ($action === 'prepare' || $action === 'refuse-guard') $adapter->prepare();
        elseif (in_array($action, ['apply', 'crash-rename', 'crash-directory'], true)) $adapter->apply();
        elseif ($action === 'recover') $adapter->recover();
        elseif ($action === 'verify-original') $adapter->verify(false);
        elseif ($action === 'verify-applied') $adapter->verify(true);
        else throw new RuntimeException('Unknown fixture action.');
        exit(0);
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(2); }
}
if (posix_geteuid() !== 0) throw new RuntimeException('Fixture initializer requires container root.');
$checks = 0;
$assert = static function (bool $value, string $label) use (&$checks): void {
    if (!$value) throw new RuntimeException('FAIL ' . $label);
    $checks++; echo "PASS $label\n";
};
$run = static function (string $base, int $uid, string $action): array {
    $user = $uid === 1003 ? 'corefilemaster' : 'corefileapp';
    $process = proc_open(['/usr/sbin/runuser', '-u', $user, '--', PHP_BINARY, __FILE__, '--owner', $base, $action],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $out, $error];
};
$meta = static function (string $path, bool $file): array {
    clearstatcache(true, $path); $s = stat($path);
    $v = ['uid' => $s['uid'], 'gid' => $s['gid'], 'mode' => $s['mode'] & 07777];
    if ($file) $v += ['sha256' => hash_file('sha256', $path), 'size' => $s['size'], 'mtime' => $s['mtime']];
    return $v;
};
$write = static function (string $path, string $bytes, int $uid, int $mode, int $mtime = 1700000000): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    file_put_contents($path, $bytes); chown($path, $uid); chgrp($path, 33); chmod($path, $mode); touch($path, $mtime);
};
$setup = static function (string $id) use ($meta, $write): array {
    $base = '/fixture/' . $id; mkdir($base, 0755); $root = $base . '/public_html'; mkdir($root, 0775); chown($root, 1004); chgrp($root, 33); chmod($root, 0775);
    mkdir($root . '/shared', 0775); chown($root . '/shared', 1004); chgrp($root . '/shared', 33); chmod($root . '/shared', 0775);
    $originals = ['master.php' => [1003, 0644], 'shared/master-old.php' => [1003, 0644],
        'application.php' => [1004, 0664], 'shared/app-old.php' => [1004, 0664]];
    foreach ($originals as $r => [$uid, $mode]) $write($root . '/' . $r, '<?php return "original ' . $r . '";', $uid, $mode);
    $write($root . '/.htaccess', "# original routing\n", 1004, 0664);
    $write($root . '/core.config.local.php', "private fixture placeholder\n", 1004, 0644);
    $write($root . '/unrelated.txt', "unrelated other-owner bytes\n", 33, 0644);
    $sources = ['master.php' => file_get_contents(__DIR__ . '/../core/migration_hash.php'),
        'shared/master-old.php' => file_get_contents(__DIR__ . '/../deploy/full_core_maintenance.php'),
        'new-master/asset.bin' => str_repeat("\0\xff\x7factual-binary", 4096),
        'application.php' => file_get_contents(__DIR__ . '/../deploy/full_core_release_files.php'),
        'shared/app-old.php' => file_get_contents(__DIR__ . '/../deploy/full_core_maintenance_channel.php'),
        'new-app/code.php' => file_get_contents(__DIR__ . '/../core/migration_hash.php')];
    $manifest = ['product' => 'full-core-staging', 'commit' => str_repeat('c', 40), 'files' => []];
    foreach ($sources as $r => $bytes) $manifest['files'][$r] = hash('sha256', $bytes);
    $manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR);
    $plans = [];
    foreach ([1003, 1004] as $uid) {
        $package = $base . '/package-' . $uid; $private = $base . '/private-' . $uid;
        foreach ([$package, $private] as $dir) { mkdir($dir, 0700); chown($dir, $uid); chgrp($dir, 33); }
        $plan = ['version' => 1, 'operation' => substr(hash('sha256', $id), 0, 32), 'candidate_commit' => $manifest['commit'],
            'manifest_sha256' => hash('sha256', $manifestBytes), 'root' => $root, 'owner_uid' => $uid,
            'files' => [], 'directories' => ['shared' => ['before' => $meta($root . '/shared', false), 'after' => $meta($root . '/shared', false)]]];
        foreach ($sources as $r => $bytes) {
            $actor = str_contains($r, 'master') ? 1003 : 1004; if ($actor !== $uid) continue;
            $before = is_file($root . '/' . $r) ? $meta($root . '/' . $r, true) : null;
            $mode = $before['mode'] ?? 0644;
            $write($package . '/' . $r, $bytes, $uid, 0600, 1700000100);
            $after = ['uid' => $uid, 'gid' => 33, 'mode' => $mode, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'mtime' => 1700000100];
            $plan['files'][$r] = ['action' => $before ? 'replace' : 'add', 'before' => $before, 'after' => $after];
            if (!$before) $plan['directories'][dirname($r)] = ['before' => null, 'after' => ['uid' => $uid, 'gid' => 33, 'mode' => 0755]];
        }
        $write($package . '/integration-manifest.json', $manifestBytes, $uid, 0600);
        $write($base . '/plan-' . $uid . '.json', json_encode($plan, JSON_THROW_ON_ERROR), $uid, 0600);
        $plans[$uid] = $plan;
    }
    return [$base, $plans];
};
$unchanged = static function (string $base) use ($meta): array {
    $state = [];
    foreach (['.htaccess', 'core.config.local.php', 'unrelated.txt'] as $r) $state[$r] = $meta($base . '/public_html/' . $r, true);
    return $state;
};

[$base, $plans] = $setup('complete'); $protected = $unchanged($base);
foreach ([1003, 1004] as $uid) $assert($run($base, $uid, 'prepare')[0] === 0, 'actual candidate and original bytes prepared by each owner');
foreach ([1003, 1004] as $uid) $assert($run($base, $uid, 'apply')[0] === 0, 'each owner installs only its reviewed actual-content files');
foreach ([1003, 1004] as $uid) $assert($run($base, $uid, 'verify-applied')[0] === 0, 'candidate bytes UID GID mode and timestamp verified');
$assert($unchanged($base) === $protected, 'routing private configuration and unrelated owner remain unchanged');
foreach ([1004, 1003] as $uid) $assert($run($base, $uid, 'recover')[0] === 0, 'reverse owner order restores files and removes exact additions');
foreach ([1003, 1004] as $uid) {
    $assert($run($base, $uid, 'verify-original')[0] === 0, 'all original bytes and metadata restored');
    $assert($run($base, $uid, 'recover')[0] === 0, 'recovery can be repeated without reapplying');
    $assert($run($base, $uid, 'apply')[0] === 2, 'recovery direction cannot reactivate candidate');
}
$assert($unchanged($base) === $protected, 'protected paths survive complete recovery');

foreach (['crash-rename' => 73, 'crash-directory' => 74] as $action => $exit) {
    [$base] = $setup($action); $assert($run($base, 1004, 'prepare')[0] === 0, 'interruption scenario prepares complete backups');
    $assert($run($base, 1004, $action)[0] === $exit, 'actual process terminates at mutation boundary');
    $assert($run($base, 1004, 'apply')[0] === 2, 'interrupted apply is never retried automatically');
    $assert($run($base, 1004, 'recover')[0] === 0 && $run($base, 1004, 'verify-original')[0] === 0,
        'new process recovers interrupted files or directory creation');
}

[$base, $plans] = $setup('guard-loss');
$assert($run($base, 1004, 'refuse-guard')[0] === 2 && $run($base, 1004, 'verify-original')[0] === 0, 'missing hold refuses preparation without root changes');
[$base, $plans] = $setup('candidate-change');
file_put_contents($base . '/package-1004/application.php', 'changed candidate');
$assert($run($base, 1004, 'prepare')[0] === 2 && $run($base, 1004, 'verify-original')[0] === 0, 'changed candidate refuses before any live replacement');
[$base, $plans] = $setup('late-candidate-change');
$assert($run($base, 1004, 'prepare')[0] === 0, 'late-change fixture has a complete original backup');
file_put_contents($base . '/package-1004/shared/app-old.php', 'late candidate drift');
$assert($run($base, 1004, 'apply')[0] === 2 && $run($base, 1004, 'verify-original')[0] === 0,
    'all candidate files are rechecked before the first directory or file mutation');
[$base, $plans] = $setup('late-backup-change');
$assert($run($base, 1004, 'prepare')[0] === 0, 'late-backup fixture is prepared');
file_put_contents($base . '/private-1004/backup-' . hash('sha256', 'shared/app-old.php'), 'damaged before apply');
$assert($run($base, 1004, 'apply')[0] === 2 && $run($base, 1004, 'verify-original')[0] === 0,
    'every backup is verified before the first live mutation');
[$base, $plans] = $setup('manifest-change');
file_put_contents($base . '/package-1004/integration-manifest.json', '{}');
$assert($run($base, 1004, 'prepare')[0] === 2, 'changed full-release manifest cannot authorize owner plan');
[$base, $plans] = $setup('symlink');
unlink($base . '/package-1004/application.php'); symlink('/etc/passwd', $base . '/package-1004/application.php');
$assert($run($base, 1004, 'prepare')[0] === 2 && $run($base, 1004, 'verify-original')[0] === 0, 'linked candidate cannot escape private stage');

[$base, $plans] = $setup('concurrent');
$assert($run($base, 1004, 'prepare')[0] === 0 && $run($base, 1004, 'apply')[0] === 0, 'concurrent-change case reaches verified apply');
$target = $base . '/public_html/application.php'; $candidate = file_get_contents($target);
file_put_contents($target, 'unreviewed concurrent bytes');
$assert($run($base, 1004, 'recover')[0] === 2 && file_get_contents($target) === 'unreviewed concurrent bytes', 'concurrent edit blocks recovery and is preserved');
$write($target, $candidate, 1004, 0664, 1700000100);
$backup = $base . '/private-1004/backup-' . hash('sha256', 'application.php'); $original = file_get_contents($backup);
file_put_contents($backup, 'damaged backup');
$assert($run($base, 1004, 'recover')[0] === 2 && file_get_contents($target) === $candidate, 'damaged backup refuses before changing candidate files');
$write($backup, $original, 1004, 0600);
$assert($run($base, 1004, 'recover')[0] === 0 && $run($base, 1004, 'verify-original')[0] === 0, 'verified recovery material permits explicit continuation');

foreach (['.htaccess', 'core/config.local.php', 'core/migrations/007_subtenant_provisioning.sql', '../outside.php'] as $badPath) {
    [$base, $plans] = $setup('forbidden-' . substr(hash('sha256', $badPath), 0, 8));
    $plan = $plans[1004]; $plan['files'][$badPath] = $plan['files']['application.php'];
    $write($base . '/plan-1004.json', json_encode($plan), 1004, 0600);
    $assert($run($base, 1004, 'prepare')[0] === 2, 'routing private retained or escaped paths rejected');
}
echo "PASS $checks native owner-file release checks; no production root or database used\n";
