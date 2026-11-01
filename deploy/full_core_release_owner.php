<?php
/** Native owner-side release sequence. Transport supplies observed peer results, never arbitrary shell commands. */
declare(strict_types=1);
require_once __DIR__ . '/full_core_upgrade_executor.php';
require_once __DIR__ . '/full_core_release_files.php';
require_once __DIR__ . '/full_core_database_transfer.php';

final class FullCoreReleaseOwner
{
    private int $uid;
    private array $spec;
    private string $specHash;
    private FullCoreReleaseControl $control;
    private ?FullCoreMaintenanceHost $host = null;
    private ?int $lastAccepted = null;
    private Closure $exchange;
    private Closure $connect;
    private Closure $restoredState;
    private ?Closure $transfer;
    private ?Closure $sendStream;

    /** Callbacks connect native IO to the separately authenticated controller; no callback grants phase order. */
    public function __construct(private array $config, string $configHash, callable $exchange,
        callable $connect, callable $restoredState, ?callable $transfer = null, ?callable $sendStream = null)
    {
        self::digest(FullCoreReleaseControl::encode($config), $configHash);
        if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid')) throw new RuntimeException('Native owner CLI required.');
        $this->uid = posix_geteuid(); $this->spec = $config['control_spec'] ?? [];
        FullCoreReleaseControl::validateSpec($this->spec);
        $this->specHash = hash('sha256', FullCoreReleaseControl::encode($this->spec));
        if (($config['version'] ?? null) !== 1 || ($config['owner_uid'] ?? null) !== $this->uid
            || !in_array($this->uid, [1003, 1004], true)) throw new RuntimeException('Reviewed owner configuration required.');
        foreach (['package_directory','maintenance_parent','files_directory','receipts_directory'] as $key) $this->directory($config[$key] ?? '');
        if (count(array_unique(array_intersect_key($config, array_flip(['package_directory','maintenance_parent','files_directory','receipts_directory'])))) !== 4) {
            throw new RuntimeException('Separate owner staging, maintenance, file backup and receipt directories required.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $config['original_cron_sha256'] ?? '') || !is_array($config['cron_review'] ?? null)) {
            throw new RuntimeException('Reviewed original schedule required.');
        }
        $this->plan();
        $this->exchange = Closure::fromCallable($exchange); $this->connect = Closure::fromCallable($connect);
        $this->restoredState = Closure::fromCallable($restoredState);
        $this->transfer = $transfer === null ? null : Closure::fromCallable($transfer);
        $this->sendStream = $sendStream === null ? null : Closure::fromCallable($sendStream);
        $this->control = new FullCoreReleaseControl($this->spec, $this->specHash);
    }

    private static function digest(string $bytes, string $hash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || !hash_equals(hash('sha256', $bytes), $hash)) throw new RuntimeException('Reviewed owner input differs.');
    }

    private function directory(string $path): void
    {
        if (realpath($path) !== $path || is_link($path) || !is_dir($path) || fileowner($path) !== $this->uid
            || (fileperms($path) & 07777) !== 0700 || str_starts_with($path . '/', FullCoreReleaseControl::ROOT . '/')) {
            throw new RuntimeException('Canonical owner-private directory outside webroot required.');
        }
    }

    private function read(string $path): string
    {
        clearstatcache(true, $path); $s = @lstat($path);
        if (!$s || !is_file($path) || is_link($path) || realpath($path) !== $path || $s['uid'] !== $this->uid
            || $s['nlink'] !== 1 || ($s['mode'] & 07777) !== 0600 || $s['size'] > 8388608
            || str_starts_with($path, FullCoreReleaseControl::ROOT . '/')) throw new RuntimeException('Private owner input required.');
        return (string)file_get_contents($path);
    }

    private function persist(string $path, array $value): void
    {
        $bytes = FullCoreReleaseControl::encode($value);
        if (file_exists($path) || is_link($path)) {
            if ($this->read($path) !== $bytes) throw new RuntimeException('Previous native outcome differs.');
            return;
        }
        $mask = umask(0077);
        try { $f = fopen($path, 'x+b'); } finally { umask($mask); }
        if (!$f) throw new RuntimeException('Native outcome cannot be persisted.');
        try {
            if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || !fsync($f)) throw new RuntimeException('Native outcome persistence failed.');
        } finally { fclose($f); }
        $d = fopen(dirname($path), 'r');
        try { if (!$d || !fsync($d)) throw new RuntimeException('Native outcome directory persistence failed.'); }
        finally { if (is_resource($d)) fclose($d); }
    }

    private function plan(): string
    {
        $bytes = $this->read($this->config['owner_plan_path'] ?? '');
        self::digest($bytes, $this->spec['owner_plan_sha256'][$this->uid]);
        $plan = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        foreach (['version','operation','candidate_commit','manifest_sha256','root'] as $key) {
            if (($plan[$key] ?? null) !== $this->spec[$key]) throw new RuntimeException('Owner plan belongs to another release.');
        }
        if (($plan['owner_uid'] ?? null) !== $this->uid) throw new RuntimeException('Owner plan belongs to another account.');
        return $bytes;
    }

    private function host(): FullCoreMaintenanceHost
    {
        return $this->host ??= new FullCoreMaintenanceHost(FullCoreReleaseControl::ROOT, $this->config['maintenance_parent']);
    }

    /** Local schedule and routing evidence, freshly read while the durable owner lock is held. */
    public function heldObservation(): array
    {
        $operation = $this->spec['operation']; $host = $this->host();
        if ($this->uid === 1003) $result = $host->assertSchedulerHeld($operation);
        else {
            $host->assertHeld($operation);
            $result = ['scope' => 'application-scheduler-and-web', 'root' => FullCoreReleaseControl::ROOT,
                'operation' => $operation, 'cli_uid' => $this->uid];
        }
        $path = $this->config['maintenance_parent'] . '/' . ($this->uid === 1003 ? 'scheduler-' : 'maintenance-') . $operation . '/receipt.json';
        $bytes = $this->read($path); $receipt = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        return $result + ['receipt_sha256' => hash('sha256', $bytes), 'held_cron_sha256' => $receipt['held_cron_sha256'],
            'held_web_sha256' => $receipt['held_web_sha256'] ?? null];
    }

    private function binding(string $hash): array
    {
        $bytes = $this->read($this->config['guard_binding_path'] ?? ''); self::digest($bytes, $hash);
        $b = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        foreach (['operation','candidate_commit','manifest_sha256','migration_plan_sha256','root'] as $key) {
            if (($b[$key] ?? null) !== $this->spec[$key]) throw new RuntimeException('Host guard belongs to another release.');
        }
        foreach (['held_web_sha256','application_held_cron_sha256','master_held_cron_sha256',
            'application_maintenance_receipt_sha256','master_maintenance_receipt_sha256','writer_inventory_sha256'] as $key) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $b[$key] ?? '')) throw new RuntimeException('Incomplete native host guard.');
        }
        return $b;
    }

    private function held(array $b): bool
    {
        $this->control->snapshot(); $own = $this->heldObservation();
        $prefix = $this->uid === 1003 ? 'master' : 'application';
        if ($own['receipt_sha256'] !== $b[$prefix . '_maintenance_receipt_sha256']
            || $own['held_cron_sha256'] !== $b[$prefix . '_held_cron_sha256']) throw new RuntimeException('Current owner hold differs.');
        $challenge = bin2hex(random_bytes(32)); $requested = microtime(true);
        $request = ['kind' => 'hold-witness', 'challenge' => $challenge, 'operation' => $this->spec['operation'],
            'candidate_commit' => $this->spec['candidate_commit'], 'migration_plan_sha256' => $this->spec['migration_plan_sha256'],
            'owner_uid' => $this->uid, 'owner_pid' => getmypid(), 'own_hold' => $own];
        $w = ($this->exchange)($request);
        $this->lastAccepted = FullCoreProductionUpgradeContext::validateHoldWitness($w, $b, $challenge, $requested, $this->lastAccepted);
        // The master cannot inspect the application's crontab itself; require its freshly authenticated peer observation too.
        if ($this->uid === 1003 && (($w['application']['scope'] ?? null) !== 'application-scheduler-and-web'
            || ($w['application']['cli_uid'] ?? null) !== 1004 || ($w['application']['root'] ?? null) !== FullCoreReleaseControl::ROOT
            || ($w['application']['operation'] ?? null) !== $this->spec['operation']
            || ($w['application']['receipt_sha256'] ?? null) !== $b['application_maintenance_receipt_sha256']
            || ($w['application']['held_cron_sha256'] ?? null) !== $b['application_held_cron_sha256']
            || ($w['application']['held_web_sha256'] ?? null) !== $b['held_web_sha256'])) throw new RuntimeException('Fresh application-owner hold required.');
        if ($this->heldObservation() !== $own || hash_file('sha256', FullCoreReleaseControl::ROOT . '/.htaccess') !== $b['held_web_sha256']) {
            throw new RuntimeException('Owner hold changed while observing peers.');
        }
        return true;
    }

    private function finish(string $action, string $token, string $evidence, array $native): array
    {
        $this->persist($this->config['receipts_directory'] . '/' . $action . '.json', $native);
        $outcome = $this->control->finish($action, $token, $this->control->outcome($action, $evidence,
            hash('sha256', FullCoreReleaseControl::encode($native))));
        return ['result' => $native, 'control' => $outcome];
    }

    public function requiredPeer(string $action): ?string { return $this->control->peerRequiredFor($action); }

    private function originalDatabaseState(): array
    {
        $state = json_decode($this->read($this->config['receipts_directory'] . '/original-database-state.json'), true, 16, JSON_THROW_ON_ERROR);
        if (($state['operation'] ?? null) !== $this->spec['operation'] || ($state['candidate_commit'] ?? null) !== $this->spec['candidate_commit']
            || !preg_match('/^[a-f0-9]{64}$/D', $state['snapshot_sha256'] ?? '')) throw new RuntimeException('Recorded native database baseline required.');
        return $state;
    }

    private function capturedDatabase(): array
    {
        $capture = json_decode($this->read($this->config['receipts_directory'].'/database-capture.json'),true,32,JSON_THROW_ON_ERROR);
        if (($capture['operation'] ?? null) !== $this->spec['operation'] || ($capture['candidate_commit'] ?? null) !== $this->spec['candidate_commit']
            || ($capture['snapshot_sha256'] ?? null) !== $this->originalDatabaseState()['snapshot_sha256']) throw new RuntimeException('Original native capture required.');
        return $capture;
    }

    private function verifyCapturedPair(array $pair): void
    {
        $capture = $this->capturedDatabase(); $backup = $pair['database_backup'] ?? [];
        foreach (['sql_bytes','sql_sha256','snapshot_sha256','database_defaults','native_full_schema_and_data'] as $key) {
            if (($backup[$key] ?? null) !== ($capture[$key] ?? null)) throw new RuntimeException('Encrypted backup differs from the native held capture.');
        }
    }

    private function recoveryTransfer(array $input): array
    {
        $state = $this->control->snapshot();
        if ($this->uid !== 1004 || $state['direction'] !== 'recovery' || $state['activation_may_have_started']
            || ($state['pending']['action'] ?? null) !== 'restore-database') throw new RuntimeException('Existing explicit native recovery intent required.');
        $bytes = $this->read($this->config['restore_expectation_path'] ?? '');
        self::digest($bytes,$input['expectation_sha256'] ?? ''); $expected = json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
        if ($state['pending']['evidence_sha256'] !== ($input['expectation_sha256'] ?? '')
            || ($expected['operation'] ?? null) !== $this->spec['operation'] || ($expected['candidate_commit'] ?? null) !== $this->spec['candidate_commit']) {
            throw new RuntimeException('Recovery stream does not match the pending native intent.');
        }
        $b = $this->binding($input['guard_sha256'] ?? '');
        $bytes = $this->read($this->config['recovery_backup_pair_path'] ?? ''); self::digest($bytes,$expected['backup_pair_sha256'] ?? '');
        $pair = json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
        FullCoreProductionUpgradeContext::validateBackupPair($pair,$b); $this->control->assertBackupPair($pair); $this->verifyCapturedPair($pair);
        if (($expected['snapshot_sha256'] ?? null) !== $this->originalDatabaseState()['snapshot_sha256']) throw new RuntimeException('Original recovery fingerprint differs.');
        return [$b,$pair['database_backup']];
    }

    private function invalidate(array $input, array $b, string $phase): array
    {
        $hash = $input['cache_plan_sha256'] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new RuntimeException('Reviewed cache plan hash required.');
        $this->held($b);
        $native = ($this->exchange)(['kind' => 'invalidate', 'operation' => $this->spec['operation'],
            'candidate_commit' => $this->spec['candidate_commit'], 'phase' => $phase, 'cache_plan_sha256' => $hash]);
        if (($native['operation'] ?? null) !== $this->spec['operation'] || ($native['candidate_commit'] ?? null) !== $this->spec['candidate_commit']
            || ($native['held_web_sha256'] ?? null) !== $b['held_web_sha256'] || ($native['cache_phase'] ?? null) !== $phase
            || ($native['cache_plan_sha256'] ?? null) !== $hash || !is_int($native['invalidated_files'] ?? null)
            || $native['invalidated_files'] < 1 || ($native['global_cache_reset'] ?? null) !== false) {
            throw new RuntimeException('Native cache outcome differs.');
        }
        $this->held($b); return $native;
    }

    private function preparationObservation(): array
    {
        $s = $this->control->snapshot();
        if ($s['direction'] !== 'preparation-abort') throw new RuntimeException('Permanent preparation abort fence required.');
        foreach (['apply-requested.json','recovery-requested.json','journal.jsonl'] as $marker) {
            $path = $this->config['files_directory'] . '/' . $marker;
            if (file_exists($path) || is_link($path)) throw new RuntimeException('Possible native file mutation requires paired recovery.');
        }
        $host = $this->host()->inspectPreparation($this->spec['operation'], $this->config['original_cron_sha256'],
            $this->config['cron_review'], $this->uid === 1004 ? $this->config['original_web'] : null);
        $adapter = new FullCoreReleaseFiles(FullCoreReleaseControl::ROOT, $this->config['package_directory'],
            $this->config['files_directory'], $this->plan(), $this->spec['owner_plan_sha256'][$this->uid],
            fn(): bool => $this->control->snapshot()['direction'] === 'preparation-abort');
        $adapter->verify(false);
        return ['host' => $host, 'original_files_verified' => true, 'owner_release_mutations_never_started' => true];
    }

    private function preparationPeer(bool $restored): void
    {
        $challenge = bin2hex(random_bytes(32)); $requested = microtime(true);
        $peer = ($this->exchange)(['kind' => 'preparation-abort-witness', 'challenge' => $challenge,
            'operation' => $this->spec['operation'], 'control_spec_sha256' => $this->specHash]);
        $observed = $peer['observed_at_unix'] ?? null; $now = microtime(true);
        if (($peer['challenge'] ?? null) !== $challenge || ($peer['operation'] ?? null) !== $this->spec['operation']
            || ($peer['control_spec_sha256'] ?? null) !== $this->specHash || ($peer['owner_uid'] ?? null) !== ($this->uid === 1003 ? 1004 : 1003)
            || ($peer['direction'] ?? null) !== 'preparation-abort' || ($peer['verified_preparation_abort'] ?? null) !== true
            || ($peer['original_files_verified'] ?? null) !== true || ($peer['owner_release_mutations_never_started'] ?? null) !== true
            || (!is_float($observed) && !is_int($observed)) || $observed < $requested || $observed > $now || $now - $requested > 15
            || ($restored && ($peer['host']['original'] ?? null) !== true)) {
            throw new RuntimeException('Fresh authenticated other-owner preparation verification required.');
        }
    }

    public function run(string $action, array $input = [], ?array $peer = null): array
    {
        $spec = $this->spec; $operation = $spec['operation']; $evidence = hash('sha256', FullCoreReleaseControl::encode($input));
        if ($action === 'inspect-control') {
            $state = $this->control->snapshot(); $completed = [];
            foreach ($state['completed'] as $step => $hash) {
                $outcome = $this->control->completedOutcome($step);
                $bytes = $this->read($this->config['receipts_directory'] . '/' . $step . '.json');
                self::digest($bytes, $outcome['native_receipt_sha256']);
                $completed[$step] = ['control' => $outcome, 'result' => json_decode($bytes, true, 512, JSON_THROW_ON_ERROR)];
            }
            return ['state' => $state, 'completed' => $completed];
        }
        if ($action === 'witness-held') return $this->heldObservation();
        if ($action === 'request-recovery') { $this->control->requestRecovery(); return ['state' => $this->control->snapshot()]; }
        if ($action === 'request-preparation-abort') {
            if ($this->uid === 1003 && $this->control->snapshot()['direction'] !== 'preparation-abort') {
                if (($peer['action'] ?? null) !== 'verify-preparation-abort') throw new RuntimeException('Application-owner abort verification required.');
                $this->control->acceptPeerOutcome($peer);
            }
            $this->control->requestPreparationAbort(); return ['state' => $this->control->snapshot()];
        }
        if ($action === 'abandon-unstarted') { $this->control->abandonUnstarted(); return ['state' => $this->control->snapshot()]; }
        if ($action === 'inspect-preparation-abort') {
            if (!preg_match('/^[a-f0-9]{64}$/D', $input['challenge'] ?? '')) throw new RuntimeException('Fresh preparation inspection challenge required.');
            $native = $this->preparationObservation(); $state = $this->control->snapshot();
            return $native + ['challenge' => $input['challenge'], 'operation' => $operation, 'control_spec_sha256' => $this->specHash,
                'owner_uid' => $this->uid, 'direction' => $state['direction'], 'observed_at_unix' => microtime(true),
                'verified_preparation_abort' => isset($state['completed']['verify-preparation-abort'])];
        }
        $resume = $action === 'resume-preparation-abort';
        if ($resume) {
            $pending = $this->control->snapshot()['pending'];
            $action = $input['phase'] ?? '';
            if (!in_array($action, ['verify-preparation-abort','restore-preparation-hold','complete-preparation-abort'], true)
                || ($pending['action'] ?? null) !== $action) throw new RuntimeException('Exact interrupted preparation-abort phase required.');
            $evidence = $pending['evidence_sha256'];
        }
        if ($action === 'inspect-database-state') {
            $state = $this->control->snapshot();
            if ($this->uid !== 1004 || $state['direction'] !== 'release' || $state['pending'] !== null
                || !isset($state['completed']['prepare-files']) || isset($state['completed']['upgrade-database'])) {
                throw new RuntimeException('Held application-owner pre-upgrade fingerprint phase required.');
            }
            $b = $this->binding($input['guard_sha256'] ?? ''); $this->held($b); $hash = ($this->restoredState)(); $this->held($b);
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new RuntimeException('Native database fingerprint missing.');
            $result = ['snapshot_sha256' => $hash, 'operation' => $operation, 'candidate_commit' => $spec['candidate_commit']];
            $this->persist($this->config['receipts_directory'] . '/original-database-state.json', $result);
            return $result;
        }
        if ($action === 'capture-database-stream') {
            $state = $this->control->snapshot();
            if ($this->uid !== 1004 || !$this->transfer || !$this->sendStream || $state['direction'] !== 'release'
                || $state['pending'] !== null || !isset($state['completed']['prepare-files']) || isset($state['completed']['upgrade-database'])) {
                throw new RuntimeException('Prepared, held application-owner backup phase required.');
            }
            $b = $this->binding($input['guard_sha256'] ?? ''); $baseline = $this->originalDatabaseState()['snapshot_sha256'];
            $this->held($b);
            if (($this->restoredState)() !== $baseline) throw new RuntimeException('Database changed before native backup.');
            $db = ($this->connect)();
            try { $capture = ($this->transfer)()->capture($db,fn(): bool=>$this->held($b),$this->sendStream); }
            finally { $db = null; }
            $this->held($b);
            if (($this->restoredState)() !== $baseline) throw new RuntimeException('Database changed around native backup.');
            $this->held($b);
            $capture += ['snapshot_sha256'=>$baseline,'operation'=>$operation,'candidate_commit'=>$spec['candidate_commit'],
                'database'=>FullCoreProductionUpgradeContext::DATABASE];
            $this->persist($this->config['receipts_directory'].'/database-capture.json',$capture);
            return $capture;
        }
        if (in_array($action,['empty-recovery-database','restore-database-stream'],true)) {
            [$b,$backup] = $this->recoveryTransfer($input); $this->held($b); $db = ($this->connect)();
            if ($action === 'empty-recovery-database') {
                return FullCoreDatabaseTransfer::emptyRecoveryTarget($db,$backup['database_defaults'],fn(): bool=>$this->held($b));
            }
            if (!$this->transfer) throw new RuntimeException('Native restore transport unavailable.');
            $read = function (int $offset,int $limit): string {
                $reply = ($this->exchange)(['kind'=>'database-restore-chunk','offset'=>$offset,'max_bytes'=>$limit,
                    'operation'=>$this->spec['operation'],'control_spec_sha256'=>$this->specHash]);
                if (($reply['offset'] ?? null) !== $offset || !is_string($reply['data'] ?? null)
                    || strlen($reply['data']) > 4 * (int)ceil($limit / 3)) throw new RuntimeException('Exact bounded recovery chunk required.');
                $bytes = base64_decode($reply['data'],true);
                if ($bytes === false || strlen($bytes) > $limit) throw new RuntimeException('Recovery chunk encoding differs.');
                return $bytes;
            };
            $result = ($this->transfer)()->restore($db,$backup,fn(): bool=>$this->held($b),$read);
            $this->persist($this->config['receipts_directory'].'/database-restored-stream.json',$result);
            return $result;
        }
        if (($required = $this->control->peerRequiredFor($action)) !== null) {
            if (($peer['action'] ?? null) !== $required) throw new RuntimeException('Direct required peer outcome missing.');
            $this->control->acceptPeerOutcome($peer);
        }
        if (in_array($action, ['verify-preparation-abort','restore-preparation-hold','complete-preparation-abort'], true)) {
            $token = $resume ? $pending['token'] : $this->control->begin($action, $evidence);
            $native = $this->preparationObservation();
            if ($action !== 'verify-preparation-abort') $this->preparationPeer($action === 'complete-preparation-abort');
            if ($action === 'restore-preparation-hold') {
                $this->host()->restorePreparation($operation, $this->config['original_cron_sha256'],
                    $this->config['cron_review'], $this->uid === 1004 ? $this->config['original_web'] : null);
                $native = $this->preparationObservation();
            }
            if ($action !== 'verify-preparation-abort' && !$native['host']['original']) throw new RuntimeException('Original service state required before completing preparation abort.');
            return $this->finish($action, $token, $evidence, $native);
        }
        if ($action === 'upgrade-database') {
            if ($this->uid !== 1004) throw new RuntimeException('Application owner required for database upgrade.');
            $evidence = $input['binding_sha256'] ?? ''; $directory = $this->config['execution_directory'] ?? '';
            $this->directory($directory); self::digest($this->read($directory . '/release-binding.json'), $evidence);
            $pair = json_decode($this->read($directory . '/backup-pair.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->verifyCapturedPair($pair);
            if (($pair['database_backup']['snapshot_sha256'] ?? null) !== $this->originalDatabaseState()['snapshot_sha256']) {
                throw new RuntimeException('Database backup is not paired with the recorded native baseline.');
            }
            $token = $this->control->begin($action, $evidence);
            $context = new FullCoreProductionUpgradeContext($directory, $evidence, $this->exchange, $this->control);
            $native = fullCoreUpgradeProduction(($this->connect)(), $context);
            return $this->finish($action, $token, $evidence, $native);
        }
        if (in_array($action, ['begin-database-recovery','confirm-database-recovery'], true)) {
            if ($this->uid !== 1004) throw new RuntimeException('Application owner required for database recovery.');
            $expectation = $this->read($this->config['restore_expectation_path'] ?? ''); $evidence = $input['expectation_sha256'] ?? '';
            self::digest($expectation, $evidence); $expected = json_decode($expectation, true, 512, JSON_THROW_ON_ERROR);
            foreach (['operation','candidate_commit'] as $key) if (($expected[$key] ?? null) !== $spec[$key]) throw new RuntimeException('Database recovery identity differs.');
            $b = $this->binding($input['guard_sha256'] ?? '');
            $pairBytes = $this->read($this->config['recovery_backup_pair_path'] ?? '');
            self::digest($pairBytes, $expected['backup_pair_sha256'] ?? '');
            $pair = json_decode($pairBytes, true, 512, JSON_THROW_ON_ERROR);
            FullCoreProductionUpgradeContext::validateBackupPair($pair, $b); $this->control->assertBackupPair($pair);
            $this->verifyCapturedPair($pair);
            $baseline = $this->originalDatabaseState()['snapshot_sha256'];
            if (($expected['snapshot_sha256'] ?? null) !== $baseline || ($pair['database_backup']['snapshot_sha256'] ?? null) !== $baseline) {
                throw new RuntimeException('Recovery must use the recorded original database fingerprint.');
            }
            $this->held($b);
            if ($action === 'begin-database-recovery') return ['intent' => $this->control->begin('restore-database', $evidence)];
            $actual = ($this->restoredState)();
            if (!is_string($actual) || !preg_match('/^[a-f0-9]{64}$/D', $actual)
                || !hash_equals($expected['snapshot_sha256'] ?? '', $actual)) throw new RuntimeException('Restored native database differs.');
            $this->held($b);
            return $this->finish('restore-database', $this->control->snapshot()['pending']['token'], $evidence,
                ['snapshot_sha256' => $actual, 'backup_pair_sha256' => $expected['backup_pair_sha256']]);
        }
        $token = $this->control->begin($action, $evidence); $host = $this->host();
        if ($action === 'hold') {
            $native = $this->uid === 1003 ? $host->pauseScheduler($operation, $this->config['original_cron_sha256'], $this->config['cron_review'])
                : $host->pause($operation, $this->config['original_web'], $this->config['original_cron_sha256'],
                    $this->config['cron_review'], $this->config['diagnostic_path']);
            $native += ['hold' => $this->heldObservation()];
        } elseif (in_array($action, ['prepare-files','apply-files','recover-files','verify-files','verify-recovery'], true)) {
            $b = $this->binding($input['guard_sha256'] ?? '');
            $adapter = new FullCoreReleaseFiles(FullCoreReleaseControl::ROOT, $this->config['package_directory'],
                $this->config['files_directory'], $this->plan(), $spec['owner_plan_sha256'][$this->uid], fn(): bool => $this->held($b));
            if ($action === 'prepare-files') $native = $adapter->prepare();
            elseif ($action === 'apply-files') $native = $adapter->apply();
            elseif ($action === 'recover-files') {
                $p = json_decode($this->plan(), true, 512, JSON_THROW_ON_ERROR);
                foreach ($p['files'] as $relative => $entry) if ($entry['before'] === null && str_ends_with($relative, '.php')
                    && file_exists(FullCoreReleaseControl::ROOT . '/' . $relative)) {
                    $this->invalidate($input, $b, 'recovery'); break;
                }
                $native = $adapter->recover();
            }
            else { $adapter->verify($action === 'verify-files'); $native = ['verified' => true]; }
            if ($action === 'prepare-files') $native['prepared_receipt_sha256'] = hash('sha256', $this->read($this->config['files_directory'] . '/prepared.json'));
        } elseif (in_array($action, ['refresh-cache','refresh-recovery-cache'], true)) {
            $b = $this->binding($input['guard_sha256'] ?? '');
            $phase = $action === 'refresh-cache' ? 'candidate' : 'recovery';
            $native = $this->invalidate($input, $b, $phase);
        } elseif ($action === 'activate') {
            $this->held($this->binding($input['guard_sha256'] ?? ''));
            $manifest = FullCoreProductionUpgradeContext::inspectCandidate($this->config['package_directory'], $spec['manifest_sha256'], $spec['candidate_commit']);
            $web = $this->read($this->config['package_directory'] . '/.htaccess');
            $native = $host->prepareActivation($operation, $web, $manifest['files']['.htaccess'], $spec['candidate_commit'], $spec['manifest_sha256']);
            $host->activate($operation, $spec['candidate_commit'], $spec['manifest_sha256']);
        } elseif (in_array($action, ['resume-scheduler','restore-scheduler','restore-routing'], true)) {
            if ($action === 'restore-routing') $this->held($this->binding($input['guard_sha256'] ?? ''));
            if ($this->uid === 1003) $host->restoreScheduler($operation); else $host->restore($operation);
            $native = ['restored' => true, 'operation' => $operation];
        } elseif ($action === 'complete') {
            $manifest = FullCoreProductionUpgradeContext::inspectCandidate($this->config['package_directory'], $spec['manifest_sha256'], $spec['candidate_commit']);
            if ($host->webSnapshot()['sha256'] !== $manifest['files']['.htaccess']) throw new RuntimeException('Activated routing differs.');
            $native = ['routing_verified' => true];
        } else throw new RuntimeException('Unknown release owner action.');
        return $this->finish($action, $token, $evidence, $native);
    }
}
