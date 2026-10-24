<?php
/** Linux host primitives. No executable entry point, database upgrade or production authorization. */
declare(strict_types=1);
require_once __DIR__ . '/full_core_maintenance.php';

final class FullCoreMaintenanceHost
{
    private $lock;

    public function __construct(private string $root, private string $privateParent)
    {
        if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid')
            || !is_executable('/usr/bin/crontab')) throw new RuntimeException('Linux maintenance CLI prerequisites missing.');
        foreach ([$root, $privateParent] as $directory) {
            if (realpath($directory) !== $directory || is_link($directory) || !is_dir($directory)) {
                throw new RuntimeException('Canonical unlinked maintenance directories required.');
            }
        }
        if (basename($root) !== 'public_html' || !preg_match('~^/[A-Za-z0-9_./-]+$~D', $root)
            || str_starts_with($privateParent . '/', $root . '/')
            || fileowner($privateParent) !== posix_geteuid() || (fileperms($privateParent) & 0777) !== 0700) {
            throw new RuntimeException('Private recovery directory must be owned by this CLI user and outside the webroot.');
        }
        $lockPath = $privateParent . '/maintenance.lock';
        if (is_link($lockPath) || (file_exists($lockPath)
            && (!is_file($lockPath) || fileowner($lockPath) !== posix_geteuid() || (fileperms($lockPath) & 0777) !== 0600))) {
            throw new RuntimeException('Unexpected maintenance lock.');
        }
        $oldMask = umask(0077);
        try { $this->lock = fopen($lockPath, 'c+b'); } finally { umask($oldMask); }
        if (!$this->lock || !flock($this->lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another host maintenance operation holds the lock.');
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
    }

    /** The caller must keep the command text private. Empty installed crontabs are supported. */
    public function crontab(): string
    {
        return $this->cronCommand(['-l']);
    }

    private function cronCommand(array $args, ?string $inputFile = null): string
    {
        $diagnostic = $this->privateParent . '/scheduler-' . bin2hex(random_bytes(8)) . '.stderr';
        $this->privateWrite($diagnostic, '');
        $process = proc_open(array_merge(['/usr/bin/crontab'], $args),
            [0 => ['file', $inputFile ?? '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $diagnostic, 'w']], $pipes,
            null, array_merge(getenv(), ['LC_ALL' => 'C']));
        if (!is_resource($process)) throw new RuntimeException('Cannot invoke the current user scheduler.');
        $output = stream_get_contents($pipes[1], 1048577); fclose($pipes[1]);
        $exit = proc_close($process);
        if ($exit !== 0 || $output === false || strlen($output) > 1048576) {
            throw new RuntimeException('Scheduler command failed (exit ' . $exit . '); private diagnostic: ' . basename($diagnostic));
        }
        if (filesize($diagnostic) === 0) unlink($diagnostic);
        return $output;
    }

    public function webSnapshot(): array
    {
        $path = $this->root . '/.htaccess'; clearstatcache(true, $path);
        $stat = lstat($path);
        if (!$stat || is_link($path) || !is_file($path) || $stat['nlink'] !== 1
            || realpath($path) !== $path || $stat['uid'] !== posix_geteuid()
            || !in_array($stat['mode'] & 07777, [0644, 0664], true)) {
            throw new RuntimeException('Web configuration must be a single ordinary 0644/0664 file owned by this CLI user.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Cannot read web configuration.');
        return ['sha256' => hash('sha256', $bytes), 'uid' => $stat['uid'], 'gid' => $stat['gid'], 'mode' => $stat['mode'] & 07777];
    }

    private function assertWeb(array $expected): void
    {
        if ($this->webSnapshot() !== $expected) throw new RuntimeException('Web configuration bytes or ownership changed.');
    }

    private function privateWrite(string $path, string $bytes): void
    {
        $oldMask = umask(0077);
        try { $stream = @fopen($path, 'x+b'); } finally { umask($oldMask); }
        if (!$stream) throw new RuntimeException('New private recovery file required.');
        try {
            if (!chmod($path, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) {
                throw new RuntimeException('Cannot persist private recovery material.');
            }
        } finally { fclose($stream); }
    }

    private function privateRead(string $path): string
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        if (!$stat || is_link($path) || !is_file($path) || $stat['nlink'] !== 1
            || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0777) !== 0600) {
            throw new RuntimeException('Recovery material is missing, linked or not private.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Cannot read recovery material.');
        return $bytes;
    }

    private function operationPath(string $operation): string
    {
        fullCoreMaintenanceWebRule($operation);
        return $this->privateParent . '/maintenance-' . $operation;
    }

    /** Preserve UID, GID and mode on the replacement inode; refuse before rename if that is impossible. */
    private function replaceWeb(array $expected, string $bytes): void
    {
        $this->assertWeb($expected);
        $temporary = tempnam($this->root, '.core-maintenance-');
        if ($temporary === false) throw new RuntimeException('Cannot stage web configuration.');
        try {
            $stream = fopen($temporary, 'wb');
            try {
                if (!$stream || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)
                    || !chgrp($temporary, $expected['gid']) || !chmod($temporary, $expected['mode'])) {
                    throw new RuntimeException('Cannot preserve web configuration contents and metadata.');
                }
            } finally { if (is_resource($stream)) fclose($stream); }
            clearstatcache(true, $temporary); $stat = stat($temporary);
            if (!$stat || $stat['uid'] !== $expected['uid'] || $stat['gid'] !== $expected['gid']
                || ($stat['mode'] & 07777) !== $expected['mode']) throw new RuntimeException('Staged web configuration metadata differs.');
            $this->assertWeb($expected);
            if (!rename($temporary, $this->root . '/.htaccess')) throw new RuntimeException('Cannot install web configuration.');
            $expected['sha256'] = hash('sha256', $bytes); $this->assertWeb($expected);
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    private function replaceCron(string $expectedHash, string $path, string $nextHash): void
    {
        fullCoreMaintenanceDigest($this->privateRead($path), $nextHash);
        fullCoreMaintenanceDigest($this->crontab(), $expectedHash);
        // Some native crontab clients truncate long filename arguments. Pass the verified file
        // descriptor as stdin; never shorten/move the private recovery file or expose its bytes.
        $this->cronCommand(['-'], $path);
        fullCoreMaintenanceDigest($this->crontab(), $nextHash);
    }

    /** Deliberately leaves recovery material/any installed hold on error. The release adapter decides recovery. */
    public function pause(string $operation, array $expectedWeb, string $expectedCronHash, array $review): array
    {
        $this->assertWeb($expectedWeb);
        $original = file_get_contents($this->root . '/.htaccess');
        if ($original === false || str_contains($original, '# CoreFlux maintenance ')) throw new RuntimeException('Unresolved web maintenance.');
        fullCoreMaintenanceDigest($original, $expectedWeb['sha256']);
        $cron = $this->crontab();
        $plan = fullCoreMaintenanceCron($cron, $expectedCronHash, $this->root, $review, $operation);
        $private = $this->operationPath($operation);
        if (file_exists($private) || is_link($private) || !mkdir($private, 0700)) throw new RuntimeException('New operation required; recover existing maintenance explicitly.');
        $held = fullCoreMaintenanceWebRule($operation);
        $receipt = ['version' => 1, 'operation' => $operation, 'root' => $this->root, 'cli_uid' => posix_geteuid(),
            'original_web' => $expectedWeb, 'held_web_sha256' => hash('sha256', $held),
            'original_cron_sha256' => $expectedCronHash, 'held_cron_sha256' => $plan['held_sha256'],
            'review' => $review, 'paused_jobs' => $plan['paused_jobs'], 'retained_jobs' => $plan['retained_jobs']];
        $this->privateWrite($private . '/original.htaccess', $original);
        $this->privateWrite($private . '/original.crontab', $cron);
        $this->privateWrite($private . '/held.crontab', $plan['held_bytes']);
        $this->privateWrite($private . '/receipt.json', json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        // Check both immediately before the first mutation; neither native operation offers cross-file CAS.
        fullCoreMaintenanceDigest($this->crontab(), $expectedCronHash);
        $this->replaceWeb($expectedWeb, $held);
        $this->replaceCron($expectedCronHash, $private . '/held.crontab', $plan['held_sha256']);
        $this->assertHeld($operation);
        return ['operation' => $operation, 'paused_jobs' => $plan['paused_jobs'], 'retained_jobs' => $plan['retained_jobs'],
            'business_writers_drained' => false];
    }

    private function receipt(string $operation): array
    {
        $private = $this->operationPath($operation);
        if (realpath($private) !== $private || is_link($private) || fileowner($private) !== posix_geteuid()
            || (fileperms($private) & 0777) !== 0700) throw new RuntimeException('Private recovery operation required.');
        $receipt = json_decode($this->privateRead($private . '/receipt.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($receipt['version'] ?? null) !== 1 || ($receipt['operation'] ?? '') !== $operation
            || ($receipt['root'] ?? '') !== $this->root || ($receipt['cli_uid'] ?? null) !== posix_geteuid()) {
            throw new RuntimeException('Recovery operation identity differs.');
        }
        fullCoreMaintenanceDigest($this->privateRead($private . '/original.htaccess'), $receipt['original_web']['sha256']);
        fullCoreMaintenanceDigest(fullCoreMaintenanceWebRule($operation), $receipt['held_web_sha256']);
        $plan = fullCoreMaintenanceCron($this->privateRead($private . '/original.crontab'),
            $receipt['original_cron_sha256'], $this->root, $receipt['review'], $operation);
        fullCoreMaintenanceDigest($plan['held_bytes'], $receipt['held_cron_sha256']);
        fullCoreMaintenanceDigest($this->privateRead($private . '/held.crontab'), $receipt['held_cron_sha256']);
        return $receipt;
    }

    /** Only web-file and current-user scheduler state; not proof of HTTP behavior or drained workers. */
    public function assertHeld(string $operation): void
    {
        $receipt = $this->receipt($operation);
        $web = $receipt['original_web']; $web['sha256'] = $receipt['held_web_sha256'];
        $this->assertWeb($web);
        fullCoreMaintenanceDigest($this->crontab(), $receipt['held_cron_sha256']);
    }

    private function activation(string $operation): ?array
    {
        $private = $this->operationPath($operation);
        $path = $private . '/activation.json';
        if (!file_exists($path) && !is_link($path)) return null;
        $activation = json_decode($this->privateRead($path), true, 512, JSON_THROW_ON_ERROR);
        if (($activation['version'] ?? null) !== 1 || ($activation['operation'] ?? '') !== $operation
            || ($activation['root'] ?? '') !== $this->root
            || ($activation['maintenance_receipt_sha256'] ?? '') !== hash('sha256', $this->privateRead($private . '/receipt.json'))
            || !is_string($activation['revision'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $activation['revision'])
            || !is_string($activation['manifest_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $activation['manifest_sha256'])
            || !is_string($activation['web_sha256'] ?? null)) {
            throw new RuntimeException('Activation recovery identity differs.');
        }
        fullCoreMaintenanceDigest($this->privateRead($private . '/candidate.htaccess'), $activation['web_sha256']);
        return $activation;
    }

    private function assertRecoveryNotStarted(string $operation): void
    {
        $path = $this->operationPath($operation) . '/restore-requested.json';
        if (file_exists($path) || is_link($path)) throw new RuntimeException('Recovery was requested; this operation cannot activate a release.');
    }

    /** Bind reviewed routing while held. The caller must verify the whole release manifest and approval separately. */
    public function prepareActivation(string $operation, string $candidateWeb, string $expectedWebHash,
        string $revision, string $manifestHash): array
    {
        fullCoreMaintenanceDigest($candidateWeb, $expectedWebHash);
        if ($candidateWeb === '' || strlen($candidateWeb) > 1048576 || str_contains($candidateWeb, "\0")
            || str_contains($candidateWeb, '# CoreFlux maintenance ')
            || !preg_match('/^[a-f0-9]{40}$/D', $revision) || !preg_match('/^[a-f0-9]{64}$/D', $manifestHash)) {
            throw new RuntimeException('Reviewed release identity and non-maintenance routing required.');
        }
        $receipt = $this->receipt($operation); $private = $this->operationPath($operation);
        $this->assertRecoveryNotStarted($operation);
        $this->assertHeld($operation);
        $expected = ['version' => 1, 'operation' => $operation, 'root' => $this->root,
            'maintenance_receipt_sha256' => hash('sha256', $this->privateRead($private . '/receipt.json')),
            'revision' => $revision, 'manifest_sha256' => $manifestHash, 'web_sha256' => $expectedWebHash];
        $existing = $this->activation($operation);
        if ($existing !== null) {
            if ($existing !== $expected) throw new RuntimeException('A different release is already prepared for this operation.');
            return $existing;
        }
        // A process can stop between these durable writes. Reuse only identical orphan candidate bytes.
        $candidate = $private . '/candidate.htaccess';
        if (file_exists($candidate) || is_link($candidate)) {
            fullCoreMaintenanceDigest($this->privateRead($candidate), $expectedWebHash);
        } else {
            $this->privateWrite($candidate, $candidateWeb);
        }
        $this->privateWrite($private . '/activation.json', json_encode($expected, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->assertHeld($operation);
        return $expected;
    }

    /** Reopen reviewed candidate routing, never the old routing. Only call after complete release verification and approval. */
    public function activate(string $operation, string $revision, string $manifestHash): void
    {
        $receipt = $this->receipt($operation); $private = $this->operationPath($operation);
        $this->assertRecoveryNotStarted($operation);
        $activation = $this->activation($operation);
        if ($activation === null || $activation['revision'] !== $revision || $activation['manifest_sha256'] !== $manifestHash) {
            throw new RuntimeException('Exact prepared release identity required for activation.');
        }
        $heldWeb = $receipt['original_web']; $heldWeb['sha256'] = $receipt['held_web_sha256'];
        $candidateWeb = $receipt['original_web']; $candidateWeb['sha256'] = $activation['web_sha256'];
        $web = $this->webSnapshot(); $cronHash = hash('sha256', $this->crontab());
        if (!in_array($web, [$heldWeb, $candidateWeb], true)
            || !in_array($cronHash, [$receipt['held_cron_sha256'], $receipt['original_cron_sha256']], true)) {
            throw new RuntimeException('Concurrent web/scheduler edit; activation must not overwrite it.');
        }
        if ($web !== $candidateWeb) $this->replaceWeb($heldWeb, $this->privateRead($private . '/candidate.htaccess'));
        if ($cronHash !== $receipt['original_cron_sha256']) {
            $this->replaceCron($receipt['held_cron_sha256'], $private . '/original.crontab', $receipt['original_cron_sha256']);
        }
        $this->assertWeb($candidateWeb);
        fullCoreMaintenanceDigest($this->crontab(), $receipt['original_cron_sha256']);
    }

    /** Explicit recovery only, after matching file/database recovery. A successful release must use activate(). */
    public function restore(string $operation): void
    {
        $receipt = $this->receipt($operation); $private = $this->operationPath($operation);
        $web = $this->webSnapshot(); $cronHash = hash('sha256', $this->crontab());
        $heldWeb = $receipt['original_web']; $heldWeb['sha256'] = $receipt['held_web_sha256'];
        $allowedWeb = [$receipt['original_web'], $heldWeb];
        $activation = $this->activation($operation);
        if ($activation !== null) {
            $candidateWeb = $receipt['original_web']; $candidateWeb['sha256'] = $activation['web_sha256'];
            $allowedWeb[] = $candidateWeb;
        }
        // Validate both before restoring either, including the partial-restoration/recovery case.
        if (!in_array($web, $allowedWeb, true)
            || !in_array($cronHash, [$receipt['original_cron_sha256'], $receipt['held_cron_sha256']], true)) {
            throw new RuntimeException('Concurrent web/scheduler edit; recovery must not overwrite it.');
        }
        // Persist the direction before reopening anything. A stale activate retry must not resurrect a rolled-back release.
        $intent = json_encode(['version' => 1, 'operation' => $operation,
            'maintenance_receipt_sha256' => hash('sha256', $this->privateRead($private . '/receipt.json'))], JSON_THROW_ON_ERROR);
        $intentPath = $private . '/restore-requested.json';
        if (file_exists($intentPath) || is_link($intentPath)) {
            if ($this->privateRead($intentPath) !== $intent) throw new RuntimeException('Recovery request identity differs.');
        } else {
            $this->privateWrite($intentPath, $intent);
        }
        if ($web !== $receipt['original_web']) $this->replaceWeb($web, $this->privateRead($private . '/original.htaccess'));
        if ($cronHash !== $receipt['original_cron_sha256']) {
            $this->replaceCron($receipt['held_cron_sha256'], $private . '/original.crontab', $receipt['original_cron_sha256']);
        }
        $this->assertWeb($receipt['original_web']);
        fullCoreMaintenanceDigest($this->crontab(), $receipt['original_cron_sha256']);
    }
}
