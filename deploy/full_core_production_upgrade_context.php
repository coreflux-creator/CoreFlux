<?php
/** Exact Core production bindings. This library neither holds writers nor starts an upgrade by itself. */
declare(strict_types=1);
require_once __DIR__ . '/full_core_maintenance_host.php';
require_once __DIR__ . '/full_core_release_control.php';

final class FullCoreProductionUpgradeContext
{
    public const ROOT = '/home/1516771.cloudwaysapps.com/grcudkpvcd/public_html';
    public const DATABASE = 'grcudkpvcd';
    private array $binding;
    private array $manifest;
    private FullCoreMaintenanceHost $maintenance;
    private Closure $witness;
    private ?int $lastAccepted = null;

    public function __construct(private string $directory, private string $bindingHash, callable $freshWitness,
        private FullCoreReleaseControl $control)
    {
        if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 1004
            || !in_array(getenv('COREFLUX_ENV'), [false, '', 'production'], true)) throw new RuntimeException('Exact Core application-owner production CLI required.');
        self::privateDirectory($directory);
        $bytes = self::privateFile($directory . '/release-binding.json'); self::digest($bytes, $bindingHash);
        $this->binding = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::validateBinding($this->binding);
        $b = $this->binding;
        $this->control->assertUpgrade($b, $this->bindingHash);
        if (realpath(self::ROOT) !== self::ROOT || is_link(self::ROOT) || fileowner(self::ROOT) !== 1004
            || str_starts_with($directory . '/', self::ROOT . '/')) throw new RuntimeException('Canonical production root required.');
        self::privateDirectory($b['package_directory']); self::privateDirectory($b['application_maintenance_parent']);
        if (str_starts_with($b['package_directory'] . '/', self::ROOT . '/') || $directory === $b['package_directory']) {
            throw new RuntimeException('Private candidate must be outside the production root.');
        }
        $this->manifest = self::inspectCandidate($b['package_directory'], $b['manifest_sha256'], $b['candidate_commit']);
        $this->verifyBackupPair();
        $this->maintenance = new FullCoreMaintenanceHost(self::ROOT, $b['application_maintenance_parent']);
        $this->witness = Closure::fromCallable($freshWitness);
    }

    private static function digest(string $bytes, string $hash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || !hash_equals($hash, hash('sha256', $bytes))) throw new RuntimeException('Production release digest differs.');
    }

    private static function privateDirectory(string $path): void
    {
        if (realpath($path) !== $path || is_link($path) || !is_dir($path) || fileowner($path) !== 1004
            || (fileperms($path) & 07777) !== 0700) throw new RuntimeException('Application-owned private directory required.');
    }

    private static function ordinaryFile(string $path): string
    {
        clearstatcache(true, $path); $s = @lstat($path);
        if (!$s || !is_file($path) || is_link($path) || realpath($path) !== $path || $s['nlink'] !== 1 || $s['uid'] !== 1004) {
            throw new RuntimeException('Application-owned ordinary release file required.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Release material unavailable.');
        return $bytes;
    }

    private static function privateFile(string $path): string
    {
        $bytes = self::ordinaryFile($path);
        if ((fileperms($path) & 07777) !== 0600) throw new RuntimeException('Private release receipt required.');
        return $bytes;
    }

    /** Pure contract validation is also used by the outside-host coordinator before preparing receipts. */
    public static function validateBinding(array $b): void
    {
        if (($b['version'] ?? null) !== 1 || ($b['action'] ?? null) !== 'apply-reviewed-core-database-upgrade'
            || ($b['root'] ?? null) !== self::ROOT || ($b['database'] ?? null) !== self::DATABASE
            || ($b['application_uid'] ?? null) !== 1004 || ($b['master_uid'] ?? null) !== 1003
            || !is_int($b['database_server_id'] ?? null) || $b['database_server_id'] < 0) throw new RuntimeException('Exact production release identity required.');
        foreach (['operation' => 32, 'candidate_commit' => 40, 'manifest_sha256' => 64, 'migration_plan_sha256' => 64,
            'application_maintenance_receipt_sha256' => 64, 'master_maintenance_receipt_sha256' => 64,
            'held_web_sha256' => 64, 'application_held_cron_sha256' => 64, 'master_held_cron_sha256' => 64,
            'backup_pair_sha256' => 64, 'writer_inventory_sha256' => 64, 'release_control_spec_sha256' => 64] as $key => $length) {
            if (!is_string($b[$key] ?? null) || !preg_match('/^[a-f0-9]{' . $length . '}$/D', $b[$key])) throw new RuntimeException('Missing exact production binding.');
        }
        foreach (['package_directory', 'application_maintenance_parent'] as $key) {
            if (!is_string($b[$key] ?? null) || !preg_match('~^/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*$~D', $b[$key])) {
                throw new RuntimeException('Invalid private release path.');
            }
        }
    }

    public static function validateBackupPair(array $pair, array $b): void
    {
        if (($pair['version'] ?? null) !== 1 || ($pair['source_writers_held'] ?? null) !== true) throw new RuntimeException('Held-writer backup pair required.');
        foreach (['operation', 'candidate_commit', 'root', 'database', 'migration_plan_sha256', 'held_web_sha256',
            'application_held_cron_sha256', 'master_held_cron_sha256', 'writer_inventory_sha256'] as $key) {
            if (($pair[$key] ?? null) !== $b[$key]) throw new RuntimeException('Backup belongs to a different release or hold.');
        }
        $db = $pair['database_backup'] ?? null;
        if (!is_array($db) || ($db['native_full_schema_and_data'] ?? null) !== true
            || !in_array($db['encryption'] ?? null, ['windows-dpapi-chunked-v1', 'aes-256-gcm-chunked-v1', 'aes-256-gcm-envelope-v1'], true)
            || !is_int($db['sql_bytes'] ?? null) || $db['sql_bytes'] < 1
            || !is_int($db['archive_bytes'] ?? null) || $db['archive_bytes'] < 1
            || ($db['restoring_database_user'] ?? null) !== self::DATABASE
            || !is_string($db['sql_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $db['sql_sha256'])
            || ($db['readback_sql_sha256'] ?? null) !== $db['sql_sha256']
            || !is_string($db['archive_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $db['archive_sha256'])) {
            throw new RuntimeException('Verified native encrypted database backup required.');
        }
        $owners = $pair['file_owners'] ?? null;
        if (!is_array($owners) || array_keys($owners) !== [1003, 1004]) throw new RuntimeException('Both file-owner backup receipts required.');
        foreach ($owners as $uid => $owner) {
            if (!is_array($owner) || ($owner['owner_uid'] ?? null) !== $uid || ($owner['event'] ?? null) !== 'prepared'
                || ($owner['operation'] ?? null) !== $b['operation'] || ($owner['candidate_commit'] ?? null) !== $b['candidate_commit']) {
                throw new RuntimeException('File backup identity differs.');
            }
            foreach (['plan_sha256', 'prepared_receipt_sha256'] as $key) {
                if (!is_string($owner[$key] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $owner[$key])) throw new RuntimeException('File backup fingerprint missing.');
            }
        }
    }

    private function verifyBackupPair(): void
    {
        $bytes = self::privateFile($this->directory . '/backup-pair.json'); self::digest($bytes, $this->binding['backup_pair_sha256']);
        $pair = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::validateBackupPair($pair, $this->binding);
        $this->control->assertBackupPair($pair);
    }

    /** Read-only pre-staging check shared with the production guard; no lock, maintenance or database is opened. */
    public static function inspectCandidate(string $package, string $manifestHash, string $commit): array
    {
        if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux') throw new RuntimeException('Native CLI artifact inspection required.');
        self::privateDirectory($package);
        if (str_starts_with($package . '/', self::ROOT . '/')) throw new RuntimeException('Candidate must remain outside the webroot.');
        $bytes = self::ordinaryFile($package . '/integration-manifest.json'); self::digest($bytes, $manifestHash);
        $manifest = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['product'] ?? null) !== 'full-core-staging' || ($manifest['commit'] ?? null) !== $commit
            || !preg_match('/^[a-f0-9]{40}$/D', $commit) || !is_array($manifest['files'] ?? null) || !$manifest['files']) {
            throw new RuntimeException('Exact full-Core release manifest required.');
        }
        foreach ($manifest['files'] as $relative => $hash) {
            if (!is_string($relative) || $relative === '' || str_starts_with($relative, '/')
                || preg_match('/[\x00-\x1f\x7f\\\\:]/', $relative) || str_contains($relative, '.local.')
                || array_intersect(explode('/', $relative), ['', '.', '..', '.env'])) throw new RuntimeException('Unsafe full-Core manifest path.');
            $path = $package;
            foreach (explode('/', $relative) as $part) { $path .= '/' . $part; if (is_link($path)) throw new RuntimeException('Linked candidate path.'); }
            self::digest(self::ordinaryFile($path), $hash);
        }
        return $manifest;
    }

    /** Reject stale/replayed reports and require each actual FPM observation to advance its request counter. */
    public static function validateWitness(array $w, array $b, string $challenge, float $requestedAt, ?int $previousAccepted): int
    {
        if (($w['backup_pair_sha256'] ?? null) !== ($b['backup_pair_sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $b['backup_pair_sha256'] ?? '')) {
            throw new RuntimeException('Matching-backup witness required.');
        }
        return self::validateHoldWitness($w, $b, $challenge, $requestedAt, $previousAccepted);
    }

    /** File preparation precedes the DB backup; it requires the same fresh writer/host observations. */
    public static function validateHoldWitness(array $w, array $b, string $challenge, float $requestedAt, ?int $previousAccepted): int
    {
        $now = microtime(true);
        if (($w['challenge'] ?? null) !== $challenge || ($w['operation'] ?? null) !== $b['operation']
            || ($w['candidate_commit'] ?? null) !== $b['candidate_commit'] || ($w['migration_plan_sha256'] ?? null) !== $b['migration_plan_sha256']
            || !is_numeric($w['observed_at_unix'] ?? null) || !is_finite((float)$w['observed_at_unix'])
            || (float)$w['observed_at_unix'] < $requestedAt || (float)$w['observed_at_unix'] > $now + 2
            || $now - (float)$w['observed_at_unix'] > 15) throw new RuntimeException('Fresh challenge-bound release witness required.');
        $master = $w['master'] ?? [];
        if (($master['scope'] ?? null) !== 'current-user-scheduler' || ($master['operation'] ?? null) !== $b['operation']
            || ($master['root'] ?? null) !== self::ROOT || ($master['cli_uid'] ?? null) !== 1003
            || ($master['held_cron_sha256'] ?? null) !== $b['master_held_cron_sha256']
            || ($master['receipt_sha256'] ?? null) !== $b['master_maintenance_receipt_sha256']) throw new RuntimeException('Master schedule is not freshly held.');
        $web = $w['web'] ?? []; $pool = $web['pool'] ?? [];
        $observed = is_string($web['observed_at'] ?? null) ? strtotime($web['observed_at']) : false;
        if (($web['scope'] ?? null) !== 'core-web-pool-only' || ($web['operation'] ?? null) !== $b['operation']
            || ($web['candidate_commit'] ?? null) !== $b['candidate_commit'] || ($web['held_web_sha256'] ?? null) !== $b['held_web_sha256']
            || $observed === false || $observed < floor($requestedAt) || $observed > $now + 2 || $now - $observed > 15
            || ($pool['pool_matches'] ?? null) !== true || ($pool['self_process_count'] ?? null) !== 1
            || ($pool['self_running'] ?? null) !== true || ($pool['self_script_matches'] ?? null) !== true
            || ($pool['counts_consistent'] ?? null) !== true || ($pool['request_pool_quiet'] ?? null) !== true
            || ($pool['listen_queue'] ?? null) !== 0 || ($pool['other_busy_processes'] ?? null) !== 0
            || ($pool['active_processes'] ?? null) !== 1 || !is_int($pool['other_idle_processes'] ?? null) || $pool['other_idle_processes'] < 0
            || ($pool['total_processes'] ?? null) !== $pool['other_idle_processes'] + 1 || !is_int($pool['accepted_connections'] ?? null)
            || $pool['accepted_connections'] < 1 || ($previousAccepted !== null && $pool['accepted_connections'] <= $previousAccepted)) {
            throw new RuntimeException('Fresh quiet Core request pool required.');
        }
        $writers = $w['writers'] ?? [];
        if (($writers['inventory_sha256'] ?? null) !== $b['writer_inventory_sha256']
            || ($writers['registry_unchanged'] ?? null) !== true || ($writers['core_cli_processes'] ?? null) !== 0
            || ($writers['unmanaged_writers'] ?? null) !== 0) {
            throw new RuntimeException('Other-writer witness required.');
        }
        return $pool['accepted_connections'];
    }

    /** Real application-owner receipt/schedule/web checks and DB checks run inside every SQL guard. */
    public function assertHeld(PDO $db): bool
    {
        $b = $this->binding;
        $this->control->assertUpgrade($b, $this->bindingHash);
        self::digest(self::privateFile($this->directory . '/release-binding.json'), $this->bindingHash);
        $this->verifyBackupPair();
        if (file_exists($this->directory . '/recovery-requested.json') || is_link($this->directory . '/recovery-requested.json')) throw new RuntimeException('Release is recovery-only.');
        $receiptPath = $b['application_maintenance_parent'] . '/maintenance-' . $b['operation'] . '/receipt.json';
        $receiptBytes = self::privateFile($receiptPath); self::digest($receiptBytes, $b['application_maintenance_receipt_sha256']);
        $receipt = json_decode($receiptBytes, true, 512, JSON_THROW_ON_ERROR);
        if (($receipt['held_web_sha256'] ?? null) !== $b['held_web_sha256']
            || ($receipt['held_cron_sha256'] ?? null) !== $b['application_held_cron_sha256']) throw new RuntimeException('Application hold binding differs.');
        $this->maintenance->assertHeld($b['operation']);
        $this->assertDatabaseIdle($db);
        $challenge = bin2hex(random_bytes(32)); $requested = microtime(true);
        $w = ($this->witness)(['challenge' => $challenge, 'operation' => $b['operation'],
            'candidate_commit' => $b['candidate_commit'], 'migration_plan_sha256' => $b['migration_plan_sha256']]);
        if (!is_array($w)) throw new RuntimeException('Release witness unavailable.');
        $this->lastAccepted = self::validateWitness($w, $b, $challenge, $requested, $this->lastAccepted);
        // Recheck locally after waiting for outside-host observations, including connections opened during that wait.
        $this->maintenance->assertHeld($b['operation']);
        $this->assertDatabaseIdle($db);
        return true;
    }

    private function assertDatabaseIdle(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $db->inTransaction() || $db->query('SELECT DATABASE()')->fetchColumn() !== self::DATABASE
            || !str_starts_with((string)$db->query('SELECT CURRENT_USER()')->fetchColumn(), self::DATABASE . '@')
            || (int)$db->query('SELECT @@server_id')->fetchColumn() !== $this->binding['database_server_id']
            || !in_array(strtoupper((string)$db->query('SELECT @@GLOBAL.event_scheduler')->fetchColumn()), ['OFF', 'DISABLED'], true)
            || (int)$db->query('SELECT COUNT(*) FROM information_schema.events WHERE event_schema=DATABASE()')->fetchColumn() !== 0) {
            throw new RuntimeException('Production database identity or event-writer state differs.');
        }
        $connection = (int)$db->query('SELECT CONNECTION_ID()')->fetchColumn();
        foreach ($db->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $process) {
            if ((int)$process['Id'] !== $connection) throw new RuntimeException('Another visible database connection remains; retain maintenance.');
        }
    }

    public function execution(): array
    {
        return ['database' => self::DATABASE, 'root' => self::ROOT, 'package' => $this->binding['package_directory'],
            'manifest' => $this->manifest, 'plan_sha256' => $this->binding['migration_plan_sha256'],
            'journal' => $this->directory . '/database-upgrade.jsonl', 'binding' => [
                'operation' => $this->binding['operation'], 'release_binding_sha256' => $this->bindingHash,
                'backup_pair_sha256' => $this->binding['backup_pair_sha256'],
                'release_control_spec_sha256' => $this->binding['release_control_spec_sha256'], 'root' => self::ROOT]];
    }
}
