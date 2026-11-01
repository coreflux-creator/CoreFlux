<?php
/** Explicit native CLI endpoint for an authenticated external coordinator. Never called by the web app. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/full_core_release_owner.php';
require_once __DIR__ . '/full_core_database_state.php';

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
    $exchange = static function (array $query) use ($request): array {
        $query += ['owner_uid' => posix_geteuid(), 'owner_pid' => getmypid(), 'controller_challenge' => $request['challenge']];
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
        static fn(): string => fullCoreNativeDatabaseState($connect(), FullCoreProductionUpgradeContext::DATABASE)['snapshot_sha256']);
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
