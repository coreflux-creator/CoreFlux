<?php
/** Per-owner actual-content replacement/recovery primitives. No executable production entry point. */
declare(strict_types=1);

final class FullCoreReleaseFiles
{
    private array $plan;
    private string $planHash;
    private $lock;
    private Closure $guard;
    private int $uid;
    private array $manifestFiles;

    public function __construct(private string $root, private string $package, private string $private,
        string $planBytes, string $reviewedPlanHash, callable $maintenanceGuard)
    {
        if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid')) {
            throw new RuntimeException('Native Linux CLI required.');
        }
        $this->uid = posix_geteuid();
        if ($this->uid === 0) throw new RuntimeException('Use the reviewed file owner, not root.');
        $this->guard = Closure::fromCallable($maintenanceGuard);
        foreach ([$root, $package, $private] as $directory) {
            if (realpath($directory) !== $directory || is_link($directory) || !is_dir($directory)) {
                throw new RuntimeException('Canonical unlinked release directories required.');
            }
        }
        if (basename($root) !== 'public_html' || str_starts_with($package . '/', $root . '/')
            || str_starts_with($private . '/', $root . '/') || $package === $private
            || fileowner($package) !== $this->uid || fileowner($private) !== $this->uid
            || (fileperms($package) & 07777) !== 0700 || (fileperms($private) & 07777) !== 0700
            || stat($root)['dev'] !== stat($private)['dev']) {
            throw new RuntimeException('Separate owner-private staging and same-filesystem recovery directories required.');
        }
        self::digest($planBytes, $reviewedPlanHash);
        $this->planHash = $reviewedPlanHash;
        $this->plan = json_decode($planBytes, true, 512, JSON_THROW_ON_ERROR);
        $this->validatePlan();
        $manifestPath = $this->path($package, 'integration-manifest.json');
        $manifestMetadata = self::metadata($manifestPath, true);
        if (!$manifestMetadata || $manifestMetadata['uid'] !== $this->uid) throw new RuntimeException('Owner-staged manifest required.');
        $manifestBytes = (string) file_get_contents($manifestPath);
        self::digest($manifestBytes, $this->plan['manifest_sha256']);
        $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['product'] ?? null) !== 'full-core-staging' || ($manifest['commit'] ?? null) !== $this->plan['candidate_commit']
            || !is_array($manifest['files'] ?? null)) throw new RuntimeException('Candidate manifest identity differs.');
        foreach ($this->plan['files'] as $relative => $entry) {
            if (($manifest['files'][$relative] ?? null) !== $entry['after']['sha256']) throw new RuntimeException('Owner plan differs from full release manifest.');
        }
        $this->manifestFiles = $manifest['files'];
        $lock = $private . '/files.lock';
        if (file_exists($lock) || is_link($lock)) $this->privateRead($lock);
        $old = umask(0077);
        try { $this->lock = fopen($lock, 'c+b'); } finally { umask($old); }
        if (!$this->lock || !flock($this->lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another owner-file operation holds the lock.');
        $saved = $private . '/plan.json';
        if (file_exists($saved) || is_link($saved)) self::digest($this->privateRead($saved), $reviewedPlanHash);
        else $this->writePrivate($saved, $planBytes);
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
    }

    private static function digest(string $bytes, string $hash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || !hash_equals($hash, hash('sha256', $bytes))) {
            throw new RuntimeException('Reviewed release digest differs.');
        }
    }

    private static function relative(string $path): bool
    {
        if ($path === '' || strlen($path) > 1024 || str_starts_with($path, '/')
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) return false;
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') return false;
        }
        return true;
    }

    private function path(string $base, string $relative): string
    {
        if (!self::relative($relative)) throw new RuntimeException('Unsafe release path.');
        $at = $base;
        foreach (explode('/', $relative) as $part) {
            $at .= '/' . $part;
            if (is_link($at)) throw new RuntimeException('Linked release path refused.');
        }
        return $at;
    }

    private static function metadata(string $path, bool $file): ?array
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        if (!$stat) return null;
        if (is_link($path) || realpath($path) !== $path || ($file && (!is_file($path) || $stat['nlink'] !== 1))
            || (!$file && !is_dir($path))) throw new RuntimeException('Ordinary unlinked release path required.');
        $value = ['uid' => $stat['uid'], 'gid' => $stat['gid'], 'mode' => $stat['mode'] & 07777];
        if ($file) $value += ['sha256' => hash_file('sha256', $path), 'size' => $stat['size'], 'mtime' => $stat['mtime']];
        return $value;
    }

    private static function same(?array $a, ?array $b): bool
    {
        if ($a !== null) ksort($a);
        if ($b !== null) ksort($b);
        return $a === $b;
    }

    private function validateMetadata(array $value, bool $file): void
    {
        $keys = array_keys($value); sort($keys);
        $expected = $file ? ['gid', 'mode', 'mtime', 'sha256', 'size', 'uid'] : ['gid', 'mode', 'uid'];
        if ($keys !== $expected || !is_int($value['uid']) || $value['uid'] < 1 || !is_int($value['gid']) || $value['gid'] < 0
            || !is_int($value['mode']) || ($file ? !in_array($value['mode'], [0644, 0664], true)
                : !in_array($value['mode'], [0700, 0750, 0755, 0770, 0775], true))) {
            throw new RuntimeException('Unsupported release metadata.');
        }
        if ($file && (!is_int($value['mtime']) || $value['mtime'] < 0 || !is_int($value['size']) || $value['size'] < 0
            || !is_string($value['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $value['sha256']))) {
            throw new RuntimeException('Invalid file fingerprint.');
        }
    }

    private function validatePlan(): void
    {
        $p = $this->plan;
        if (($p['version'] ?? null) !== 1 || ($p['root'] ?? null) !== $this->root || ($p['owner_uid'] ?? null) !== $this->uid
            || !preg_match('/^[a-f0-9]{32}$/D', $p['operation'] ?? '')
            || !preg_match('/^[a-f0-9]{40}$/D', $p['candidate_commit'] ?? '')
            || !preg_match('/^[a-f0-9]{64}$/D', $p['manifest_sha256'] ?? '')
            || !is_array($p['files'] ?? null) || !is_array($p['directories'] ?? null)
            || count($p['files']) > 20000 || count($p['directories']) > 10000) {
            throw new RuntimeException('Exact reviewed owner-file plan required.');
        }
        foreach ($p['files'] as $relative => $entry) {
            if (!is_string($relative) || !self::relative($relative)
                || in_array($relative, ['.htaccess', 'core/migrations/007_subtenant_provisioning.sql',
                    'core/migrations/041_csv_mapping_presets.sql', 'core/migrations/043_simulation_harness.sql'], true)
                || preg_match('~(?:^|/)\.env(?:\.|$)|\.local\.|^(?:uploads|storage|logs|private_html)(?:/|$)~', $relative)
                || !is_array($entry) || !in_array($entry['action'] ?? null, ['add', 'replace'], true)
                || !array_key_exists('before', $entry) || !is_array($entry['after'] ?? null)) {
                throw new RuntimeException('Routing, private data or unreviewed action in file plan.');
            }
            $this->validateMetadata($entry['after'], true);
            if ($entry['after']['uid'] !== $this->uid) throw new RuntimeException('This owner cannot mutate another owner file.');
            if ($entry['action'] === 'add') {
                if ($entry['before'] !== null) throw new RuntimeException('Added file must have an absent preimage.');
            } else {
                if (!is_array($entry['before'])) throw new RuntimeException('Replaced file requires original metadata.');
                $this->validateMetadata($entry['before'], true);
                foreach (['uid', 'gid', 'mode'] as $key) {
                    if ($entry['before'][$key] !== $entry['after'][$key]) throw new RuntimeException('Existing file ownership and mode must be preserved.');
                }
            }
        }
        foreach ($p['directories'] as $relative => $entry) {
            if (!is_string($relative) || !self::relative($relative) || !is_array($entry)
                || !array_key_exists('before', $entry) || !is_array($entry['after'] ?? null)) throw new RuntimeException('Invalid directory plan.');
            $this->validateMetadata($entry['after'], false);
            if ($entry['before'] !== null) {
                if (!is_array($entry['before'])) throw new RuntimeException('Invalid original directory metadata.');
                $this->validateMetadata($entry['before'], false);
                if (!self::same($entry['before'], $entry['after'])) throw new RuntimeException('Existing directories must not be changed.');
            } elseif ($entry['after']['uid'] !== $this->uid) throw new RuntimeException('New directories require this owner.');
        }
        foreach ($p['files'] as $relative => $entry) {
            $parent = dirname($relative);
            while ($parent !== '.') {
                if (!isset($p['directories'][$parent])) throw new RuntimeException('Every file ancestor must be reviewed.');
                $parent = dirname($parent);
            }
        }
    }

    private function held(string $phase): void
    {
        if (($this->guard)($phase, $this->plan['operation'], $this->plan['candidate_commit'], $this->planHash) !== true) {
            throw new RuntimeException('Release maintenance guard is not held.');
        }
        self::digest($this->privateRead($this->private . '/plan.json'), $this->planHash);
    }

    private function privateRead(string $path): string
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        if (!$stat || !is_file($path) || is_link($path) || realpath($path) !== $path
            || $stat['nlink'] !== 1 || $stat['uid'] !== $this->uid || ($stat['mode'] & 07777) !== 0600) {
            throw new RuntimeException('Owner-private release material required.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Private release material unavailable.');
        return $bytes;
    }

    private function writePrivate(string $path, string $bytes): void
    {
        $old = umask(0077);
        try { $stream = @fopen($path, 'x+b'); } finally { umask($old); }
        if (!$stream) throw new RuntimeException('New private file required.');
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) {
                throw new RuntimeException('Cannot persist private release material.');
            }
        } finally { fclose($stream); }
    }

    private function marker(string $name): array
    {
        return ['version' => 1, 'event' => $name, 'operation' => $this->plan['operation'],
            'candidate_commit' => $this->plan['candidate_commit'], 'plan_sha256' => $this->planHash, 'owner_uid' => $this->uid];
    }

    private function mark(string $name, bool $reuse = false): void
    {
        $path = $this->private . '/' . $name . '.json';
        $bytes = json_encode($this->marker($name), JSON_THROW_ON_ERROR);
        if ($reuse && (file_exists($path) || is_link($path))) {
            if ($this->privateRead($path) !== $bytes) throw new RuntimeException('Release marker identity differs.');
        } else $this->writePrivate($path, $bytes);
    }

    private function requireMarker(string $name): void
    {
        if (json_decode($this->privateRead($this->private . '/' . $name . '.json'), true, 8, JSON_THROW_ON_ERROR) !== $this->marker($name)) {
            throw new RuntimeException('Release marker differs.');
        }
    }

    private function journal(string $event, ?string $relative = null): void
    {
        $path = $this->private . '/journal.jsonl';
        if (file_exists($path) || is_link($path)) $this->privateRead($path);
        $old = umask(0077);
        try { $stream = fopen($path, 'ab'); } finally { umask($old); }
        if (!$stream) throw new RuntimeException('Release journal unavailable.');
        try {
            $bytes = json_encode($this->marker($event) + ['at' => gmdate('c'), 'path' => $relative], JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) throw new RuntimeException('Release journal persistence failed.');
        } finally { fclose($stream); }
    }

    private function candidate(string $relative, array $expected): string
    {
        $path = $this->path($this->package, $relative); $metadata = self::metadata($path, true);
        if (!$metadata || $metadata['uid'] !== $this->uid || $metadata['sha256'] !== $expected['sha256']
            || $metadata['size'] !== $expected['size'] || $metadata['mtime'] !== $expected['mtime']) {
            throw new RuntimeException('Private candidate bytes or timestamp differ.');
        }
        return $path;
    }

    /** Only complete, wholly absent, single-owner trees may become visible together. */
    private function newTrees(): array
    {
        $trees = [];
        foreach ($this->plan['directories'] as $relative => $entry) {
            $parent = dirname($relative);
            if ($entry['before'] !== null || (isset($this->plan['directories'][$parent])
                && $this->plan['directories'][$parent]['before'] === null)) continue;
            $prefix = $relative . '/'; $tree = ['root' => $relative, 'directories' => [], 'files' => []];
            foreach ($this->plan['directories'] as $path => $value) {
                if ($path === $relative || str_starts_with($path, $prefix)) $tree['directories'][$path] = $value;
            }
            foreach ($this->plan['files'] as $path => $value) {
                if (str_starts_with($path, $prefix)) $tree['files'][$path] = $value;
            }
            if (!$tree['files']) continue;
            foreach (array_merge($tree['directories'], $tree['files']) as $value) {
                if ($value['before'] !== null || $value['after']['uid'] !== $this->uid) continue 2;
            }
            foreach ($this->manifestFiles as $path => $hash) {
                if (str_starts_with($path, $prefix) && !isset($tree['files'][$path])) continue 2;
            }
            $trees[$relative] = $tree;
        }
        return $trees;
    }

    private function treePath(string $directory, array $tree, string $relative): string
    {
        return $relative === $tree['root'] ? $directory : $this->path($directory, substr($relative, strlen($tree['root']) + 1));
    }

    /** Verify exact metadata and directory membership, including unexpected files. */
    private function inspectTree(string $directory, array $tree, bool $allowMissing = false): bool
    {
        $complete = true;
        foreach (['directories' => false, 'files' => true] as $kind => $file) {
            foreach ($tree[$kind] as $relative => $entry) {
                $actual = self::metadata($this->treePath($directory, $tree, $relative), $file);
                if ($actual === null && $allowMissing) { $complete = false; continue; }
                if (!self::same($actual, $entry['after'])) throw new RuntimeException('New release tree bytes or metadata differ.');
            }
        }
        if (self::metadata($directory, false) === null) return false;
        $pending = [$tree['root']];
        while ($pending) {
            $relative = array_pop($pending); $path = $this->treePath($directory, $tree, $relative);
            $names = scandir($path);
            if ($names === false) throw new RuntimeException('Cannot inspect complete release tree.');
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') continue;
                $child = $relative . '/' . $name;
                if (isset($tree['directories'][$child])) $pending[] = $child;
                elseif (!isset($tree['files'][$child])) throw new RuntimeException('Unexpected entry in new release tree; preserve it.');
            }
        }
        return $complete;
    }

    private function syncDirectory(string $directory): void
    {
        $stream = fopen($directory, 'r');
        try { if (!$stream || !fsync($stream)) throw new RuntimeException('Cannot persist release directory.'); }
        finally { if (is_resource($stream)) fclose($stream); }
    }

    /** Stage only reviewed bytes; the sole visible mutation is the guarded rename. */
    private function installTree(array $tree): void
    {
        $relative = $tree['root']; $target = $this->path($this->root, $relative);
        if (self::metadata($target, false) !== null) throw new RuntimeException('New release tree appeared before staging.');
        $temporary = $this->private . '/pending-tree-' . bin2hex(random_bytes(16));
        $directories = $tree['directories'];
        uksort($directories, static fn($a, $b) => substr_count($a, '/') <=> substr_count($b, '/'));
        foreach ($directories as $path => $entry) {
            $next = $this->treePath($temporary, $tree, $path);
            if (!mkdir($next, 0700) || !chgrp($next, $entry['after']['gid']) || !chmod($next, $entry['after']['mode'])) {
                throw new RuntimeException('Cannot stage reviewed new directory metadata.');
            }
        }
        foreach ($tree['files'] as $path => $entry) {
            $source = $this->candidate($path, $entry['after']); $next = $this->treePath($temporary, $tree, $path);
            $this->writePrivate($next, (string)file_get_contents($source));
            if (!chgrp($next, $entry['after']['gid']) || !chmod($next, $entry['after']['mode']) || !touch($next, $entry['after']['mtime'])) {
                throw new RuntimeException('Cannot stage reviewed tree file metadata.');
            }
        }
        $this->inspectTree($temporary, $tree);
        foreach (array_reverse(array_keys($directories)) as $path) $this->syncDirectory($this->treePath($temporary, $tree, $path));
        $this->syncDirectory($this->private);
        $this->journal('directory_tree_create_start', $relative);
        $this->held('directory-rename');
        // No source or metadata assumptions survive the external guard wait.
        $this->inspectTree($temporary, $tree);
        if (self::metadata($target, false) !== null || !rename($temporary, $target)) throw new RuntimeException('New release tree changed before atomic installation.');
        $this->syncDirectory(dirname($target)); $this->syncDirectory($this->private);
        $this->inspectTree($target, $tree);
        $this->journal('directory_tree_create_complete', $relative);
        // Interrupted private staging is retained for inspection, never recursively removed.
    }

    /** Retain exact added bytes privately; do not recursively delete a public tree. */
    private function removeTree(array $tree): void
    {
        $relative = $tree['root']; $target = $this->path($this->root, $relative);
        $saved = $this->private . '/recovered-tree-' . hash('sha256', $relative);
        if (file_exists($saved) || is_link($saved)) throw new RuntimeException('Recovery tree destination already exists.');
        $this->journal('directory_tree_remove_start', $relative);
        $this->held('directory-tree-recovery');
        $this->inspectTree($target, $tree);
        if (!rename($target, $saved)) throw new RuntimeException('Cannot atomically remove reviewed new tree.');
        $this->syncDirectory(dirname($target)); $this->syncDirectory($this->private);
        $this->inspectTree($saved, $tree);
        $this->journal('directory_tree_remove_complete', $relative);
    }

    /** Capture every old byte before any live-file mutation. Preparation never changes the root. */
    public function prepare(): array
    {
        $this->held('file-backup');
        foreach (['apply-requested', 'recovery-requested'] as $marker) {
            if (file_exists($this->private . '/' . $marker . '.json')) throw new RuntimeException('Existing operation must be recovered explicitly.');
        }
        foreach ($this->plan['directories'] as $relative => $entry) {
            if (!self::same(self::metadata($this->path($this->root, $relative), false), $entry['before'])) throw new RuntimeException('Directory preimage differs.');
        }
        foreach ($this->plan['files'] as $relative => $entry) {
            $this->held('file-backup'); $target = $this->path($this->root, $relative);
            if (!self::same(self::metadata($target, true), $entry['before'])) throw new RuntimeException('File preimage differs.');
            $this->candidate($relative, $entry['after']);
            if ($entry['before'] === null) continue;
            $backup = $this->private . '/backup-' . hash('sha256', $relative);
            if (!file_exists($backup) && !is_link($backup)) $this->writePrivate($backup, (string) file_get_contents($target));
            self::digest($this->privateRead($backup), $entry['before']['sha256']);
            if (!self::same(self::metadata($target, true), $entry['before'])) throw new RuntimeException('File changed during backup.');
        }
        $this->mark('prepared', true);
        return $this->marker('prepared') + ['files' => count($this->plan['files'])];
    }

    /** Build a replacement privately, persist it, then rename on the same filesystem. */
    private function replace(string $relative, ?array $expected, array $next, string $bytes): void
    {
        self::digest($bytes, $next['sha256']);
        if (strlen($bytes) !== $next['size']) throw new RuntimeException('Replacement size differs.');
        $target = $this->path($this->root, $relative);
        if (!self::same(self::metadata($target, true), $expected)) throw new RuntimeException('Concurrent file change; replacement refused.');
        $temporary = $this->private . '/pending-' . bin2hex(random_bytes(16));
        $this->writePrivate($temporary, $bytes);
        try {
            if (!chgrp($temporary, $next['gid']) || !chmod($temporary, $next['mode']) || !touch($temporary, $next['mtime'])) {
                throw new RuntimeException('Cannot preserve replacement metadata.');
            }
            if (!self::same(self::metadata($temporary, true), $next)) throw new RuntimeException('Prepared replacement metadata differs.');
            $this->held('file-rename');
            if (!self::same(self::metadata($target, true), $expected) || !rename($temporary, $target)) throw new RuntimeException('File changed before atomic replacement.');
            if (!self::same(self::metadata($target, true), $next)) throw new RuntimeException('Installed bytes or metadata differ.');
        } finally {
            // Only this invocation's new private temporary file; never a target/backup or recursive cleanup.
            if (is_file($temporary) && !is_link($temporary) && fileowner($temporary) === $this->uid) unlink($temporary);
        }
    }

    public function apply(): array
    {
        $this->held('file-apply'); $this->requireMarker('prepared');
        if (file_exists($this->private . '/recovery-requested.json')) throw new RuntimeException('Recovery direction forbids apply.');
        if (file_exists($this->private . '/apply-requested.json')) throw new RuntimeException('Existing apply requires explicit outcome inspection or recovery.');
        $this->verify(false);
        foreach ($this->plan['files'] as $relative => $entry) {
            $this->candidate($relative, $entry['after']);
            if ($entry['before'] !== null) self::digest($this->privateRead($this->private . '/backup-' . hash('sha256', $relative)), $entry['before']['sha256']);
        }
        $this->mark('apply-requested'); // No automatic re-execution, including after process loss.
        $trees = $this->newTrees(); $treeDirectories = []; $treeFiles = [];
        foreach ($trees as $tree) {
            $treeDirectories += $tree['directories']; $treeFiles += $tree['files'];
        }
        $directories = $this->plan['directories']; uksort($directories, static fn($a, $b) => substr_count($a, '/') <=> substr_count($b, '/'));
        foreach ($directories as $relative => $entry) {
            if (isset($treeDirectories[$relative]) && !isset($trees[$relative])) continue;
            $this->held('directory-apply'); $path = $this->path($this->root, $relative);
            if (!self::same(self::metadata($path, false), $entry['before'])) throw new RuntimeException('Directory changed before apply.');
            if ($entry['before'] !== null) continue;
            if (isset($trees[$relative])) { $this->installTree($trees[$relative]); continue; }
            $this->journal('directory_create_start', $relative);
            $temporary = $this->private . '/directory-' . bin2hex(random_bytes(16));
            if (!mkdir($temporary, 0700)) throw new RuntimeException('Cannot prepare private directory.');
            try {
                if (!chgrp($temporary, $entry['after']['gid']) || !chmod($temporary, $entry['after']['mode'])
                    || !self::same(self::metadata($temporary, false), $entry['after'])) throw new RuntimeException('Cannot prepare reviewed directory metadata.');
                $this->held('directory-rename');
                if (self::metadata($path, false) !== null || !rename($temporary, $path)) throw new RuntimeException('Directory appeared before atomic creation.');
            } finally {
                if (is_dir($temporary) && !is_link($temporary) && fileowner($temporary) === $this->uid && count(scandir($temporary)) === 2) rmdir($temporary);
            }
            $this->journal('directory_create_complete', $relative);
        }
        foreach ($this->plan['files'] as $relative => $entry) {
            if (isset($treeFiles[$relative])) continue;
            $this->held('file-apply'); $source = $this->candidate($relative, $entry['after']);
            $this->journal('file_replace_start', $relative);
            $this->replace($relative, $entry['before'], $entry['after'], (string) file_get_contents($source));
            $this->journal('file_replace_complete', $relative);
        }
        $this->verify(true); $this->mark('applied');
        return $this->marker('applied') + ['files' => count($this->plan['files'])];
    }

    /** Explicit resumable recovery; caller restores the matching DB and invalidates removed PHP first. */
    public function recover(): array
    {
        $this->held('file-recovery'); $this->requireMarker('prepared'); $this->mark('recovery-requested', true);
        // Validate all recognized outcomes and backup bytes before changing any file.
        foreach ($this->plan['directories'] as $relative => $entry) {
            $current = self::metadata($this->path($this->root, $relative), false);
            if (!self::same($current, $entry['before']) && !self::same($current, $entry['after'])) throw new RuntimeException('Concurrent directory change blocks recovery.');
        }
        foreach ($this->plan['files'] as $relative => $entry) {
            $current = self::metadata($this->path($this->root, $relative), true);
            if (!self::same($current, $entry['before']) && !self::same($current, $entry['after'])) throw new RuntimeException('Concurrent file change blocks recovery.');
            if ($entry['before'] !== null) self::digest($this->privateRead($this->private . '/backup-' . hash('sha256', $relative)), $entry['before']['sha256']);
        }
        $trees = $this->newTrees(); $completeTrees = []; $treeFiles = []; $treeDirectories = [];
        // Check every tree, including extra entries, before changing any original file.
        foreach ($trees as $relative => $tree) {
            $path = $this->path($this->root, $relative);
            if ($this->inspectTree($path, $tree, true)) $completeTrees[] = $relative;
            elseif (self::metadata($path, false) !== null) continue; // Earlier per-file partial installation.
            $treeFiles += $tree['files']; $treeDirectories += $tree['directories'];
        }
        foreach ($completeTrees as $relative) $this->removeTree($trees[$relative]);
        foreach ($this->plan['files'] as $relative => $entry) {
            if (isset($treeFiles[$relative])) continue;
            $this->held('file-recovery'); $target = $this->path($this->root, $relative);
            if (self::same(self::metadata($target, true), $entry['before'])) continue;
            $this->journal('file_restore_start', $relative);
            if ($entry['before'] === null) {
                if (!self::same(self::metadata($target, true), $entry['after']) || !unlink($target)) throw new RuntimeException('Added file changed before removal.');
            } else $this->replace($relative, $entry['after'], $entry['before'], $this->privateRead($this->private . '/backup-' . hash('sha256', $relative)));
            $this->journal('file_restore_complete', $relative);
        }
        $directories = $this->plan['directories']; uksort($directories, static fn($a, $b) => substr_count($b, '/') <=> substr_count($a, '/'));
        foreach ($directories as $relative => $entry) {
            if (isset($treeDirectories[$relative])) continue;
            if ($entry['before'] !== null) continue;
            $this->held('directory-recovery'); $path = $this->path($this->root, $relative);
            $current = self::metadata($path, false); if ($current === null) continue;
            if (!self::same($current, $entry['after']) || count(scandir($path)) !== 2) throw new RuntimeException('New directory changed; removal refused.');
            $this->journal('directory_remove_start', $relative);
            if (!rmdir($path)) throw new RuntimeException('Cannot remove reviewed empty directory.');
            $this->journal('directory_remove_complete', $relative);
        }
        $this->verify(false); $this->mark('recovered', true);
        return $this->marker('recovered') + ['files' => count($this->plan['files'])];
    }

    public function verify(bool $applied): void
    {
        $this->held($applied ? 'verify-applied' : 'verify-original');
        foreach ($this->plan['files'] as $relative => $entry) {
            if (!self::same(self::metadata($this->path($this->root, $relative), true), $entry[$applied ? 'after' : 'before'])) {
                throw new RuntimeException('File verification differs.');
            }
        }
        foreach ($this->plan['directories'] as $relative => $entry) {
            if (!self::same(self::metadata($this->path($this->root, $relative), false), $entry[$applied ? 'after' : 'before'])) {
                throw new RuntimeException('Directory verification differs.');
            }
        }
    }
}
