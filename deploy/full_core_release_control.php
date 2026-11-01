<?php
/** Durable per-owner release control. One fixed lock/active operation survives SSH and controller loss. */
declare(strict_types=1);

final class FullCoreReleaseControl
{
    public const ROOT = '/home/1516771.cloudwaysapps.com/grcudkpvcd/public_html';
    private const PREPARATION_ABORT = ['verify-preparation-abort', 'restore-preparation-hold', 'complete-preparation-abort'];
    private int $uid;
    private string $directory;
    private string $operationDirectory;
    private string $specHash;
    private array $state;
    private $lock;

    public function __construct(private array $spec, string $reviewedSpecHash)
    {
        if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid')
            || !in_array(posix_geteuid(), [1003, 1004], true)) throw new RuntimeException('Reviewed Core deployment account required.');
        $this->uid = posix_geteuid(); self::validateSpec($spec);
        $bytes = self::encode($spec); $this->specHash = hash('sha256', $bytes);
        if (!hash_equals($this->specHash, $reviewedSpecHash)) throw new RuntimeException('Reviewed release control identity differs.');
        $parent = dirname(self::ROOT) . '/private_html';
        foreach ([self::ROOT, $parent] as $path) {
            if (realpath($path) !== $path || is_link($path) || !is_dir($path) || fileowner($path) !== 1004) {
                throw new RuntimeException('Canonical Core application directories required.');
            }
        }
        $this->directory = $parent . '/core-release-control-' . $this->uid;
        $this->privateDirectory($this->directory, true);
        $lockPath = $this->directory . '/release.lock';
        if (file_exists($lockPath) || is_link($lockPath)) $this->read($lockPath);
        $mask = umask(0077);
        try { $this->lock = fopen($lockPath, 'c+b'); } finally { umask($mask); }
        if (!$this->lock || !flock($this->lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another Core release session owns this deployment account.');
        $this->read($lockPath);
        $activePath = $this->directory . '/active.json';
        if (file_exists($activePath) || is_link($activePath)) {
            $active = json_decode($this->read($activePath), true, 32, JSON_THROW_ON_ERROR);
            if (!is_string($active['operation'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $active['operation'])
                || ($active['owner_uid'] ?? null) !== $this->uid) throw new RuntimeException('Active release identity is invalid.');
            if ($active['operation'] !== $spec['operation']) {
                $previousDirectory = $this->directory . '/operation-' . $active['operation'];
                $previousSpec = json_decode($this->read($previousDirectory . '/spec.json'), true, 64, JSON_THROW_ON_ERROR);
                self::validateSpec($previousSpec);
                $previous = json_decode($this->read($previousDirectory . '/state.json'), true, 64, JSON_THROW_ON_ERROR);
                $this->validateState($previous, $previousSpec, $previousDirectory);
                if (($previous['spec_sha256'] ?? null) !== ($active['spec_sha256'] ?? null)
                    || $previousSpec['operation'] !== $active['operation']
                    || !in_array($previous['terminal'] ?? null, ['complete', 'recovered', 'unstarted', 'preparation-aborted'], true)
                    || ($previous['pending'] ?? null) !== null) throw new RuntimeException('Unresolved earlier Core release must be inspected or recovered first.');
            } elseif (($active['spec_sha256'] ?? null) !== $this->specHash) throw new RuntimeException('Operation already belongs to another release specification.');
        }
        $this->operationDirectory = $this->directory . '/operation-' . $spec['operation'];
        $this->privateDirectory($this->operationDirectory, true);
        $specPath = $this->operationDirectory . '/spec.json';
        if (file_exists($specPath) || is_link($specPath)) {
            if ($this->read($specPath) !== $bytes) throw new RuntimeException('Saved release specification differs.');
        } else $this->writeNew($specPath, $bytes);
        $statePath = $this->operationDirectory . '/state.json';
        if (file_exists($statePath) || is_link($statePath)) {
            $this->state = json_decode($this->read($statePath), true, 64, JSON_THROW_ON_ERROR);
            $this->validateState();
        } else {
            $this->state = ['version' => 1, 'operation' => $spec['operation'], 'owner_uid' => $this->uid,
                'spec_sha256' => $this->specHash, 'sequence' => 0, 'direction' => 'release', 'pending' => null,
                'completed' => [], 'peer_outcomes' => [], 'activation_may_have_started' => false, 'terminal' => null];
            $this->writeNew($statePath, self::encode($this->state));
        }
        $this->replace($activePath, self::encode(['operation' => $spec['operation'], 'owner_uid' => $this->uid, 'spec_sha256' => $this->specHash]));
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
    }

    public static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function validateSpec(array $spec): void
    {
        if (($spec['version'] ?? null) !== 1 || ($spec['root'] ?? null) !== self::ROOT) throw new RuntimeException('Exact Core release target required.');
        foreach (['operation' => 32, 'candidate_commit' => 40, 'manifest_sha256' => 64, 'migration_plan_sha256' => 64] as $key => $length) {
            if (!is_string($spec[$key] ?? null) || !preg_match('/^[a-f0-9]{' . $length . '}$/D', $spec[$key])) throw new RuntimeException('Complete release control fingerprint required.');
        }
        if (!is_array($spec['owner_plan_sha256'] ?? null) || array_keys($spec['owner_plan_sha256']) !== [1003, 1004]) throw new RuntimeException('Both owner file plans required.');
        foreach ($spec['owner_plan_sha256'] as $hash) if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new RuntimeException('Owner plan fingerprint required.');
    }

    private function privateDirectory(string $path, bool $create = false): void
    {
        if (!file_exists($path) && !is_link($path) && $create) {
            if (!mkdir($path, 0700)) throw new RuntimeException('Cannot create private release control.');
            $this->syncDirectory(dirname($path));
        }
        clearstatcache(true, $path);
        if (realpath($path) !== $path || !is_dir($path) || is_link($path) || fileowner($path) !== $this->uid
            || (fileperms($path) & 07777) !== 0700) throw new RuntimeException('Owner-private release control directory required.');
    }

    private function read(string $path): string
    {
        clearstatcache(true, $path); $s = @lstat($path);
        if (!$s || !is_file($path) || is_link($path) || realpath($path) !== $path || $s['nlink'] !== 1
            || $s['uid'] !== $this->uid || ($s['mode'] & 07777) !== 0600 || $s['size'] > 4194304) throw new RuntimeException('Private release control file differs.');
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Release control material unavailable.');
        return $bytes;
    }

    private function writeNew(string $path, string $bytes): void
    {
        $mask = umask(0077);
        try { $stream = @fopen($path, 'x+b'); } finally { umask($mask); }
        if (!$stream) throw new RuntimeException('New release control file required.');
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) throw new RuntimeException('Cannot persist release control state.');
        } finally { fclose($stream); }
        $this->syncDirectory(dirname($path));
    }

    private function syncDirectory(string $path): void
    {
        $stream = fopen($path, 'r');
        try {
            if (!$stream || !fsync($stream)) throw new RuntimeException('Cannot persist release control directory entries.');
        } finally { if (is_resource($stream)) fclose($stream); }
    }

    private function replace(string $path, string $bytes): void
    {
        if (file_exists($path) || is_link($path)) $this->read($path);
        $temporary = dirname($path) . '/pending-' . bin2hex(random_bytes(16));
        $this->writeNew($temporary, $bytes);
        if (!rename($temporary, $path)) throw new RuntimeException('Cannot commit release control state.');
        $this->syncDirectory(dirname($path));
    }

    private function validateState(?array $s = null, ?array $spec = null, ?string $directory = null): void
    {
        $s ??= $this->state; $spec ??= $this->spec; $directory ??= $this->operationDirectory;
        if (($s['version'] ?? null) !== 1 || ($s['operation'] ?? null) !== $spec['operation']
            || ($s['owner_uid'] ?? null) !== $this->uid || ($s['spec_sha256'] ?? null) !== hash('sha256', self::encode($spec))
            || !is_int($s['sequence'] ?? null) || $s['sequence'] < 0 || !is_array($s['completed'] ?? null) || !is_array($s['peer_outcomes'] ?? null)
            || !in_array($s['direction'] ?? null, ['release', 'recovery', 'preparation-abort'], true)
            || !is_bool($s['activation_may_have_started'] ?? null)
            || !array_key_exists('pending', $s) || !array_key_exists('terminal', $s)) throw new RuntimeException('Release control state is invalid.');
        if ($s['pending'] !== null) {
            if (!is_array($s['pending']) || !is_string($s['pending']['action'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $s['pending']['token'] ?? '')
                || !preg_match('/^[a-f0-9]{64}$/D', $s['pending']['evidence_sha256'] ?? '')) throw new RuntimeException('Pending release intent is invalid.');
        }
        $release = $this->uid === 1004
            ? ['hold', 'prepare-files', 'upgrade-database', 'apply-files', 'verify-files', 'refresh-cache', 'activate', 'complete']
            : ['hold', 'prepare-files', 'apply-files', 'verify-files', 'resume-scheduler'];
        $recovery = $this->uid === 1004
            ? ['restore-database', 'recover-files', 'verify-recovery', 'refresh-recovery-cache', 'restore-routing']
            : ['recover-files', 'verify-recovery', 'restore-scheduler'];
        foreach ($s['completed'] as $action => $hash) {
            if (!in_array($action, array_merge($release, $recovery, self::PREPARATION_ABORT), true) || !is_string($hash)
                || !preg_match('/^[a-f0-9]{64}$/D', $hash)
                || !hash_equals($hash, hash('sha256', $this->read($directory . '/' . $action . '.json')))) {
                throw new RuntimeException('Completed release outcome is missing or changed.');
            }
        }
        foreach ($s['peer_outcomes'] as $action => $hash) {
            if (!in_array($action, ['hold','prepare-files','upgrade-database','verify-files','activate','resume-scheduler','restore-database','verify-recovery','restore-routing','verify-preparation-abort','restore-preparation-hold'], true)
                || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)
                || !hash_equals($hash, hash('sha256', $this->read($directory . '/peer-' . $action . '.json')))) {
                throw new RuntimeException('Other-owner outcome is missing or changed.');
            }
        }
        foreach ([$release, $recovery, self::PREPARATION_ABORT] as $order) {
            $gap = false;
            foreach ($order as $action) {
                if (!isset($s['completed'][$action])) $gap = true;
                elseif ($gap) throw new RuntimeException('Completed release outcomes are out of order.');
            }
        }
        $next = null;
        $order = match ($s['direction']) { 'release' => $release, 'recovery' => $recovery, 'preparation-abort' => self::PREPARATION_ABORT };
        foreach ($order as $action) {
            if (!isset($s['completed'][$action])) { $next = $action; break; }
        }
        if (($s['pending'] !== null && $s['pending']['action'] !== $next)
            || ($s['direction'] === 'release' && array_intersect(array_keys($s['completed']), $recovery))
            || ($s['direction'] === 'recovery' && $s['activation_may_have_started'])
            || ((isset($s['completed']['activate']) || ($s['pending']['action'] ?? null) === 'activate') && !$s['activation_may_have_started'])) {
            throw new RuntimeException('Release direction or pending phase is inconsistent.');
        }
        if ($s['direction'] === 'preparation-abort') {
            if ($s['activation_may_have_started'] || !array_key_exists('interrupted_release_intent', $s)
                || array_diff(array_keys($s['completed']), array_merge(['hold','prepare-files'], self::PREPARATION_ABORT))
                || array_diff(array_keys($s['peer_outcomes']), ['hold','prepare-files','verify-preparation-abort','restore-preparation-hold'])
                || ($s['interrupted_release_intent'] !== null && !in_array($s['interrupted_release_intent']['action'] ?? null, ['hold','prepare-files'], true))) {
                throw new RuntimeException('Preparation abort cannot include a possible code or database mutation.');
            }
        } elseif (array_intersect(array_keys($s['completed']), self::PREPARATION_ABORT)) {
            throw new RuntimeException('Preparation abort cannot return to release or database recovery.');
        }
        if (!in_array($s['terminal'], [null, 'complete', 'recovered', 'unstarted', 'preparation-aborted'], true)
            || ($s['terminal'] === 'complete' && !isset($s['completed'][end($release)]))
            || ($s['terminal'] === 'recovered' && !isset($s['completed'][end($recovery)]))
            || ($s['terminal'] === 'preparation-aborted' && ($s['direction'] !== 'preparation-abort'
                || !isset($s['completed']['complete-preparation-abort'])))
            || ($s['terminal'] === 'unstarted' && ($s['completed'] || $s['pending'] !== null || $s['sequence'] !== 0
                || $s['direction'] !== 'release'))) throw new RuntimeException('Terminal release state is incomplete.');
    }

    public function snapshot(): array
    {
        if (self::encode($this->state) !== $this->read($this->operationDirectory . '/state.json')) throw new RuntimeException('Release control state changed outside its lock.');
        $this->validateState();
        return $this->state;
    }

    /** Read an already completed, hash-verified outcome after a lost transport response. Never executes or resolves an action. */
    public function completedOutcome(string $action): array
    {
        $state = $this->snapshot();
        if (!isset($state['completed'][$action])) throw new RuntimeException('Completed owner outcome required.');
        return json_decode($this->read($this->operationDirectory . '/' . $action . '.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    private function save(): void
    {
        $this->validateState();
        $this->replace($this->operationDirectory . '/state.json', self::encode($this->state));
    }

    /** Persist intent before any side effect. An unfinished intent never grants permission to run it again. */
    public function begin(string $action, string $evidenceHash): string
    {
        $s = $this->snapshot();
        if ($s['terminal'] !== null || $s['pending'] !== null || isset($s['completed'][$action])) throw new RuntimeException('Existing operation outcome must be inspected; automatic replay is refused.');
        if (!preg_match('/^[a-f0-9]{64}$/D', $evidenceHash)) throw new RuntimeException('Exact action evidence required.');
        $order = $this->uid === 1004
            ? ['hold', 'prepare-files', 'upgrade-database', 'apply-files', 'verify-files', 'refresh-cache', 'activate', 'complete']
            : ['hold', 'prepare-files', 'apply-files', 'verify-files', 'resume-scheduler'];
        if ($s['direction'] === 'recovery') $order = $this->uid === 1004
            ? ['restore-database', 'recover-files', 'verify-recovery', 'refresh-recovery-cache', 'restore-routing']
            : ['recover-files', 'verify-recovery', 'restore-scheduler'];
        if ($s['direction'] === 'preparation-abort') $order = self::PREPARATION_ABORT;
        $next = null;
        foreach ($order as $step) if (!isset($s['completed'][$step])) { $next = $step; break; }
        if ($action !== $next) throw new RuntimeException('Release phase is out of order for this owner or recovery direction.');
        $peer = $this->peerRequiredFor($action);
        if ($peer !== null && !isset($s['peer_outcomes'][$peer])) throw new RuntimeException('Verified other-owner outcome required before this release phase.');
        $token = bin2hex(random_bytes(32));
        $this->state['sequence']++;
        $this->state['pending'] = ['action' => $action, 'token' => $token, 'evidence_sha256' => $evidenceHash];
        if ($action === 'activate') $this->state['activation_may_have_started'] = true;
        $this->save();
        return $token;
    }

    /** The caller must independently verify the actual native outcome; this method never reruns an action. */
    public function finish(string $action, string $token, array $receipt): array
    {
        $s = $this->snapshot(); $pending = $s['pending'];
        if (!is_array($pending) || $pending['action'] !== $action || !hash_equals($pending['token'], $token)) throw new RuntimeException('Exact pending action receipt required.');
        foreach (['operation', 'candidate_commit', 'manifest_sha256', 'migration_plan_sha256', 'root'] as $key) {
            if (($receipt[$key] ?? null) !== $this->spec[$key]) throw new RuntimeException('Outcome belongs to another release.');
        }
        if (($receipt['owner_uid'] ?? null) !== $this->uid || ($receipt['action'] ?? null) !== $action
            || ($receipt['owner_plan_sha256'] ?? null) !== $this->spec['owner_plan_sha256'][$this->uid]
            || ($receipt['evidence_sha256'] ?? null) !== $pending['evidence_sha256']
            || !is_string($receipt['native_receipt_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $receipt['native_receipt_sha256'])) {
            throw new RuntimeException('Verified native outcome fingerprint differs.');
        }
        $bytes = self::encode($receipt); $path = $this->operationDirectory . '/' . $action . '.json';
        if (file_exists($path) || is_link($path)) {
            if ($this->read($path) !== $bytes) throw new RuntimeException('Previously recorded outcome differs.');
        } else $this->writeNew($path, $bytes);
        $this->state['completed'][$action] = hash('sha256', $bytes);
        $this->state['pending'] = null;
        if (in_array($action, ['complete', 'resume-scheduler'], true)) $this->state['terminal'] = 'complete';
        if (in_array($action, ['restore-routing', 'restore-scheduler'], true)) $this->state['terminal'] = 'recovered';
        if ($action === 'complete-preparation-abort') $this->state['terminal'] = 'preparation-aborted';
        $this->save();
        return $receipt;
    }

    public function perform(string $action, string $evidenceHash, callable $nativeAction): array
    {
        $token = $this->begin($action, $evidenceHash);
        $nativeReceipt = $nativeAction();
        if (!is_array($nativeReceipt)) throw new RuntimeException('Native action did not return a verified receipt; inspect outcome.');
        return $this->finish($action, $token, $this->outcome($action, $evidenceHash, hash('sha256', self::encode($nativeReceipt))));
    }

    public function outcome(string $action, string $evidenceHash, string $nativeHash): array
    {
        return ['operation' => $this->spec['operation'], 'candidate_commit' => $this->spec['candidate_commit'],
            'manifest_sha256' => $this->spec['manifest_sha256'], 'migration_plan_sha256' => $this->spec['migration_plan_sha256'],
            'root' => self::ROOT, 'owner_uid' => $this->uid, 'owner_plan_sha256' => $this->spec['owner_plan_sha256'][$this->uid],
            'action' => $action, 'evidence_sha256' => $evidenceHash, 'native_receipt_sha256' => $nativeHash];
    }

    public function peerRequiredFor(string $action): ?string
    {
        if ($action === 'request-preparation-abort' && $this->uid === 1003) return 'verify-preparation-abort';
        if ($action === 'restore-preparation-hold') return 'verify-preparation-abort';
        if ($action === 'complete-preparation-abort') return 'restore-preparation-hold';
        $required = $this->uid === 1004
            ? ['prepare-files'=>'hold', 'upgrade-database'=>'prepare-files', 'refresh-cache'=>'verify-files', 'complete'=>'resume-scheduler', 'restore-routing'=>'verify-recovery']
            : ['prepare-files'=>'hold', 'apply-files'=>'upgrade-database', 'resume-scheduler'=>'activate',
                'recover-files'=>'restore-database', 'restore-scheduler'=>'restore-routing'];
        return $required[$action] ?? null;
    }

    /** Accept only the direct other-owner worker's verified response, transported by the external coordinator. */
    public function acceptPeerOutcome(array $receipt): void
    {
        $s = $this->snapshot(); $uid = $this->uid === 1003 ? 1004 : 1003; $action = $receipt['action'] ?? null;
        if ($s['terminal'] !== null || !in_array($action, ['hold','prepare-files','upgrade-database','verify-files','activate','resume-scheduler',
            'restore-database','verify-recovery','restore-routing','verify-preparation-abort','restore-preparation-hold'], true)) throw new RuntimeException('Recognized other-owner outcome required.');
        foreach (['operation','candidate_commit','manifest_sha256','migration_plan_sha256','root'] as $key) {
            if (($receipt[$key] ?? null) !== $this->spec[$key]) throw new RuntimeException('Other-owner outcome belongs to a different release.');
        }
        if (($receipt['owner_uid'] ?? null) !== $uid || ($receipt['owner_plan_sha256'] ?? null) !== $this->spec['owner_plan_sha256'][$uid]) {
            throw new RuntimeException('Other-owner identity or plan differs.');
        }
        foreach (['evidence_sha256','native_receipt_sha256'] as $key) {
            if (!is_string($receipt[$key] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $receipt[$key])) throw new RuntimeException('Other-owner evidence fingerprint missing.');
        }
        $bytes = self::encode($receipt); $path = $this->operationDirectory . '/peer-' . $action . '.json';
        if (file_exists($path) || is_link($path)) {
            if ($this->read($path) !== $bytes) throw new RuntimeException('Previously observed other-owner outcome differs.');
        } else $this->writeNew($path, $bytes);
        $this->state['peer_outcomes'][$action] = hash('sha256',$bytes); $this->save();
    }

    /** Recovery is deliberate and permanent. Unknown activation outcomes need a new reviewed hold/backup, not old data restoration. */
    public function requestRecovery(): void
    {
        $s = $this->snapshot();
        if ($s['terminal'] !== null || $s['activation_may_have_started'] || $s['direction'] === 'preparation-abort') throw new RuntimeException('Original-backup recovery is forbidden in this release state.');
        if ($s['direction'] === 'recovery') return;
        $this->state['direction'] = 'recovery';
        $this->state['interrupted_release_intent'] = $s['pending'];
        $this->state['pending'] = null;
        $this->state['sequence']++;
        $this->save();
    }

    /** Permanently fence a failed preparation before either account reopens original routing or jobs. */
    public function requestPreparationAbort(): void
    {
        $s = $this->snapshot();
        if ($s['terminal'] !== null) throw new RuntimeException('Preparation abort is already terminal or unavailable.');
        if ($s['direction'] === 'preparation-abort') return;
        if ($s['direction'] !== 'release' || $s['activation_may_have_started']
            || array_diff(array_keys($s['completed']), ['hold','prepare-files'])
            || array_diff(array_keys($s['peer_outcomes']), ['hold','prepare-files','verify-preparation-abort'])
            || ($s['pending'] !== null && !in_array($s['pending']['action'], ['hold','prepare-files'], true))) {
            throw new RuntimeException('A possible database or code change requires paired recovery, not preparation abort.');
        }
        // Fence the only database-upgrade owner first. Otherwise it could begin SQL after a
        // master's observation but before its own abort, leaving the owners in incompatible directions.
        if ($this->uid === 1003 && !isset($s['peer_outcomes']['verify-preparation-abort'])) {
            throw new RuntimeException('Application-owner preparation abort must be verified before fencing the master.');
        }
        $this->state['direction'] = 'preparation-abort';
        $this->state['interrupted_release_intent'] = $s['pending'];
        $this->state['pending'] = null;
        $this->state['sequence']++;
        $this->save();
    }

    public function abandonUnstarted(): void
    {
        $s = $this->snapshot();
        if ($s['pending'] !== null || $s['completed'] || $s['activation_may_have_started'] || $s['terminal'] !== null
            || $s['sequence'] !== 0 || $s['direction'] !== 'release') {
            throw new RuntimeException('Only an operation with no action intent can be abandoned.');
        }
        $this->state['terminal'] = 'unstarted'; $this->save();
    }

    public function assertUpgrade(array $binding, string $bindingHash): void
    {
        $s = $this->snapshot();
        if ($this->uid !== 1004 || $s['direction'] !== 'release' || $s['terminal'] !== null
            || ($s['pending']['action'] ?? null) !== 'upgrade-database') throw new RuntimeException('Application-owner database upgrade intent is not held.');
        foreach (['operation', 'candidate_commit', 'manifest_sha256', 'migration_plan_sha256', 'root'] as $key) {
            if (($binding[$key] ?? null) !== $this->spec[$key]) throw new RuntimeException('Database upgrade differs from the active coordinated release.');
        }
        if (($binding['release_control_spec_sha256'] ?? null) !== $this->specHash) throw new RuntimeException('Database release control fingerprint differs.');
        if (($s['pending']['evidence_sha256'] ?? null) !== $bindingHash) throw new RuntimeException('Database binding differs from the durable upgrade intent.');
    }

    public function assertBackupPair(array $pair): void
    {
        $this->snapshot();
        foreach (['operation', 'candidate_commit', 'migration_plan_sha256', 'root'] as $key) {
            if (($pair[$key] ?? null) !== $this->spec[$key]) throw new RuntimeException('Backup pair differs from coordinated release.');
        }
        foreach ([1003, 1004] as $uid) {
            if (($pair['file_owners'][$uid]['plan_sha256'] ?? null) !== $this->spec['owner_plan_sha256'][$uid]) {
                throw new RuntimeException('Prepared owner backup does not match the reviewed release file plan.');
            }
        }
    }
}
