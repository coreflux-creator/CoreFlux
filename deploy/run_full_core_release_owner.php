<?php
/** Explicit native CLI endpoint for an authenticated external coordinator. Never called by the web app. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/full_core_release_owner.php';
require_once __DIR__ . '/full_core_database_state.php';
define('CORE_WRITER_LIBRARY_ONLY', true);
require_once __DIR__ . '/inspect_full_core_writers.php';
require_once __DIR__ . '/full_core_writer_policy.php';

function ownerWorkerPrivate(string $path, string $sha): string
{
    clearstatcache(true, $path); $s = @lstat($path);
    if (!$s || !is_file($path) || is_link($path) || realpath($path) !== $path || $s['uid'] !== posix_geteuid()
        || $s['nlink'] !== 1 || ($s['mode'] & 07777) !== 0600 || $s['size'] > 8388608
        || str_starts_with($path, FullCoreReleaseControl::ROOT . '/') || !preg_match('/^[a-f0-9]{64}$/D', $sha)) {
        throw new RuntimeException('Reviewed private native input required.');
    }
    $bytes = (string)file_get_contents($path);
    if (!hash_equals($sha, hash('sha256', $bytes))) throw new RuntimeException('Native input fingerprint changed.');
    return $bytes;
}

function ownerWorkerRead(): array
{
    $line = fgets(STDIN, 1048578);
    if ($line === false || strlen($line) > 1048576 || !str_ends_with($line, "\n")) throw new RuntimeException('Bounded complete controller message required.');
    $value = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('Controller object required.');
    return $value;
}

try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || ($argv[1] ?? '') !== '--execute-owner-config'
        || count($argv) !== 4 || !in_array(posix_geteuid(), [1003,1004], true)) throw new RuntimeException('Explicit native owner execution required.');
    $config = json_decode(ownerWorkerPrivate($argv[2], $argv[3]), true, 512, JSON_THROW_ON_ERROR);
    $request = ownerWorkerRead();
    if (!is_string($request['action'] ?? null) || !is_array($request['input'] ?? null)
        || !is_string($request['challenge'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $request['challenge'])) throw new RuntimeException('Exact action and controller challenge required.');
    // Observation must not acquire the native release lock already held by the active owner.
    if (in_array($request['action'], ['inspect-writers','witness-writers'], true)) {
        $input = $request['input'];
        $peerOwner = null; $peerHold = null;
        if ($request['action'] === 'witness-writers') {
            $runtime = $input['runtime'] ?? [];
            foreach (['operation','candidate_commit'] as $key) if (($runtime[$key] ?? null) !== ($config['control_spec'][$key] ?? null)) throw new RuntimeException('Writer observation operation differs.');
            if (!in_array($runtime['owner_uid'] ?? null, [1003,1004], true)) throw new RuntimeException('Exact active native owner required.');
            // Only the peer can acquire its release lock. The active owner's
            // independent process observer must never re-enter that owner's lock.
            if ($runtime['owner_uid'] !== posix_geteuid()) {
                $readOnly = static fn() => throw new RuntimeException('Writer witness cannot execute a release or database action.');
                $peerOwner = new FullCoreReleaseOwner($config, $argv[3], $readOnly, $readOnly, $readOnly);
                $peerHold = $peerOwner->heldObservation();
            }
        }
        $report = coreWriterObserve(['version'=>1,'action'=>'inspect-writers','owner_uid'=>posix_geteuid(),
            'challenge'=>$input['challenge'] ?? '']);
        $result = $report;
        if ($request['action'] === 'witness-writers') {
            $policyBytes = ownerWorkerPrivate($config['writer_policy_path'] ?? '', $config['writer_policy_sha256'] ?? '');
            $classified = coreWriterPolicyClassify($report,$policyBytes,$config['writer_policy_sha256'],$runtime);
            $result = array_intersect_key($report,array_flip(['version','root','owner_uid','challenge','started_at_unix','observed_at_unix','boot_id']))
                + ['scope'=>'native-writer-classification','writers'=>$classified,
                    'runtime_sha256'=>hash('sha256',FullCoreReleaseControl::encode($runtime))];
            if ($peerOwner !== null) {
                if ($peerOwner->heldObservation() !== $peerHold) throw new RuntimeException('Peer hold changed during native writer observation.');
                $result['hold'] = $peerHold;
            }
        }
        echo json_encode(['response'=>$result,'challenge'=>$request['challenge'],'owner_uid'=>posix_geteuid()],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        exit;
    }
    $exchange = static function (array $query) use ($request): array {
        $identity = coreWriterProcess(getmypid(), 'coreWriterRead');
        $query += ['owner_uid' => posix_geteuid(), 'owner_pid' => getmypid(), 'controller_challenge' => $request['challenge']];
        $query += ['owner_start_ticks'=>$identity['start_ticks']];
        // Upgrade guards and owner guards share the same real observation path.
        if (!isset($query['kind'])) $query['kind'] = 'hold-witness';
        if ($query['kind'] === 'hold-witness' && !is_array($query['own_hold'] ?? null)) throw new RuntimeException('Active maintenance owner observation required.');
        echo json_encode(['request' => $query], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; fflush(STDOUT);
        return ownerWorkerRead();
    };
    $connect = static function () use ($config): PDO {
        if (posix_geteuid() !== 1004) throw new RuntimeException('Application database identity required.');
        $c = json_decode(ownerWorkerPrivate($config['database_connection_path'] ?? '', $config['database_connection_sha256'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
        if (!is_string($c['dsn'] ?? null) || !str_starts_with($c['dsn'], 'mysql:') || ($c['username'] ?? null) !== FullCoreProductionUpgradeContext::DATABASE
            || !is_string($c['password'] ?? null)) throw new RuntimeException('Exact application database configuration required.');
        $db = new PDO($c['dsn'], $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true]);
        if ($db->query('SELECT DATABASE()')->fetchColumn() !== FullCoreProductionUpgradeContext::DATABASE
            || !str_starts_with((string)$db->query('SELECT CURRENT_USER()')->fetchColumn(), FullCoreProductionUpgradeContext::DATABASE . '@')
            || (int)$db->query('SELECT @@server_id')->fetchColumn() !== ($config['database_server_id'] ?? null)) {
            throw new RuntimeException('Connected database identity differs.');
        }
        return $db;
    };
    $owner = new FullCoreReleaseOwner($config, $argv[3], $exchange, $connect,
        static fn(): string => fullCoreNativeDatabaseState($connect(), FullCoreProductionUpgradeContext::DATABASE)['snapshot_sha256'],
        static fn(): FullCoreDatabaseTransfer => new FullCoreDatabaseTransfer(json_decode(ownerWorkerPrivate(
            $config['database_connection_path'] ?? '', $config['database_connection_sha256'] ?? ''),true,16,JSON_THROW_ON_ERROR)),
        static function (int $offset,string $bytes) use ($request): void {
            $line = json_encode(['stream'=>['offset'=>$offset,'data'=>base64_encode($bytes)],
                'owner_uid'=>posix_geteuid(),'challenge'=>$request['challenge']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            if (fwrite(STDOUT,$line) !== strlen($line) || !fflush(STDOUT)) throw new RuntimeException('Native backup stream interrupted.');
        });
    $result = $owner->run($request['action'], $request['input'], $request['peer'] ?? null);
    echo json_encode(['response' => $result, 'challenge' => $request['challenge'], 'owner_uid' => posix_geteuid()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    // Exception messages can contain SQL values. Keep them in owner-private diagnostics only.
    $directory = $config['receipts_directory'] ?? null;
    if (is_string($directory) && realpath($directory) === $directory && is_dir($directory) && !is_link($directory)
        && fileowner($directory) === posix_geteuid() && (fileperms($directory) & 07777) === 0700
        && !str_starts_with($directory . '/', FullCoreReleaseControl::ROOT . '/')) {
        $messages = [];
        do { $messages[] = $error->getMessage(); $error = $error->getPrevious(); } while ($error);
        $mask = umask(0077);
        try { file_put_contents($directory . '/error-' . bin2hex(random_bytes(12)) . '.json', json_encode($messages, JSON_THROW_ON_ERROR)); }
        finally { umask($mask); }
    }
    fwrite(STDERR, "Core owner action refused or interrupted; inspect its private state and diagnostic before continuing.\n"); exit(2);
}
