<?php
/** Guarded code activation for a new CoreAccounting app with an empty database. */
declare(strict_types=1);

function coreAccountingInstallOption(array $argv, string $name): string
{
    $prefix = '--' . $name . '=';
    $values = array_values(array_filter($argv,
        static fn(string $arg): bool => str_starts_with($arg, $prefix)));
    return count($values) === 1 ? substr($values[0], strlen($prefix)) : '';
}

function coreAccountingInstallAbsolute(string $path): bool
{
    return preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $path) === 1;
}

function coreAccountingInstallWithin(string $path, string $root): bool
{
    $path = str_replace('\\', '/', rtrim($path, '/\\'));
    $root = str_replace('\\', '/', rtrim($root, '/\\'));
    if (PHP_OS_FAMILY === 'Windows') {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    return $path === $root || str_starts_with($path, $root . '/');
}

function coreAccountingInstallNewPath(string $requested, string $label): string
{
    if (!coreAccountingInstallAbsolute($requested) || $requested === ''
        || in_array(basename($requested), ['', '.', '..'], true)) {
        throw new RuntimeException("$label must be a new absolute path.");
    }
    $parent = realpath(dirname($requested));
    if ($parent === false || !is_dir($parent) || is_link($requested)
        || file_exists($requested) || !is_writable($parent)) {
        throw new RuntimeException("$label parent must exist and its destination must be new.");
    }
    return $parent . DIRECTORY_SEPARATOR . basename($requested);
}

function coreAccountingInstallMailLog(string $requested, string $webroot, string $package): string
{
    $resolved = coreAccountingInstallAbsolute($requested) ? realpath($requested) : false;
    if ($resolved === false || is_link($requested) || !is_file($resolved)
        || !is_writable($resolved) || filesize($resolved) !== 0
        || coreAccountingInstallWithin($resolved, $webroot)
        || coreAccountingInstallWithin($resolved, $package)) {
        throw new RuntimeException('Mail log must be a new writable private file outside the release and webroot.');
    }
    return $resolved;
}

function coreAccountingInstallEntries(string $directory): array
{
    $entries = scandir($directory);
    if ($entries === false) throw new RuntimeException('Could not inspect an installation directory.');
    return array_values(array_diff($entries, ['.', '..']));
}

function coreAccountingInstallInventory(string $root): array
{
    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        if ($entry->isLink()) throw new RuntimeException('An existing webroot link requires manual review.');
        if ($entry->isDir()) {
            $entries['d:' . $relative] = true;
        } elseif ($entry->isFile()) {
            $hash = hash_file('sha256', $entry->getPathname());
            if ($hash === false) throw new RuntimeException('Could not hash an existing webroot file.');
            $entries['f:' . $relative] = $hash;
        } else {
            throw new RuntimeException('An unusual webroot entry requires manual review.');
        }
    }
    ksort($entries, SORT_STRING);
    return $entries;
}

function coreAccountingInstallCopyTree(string $source, string $target): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink()) throw new RuntimeException('A linked release entry cannot be installed.');
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $destination = $target . DIRECTORY_SEPARATOR . $relative;
        if ($entry->isDir()) {
            if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
                throw new RuntimeException('Could not create an installation directory.');
            }
        } elseif ($entry->isFile()) {
            if (file_exists($destination) || is_link($destination)
                || !copy($entry->getPathname(), $destination)) {
                throw new RuntimeException('Could not copy a release file.');
            }
            $permissions = fileperms($entry->getPathname());
            if ($permissions === false || !chmod($destination, $permissions & 0777)) {
                throw new RuntimeException('Could not set release file permissions.');
            }
        } else {
            throw new RuntimeException('A release entry is not a regular file or directory.');
        }
    }
}

function coreAccountingInstallVerifiedPackage(string $package, string $commit, string $manifestHash): array
{
    $verifier = __DIR__ . '/verify_coreaccounting_release.php';
    $previous = getenv('COREFLUX_STANDALONE_WEBROOT');
    putenv('COREFLUX_STANDALONE_WEBROOT=' . $package . '/public_html');
    try {
        $command = [PHP_BINARY, $verifier, '--confirm-read-only-package',
            '--root=' . $package . '/public_html',
            '--private-root=' . $package . '/private_runtime',
            '--manifest=' . $package . '/manifest.json',
            '--commit=' . $commit, '--manifest-sha256=' . $manifestHash];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start release verification.');
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('The signed release package failed verification: '
                . trim((string) $error . ' ' . (string) $output));
        }
    } finally {
        $previous === false ? putenv('COREFLUX_STANDALONE_WEBROOT')
            : putenv('COREFLUX_STANDALONE_WEBROOT=' . $previous);
    }
    $manifest = json_decode((string) file_get_contents($package . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || !is_array($manifest['files'] ?? null)
        || !is_array($manifest['private_files'] ?? null)) {
        throw new RuntimeException('The verified release manifest is incomplete.');
    }
    return $manifest;
}

function coreAccountingInstallEmptyDatabase(string $configPath, string $database): void
{
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $setting) {
        if (defined($setting)) throw new RuntimeException('Database settings were already loaded.');
    }
    require $configPath;
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $setting) {
        if (!defined($setting)) throw new RuntimeException('The private database settings are incomplete.');
    }
    if (!hash_equals($database, (string) DB_NAME)) {
        throw new RuntimeException('The private database identity differs from the requested database.');
    }
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (!hash_equals($database, (string) $pdo->query('SELECT DATABASE()')->fetchColumn())) {
            throw new RuntimeException('The database connection resolved to another database.');
        }
        $tables = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchColumn();
    } catch (PDOException $error) {
        throw new RuntimeException('Could not inspect the isolated accounting database.', 0, $error);
    }
    if ($tables !== 0) {
        throw new RuntimeException('The accounting database is not empty; clean installation refused.');
    }
}

function coreAccountingInstallApache(string $source, string $database, string $origin,
    string $dbConfig, string $vendorAutoload, string $mailLog): string
{
    $base = file_get_contents($source . '/.htaccess');
    if ($base === false || preg_match('/^\s*SetEnv\s+/mi', $base)) {
        throw new RuntimeException('The release Apache rules cannot be combined with host settings.');
    }
    $settings = [
        'COREFLUX_ENV' => 'coreaccounting',
        'COREFLUX_STANDALONE_DATABASE' => $database,
        'COREFLUX_PUBLIC_ORIGIN' => $origin,
        'COREFLUX_ACCOUNTING_DB_CONFIG_PATH' => $dbConfig,
        'COREFLUX_ACCOUNTING_PRIVATE_VENDOR_AUTOLOAD' => $vendorAutoload,
        'MAIL_DRIVER' => 'log',
        'MAIL_LOG_PATH' => $mailLog,
    ];
    foreach ($settings as $value) {
        if (preg_match('/[\s"\x00-\x1f]/', $value)) {
            throw new RuntimeException('Host paths and settings cannot contain whitespace or control characters.');
        }
    }
    $lines = ['# CoreAccounting isolated host settings'];
    foreach ($settings as $key => $value) $lines[] = 'SetEnv ' . $key . ' ' . $value;
    return implode("\n", $lines) . "\n\n" . $base;
}

function coreAccountingInstallVerifyCopied(string $webroot, string $privateRuntime,
    array $manifest, string $apacheHash): void
{
    require_once __DIR__ . '/coreaccounting_release_files.php';
    $actualPublic = coreAccountingReleaseFileHashes($webroot);
    $expectedPublic = $manifest['files'];
    unset($actualPublic['.htaccess'], $expectedPublic['.htaccess']);
    ksort($expectedPublic, SORT_STRING);
    if ($actualPublic !== $expectedPublic
        || !hash_equals($apacheHash, (string) hash_file('sha256', $webroot . '/.htaccess'))) {
        throw new RuntimeException('Installed public files differ from the verified release.');
    }
    $actualPrivate = coreAccountingReleaseFileHashes($privateRuntime);
    $expectedPrivate = $manifest['private_files'];
    ksort($expectedPrivate, SORT_STRING);
    if ($actualPrivate !== $expectedPrivate) {
        throw new RuntimeException('Installed private runtime differs from the verified release.');
    }
}

function coreAccountingInstallActivateFiles(string $publicSource, string $privateSource,
    string $webroot, string $privateRuntime, string $backupRoot,
    string $apache, array $manifest, array $summary): void
{
    if (!mkdir($privateRuntime, 0755)) throw new RuntimeException('Could not create private runtime directory.');
    coreAccountingInstallCopyTree($privateSource, $privateRuntime);
    require_once __DIR__ . '/coreaccounting_release_files.php';
    $installedPrivate = coreAccountingReleaseFileHashes($privateRuntime);
    $expectedPrivate = $manifest['private_files'];
    ksort($expectedPrivate, SORT_STRING);
    if ($installedPrivate !== $expectedPrivate) {
        throw new RuntimeException('Private runtime copy did not verify; webroot was not changed.');
    }

    if (!mkdir($backupRoot, 0700) || !mkdir($backupRoot . '/original', 0700)
        || !mkdir($backupRoot . '/failed', 0700)) {
        throw new RuntimeException('Could not prepare a private rollback point.');
    }
    $movedNames = [];
    $copyStarted = false;
    try {
        foreach (coreAccountingInstallEntries($webroot) as $name) {
            if (!rename($webroot . '/' . $name, $backupRoot . '/original/' . $name)) {
                throw new RuntimeException('Could not back up an existing webroot entry.');
            }
            $movedNames[] = $name;
        }
        $copyStarted = true;
        coreAccountingInstallCopyTree($publicSource, $webroot);
        $temporary = tempnam($webroot, '.coreaccounting-host-');
        if ($temporary === false) throw new RuntimeException('Could not stage host Apache settings.');
        try {
            if (file_put_contents($temporary, $apache, LOCK_EX) !== strlen($apache)
                || !chmod($temporary, 0644) || !rename($temporary, $webroot . '/.htaccess')) {
                throw new RuntimeException('Could not activate host Apache settings.');
            }
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
        coreAccountingInstallVerifyCopied($webroot, $privateRuntime, $manifest, hash('sha256', $apache));
        $receipt = json_encode($summary + ['private_runtime' => $privateRuntime],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($backupRoot . '/install-receipt.json', $receipt, LOCK_EX) !== strlen($receipt)) {
            throw new RuntimeException('Could not retain the install receipt.');
        }
    } catch (Throwable $error) {
        $restored = true;
        if ($copyStarted) {
            foreach (coreAccountingInstallEntries($webroot) as $name) {
                if (!rename($webroot . '/' . $name, $backupRoot . '/failed/' . $name)) $restored = false;
            }
        }
        foreach ($movedNames as $name) {
            if (file_exists($webroot . '/' . $name) || is_link($webroot . '/' . $name)
                || !rename($backupRoot . '/original/' . $name, $webroot . '/' . $name)) {
                $restored = false;
            }
        }
        throw new RuntimeException($restored
            ? 'Code activation failed; the original webroot was restored. Private evidence remains at ' . $backupRoot
            : 'Code activation failed and manual webroot recovery is required from ' . $backupRoot,
            0, $error);
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;

try {
    $inspect = in_array('--inspect', $argv, true);
    $activate = in_array('--confirm-empty-install', $argv, true);
    if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
        || $inspect === $activate) {
        throw new RuntimeException('Use isolated CoreAccounting CLI with --inspect or --confirm-empty-install.');
    }
    $requestedPackage = coreAccountingInstallOption($argv, 'package');
    $requestedWebroot = coreAccountingInstallOption($argv, 'webroot');
    $package = realpath($requestedPackage);
    $webroot = realpath($requestedWebroot);
    $configuredWebroot = realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'));
    $database = coreAccountingInstallOption($argv, 'database');
    $origin = coreAccountingInstallOption($argv, 'origin');
    $commit = coreAccountingInstallOption($argv, 'commit');
    $manifestHash = coreAccountingInstallOption($argv, 'manifest-sha256');
    $parts = parse_url($origin);
    if (!coreAccountingInstallAbsolute($requestedPackage)
        || !coreAccountingInstallAbsolute($requestedWebroot)
        || $package === false || $webroot === false || $configuredWebroot === false
        || is_link($requestedPackage) || is_link($requestedWebroot)
        || $webroot !== $configuredWebroot || basename($webroot) !== 'public_html'
        || !is_writable($webroot) || coreAccountingInstallWithin($package, $webroot)
        || coreAccountingInstallWithin($webroot, $package)
        || !preg_match('/^[A-Za-z0-9_]+$/D', $database)
        || $database !== getenv('COREFLUX_STANDALONE_DATABASE')
        || $origin !== getenv('COREFLUX_PUBLIC_ORIGIN')
        || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host'])
        || array_intersect(['user', 'pass', 'port', 'path', 'query', 'fragment'], array_keys($parts))
        || !preg_match('/^[a-f0-9]{40}$/D', $commit)
        || !preg_match('/^[a-f0-9]{64}$/D', $manifestHash)) {
        throw new RuntimeException('Exact empty-app paths, database, HTTPS origin and trusted release are required.');
    }
    $privateRuntime = coreAccountingInstallNewPath(
        coreAccountingInstallOption($argv, 'private-runtime'), 'Private runtime');
    $backupRoot = coreAccountingInstallNewPath(
        coreAccountingInstallOption($argv, 'backup-root'), 'Backup directory');
    if (basename($privateRuntime) !== 'private_runtime') {
        throw new RuntimeException('Private runtime destination must be named private_runtime.');
    }
    foreach ([$privateRuntime, $backupRoot] as $destination) {
        if (coreAccountingInstallWithin($destination, $webroot)
            || coreAccountingInstallWithin($destination, $package)
            || coreAccountingInstallWithin($webroot, $destination)
            || coreAccountingInstallWithin($package, $destination)) {
            throw new RuntimeException('Private destinations must not overlap package or webroot.');
        }
    }
    if (coreAccountingInstallWithin($privateRuntime, $backupRoot)
        || coreAccountingInstallWithin($backupRoot, $privateRuntime)) {
        throw new RuntimeException('Private runtime and backup must not overlap.');
    }
    $webrootDevice = stat($webroot)['dev'] ?? null;
    $backupDevice = stat(dirname($backupRoot))['dev'] ?? null;
    if ($webrootDevice === null || $webrootDevice !== $backupDevice) {
        throw new RuntimeException('Webroot and backup must be on the same filesystem for safe moves.');
    }
    $requestedDbConfig = coreAccountingInstallOption($argv, 'db-config');
    $dbConfig = realpath($requestedDbConfig);
    $configuredDbConfig = realpath((string) getenv('COREFLUX_ACCOUNTING_DB_CONFIG_PATH'));
    if (!coreAccountingInstallAbsolute($requestedDbConfig) || $dbConfig === false
        || $dbConfig !== $configuredDbConfig || is_link($requestedDbConfig)
        || !is_file($dbConfig) || !is_readable($dbConfig)
        || pathinfo($dbConfig, PATHINFO_EXTENSION) !== 'php'
        || coreAccountingInstallWithin($dbConfig, $webroot)
        || coreAccountingInstallWithin($dbConfig, $package)) {
        throw new RuntimeException('An exact private database configuration outside the release and webroot is required.');
    }
    $mailLog = coreAccountingInstallMailLog(coreAccountingInstallOption($argv, 'mail-log'),
        $webroot, $package);

    $manifest = coreAccountingInstallVerifiedPackage($package, $commit, $manifestHash);
    coreAccountingInstallEmptyDatabase($dbConfig, $database);
    $inventory = coreAccountingInstallInventory($webroot);
    $fingerprint = hash('sha256', json_encode($inventory, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $apache = coreAccountingInstallApache($package . '/public_html', $database, $origin,
        $dbConfig, $privateRuntime . '/vendor/autoload.php', $mailLog);
    $apacheHash = hash('sha256', $apache);
    $summary = [
        'status' => $inspect ? 'ready_for_empty_install' : 'code_installed_database_empty',
        'source_commit' => $commit,
        'database' => $database,
        'existing_tables' => 0,
        'webroot_files' => count(array_filter(array_keys($inventory),
            static fn(string $key): bool => str_starts_with($key, 'f:'))),
        'webroot_sha256' => $fingerprint,
        'release_public_files' => count($manifest['files']),
        'release_private_files' => count($manifest['private_files']),
        'apache_sha256' => $apacheHash,
    ];
    if ($inspect) {
        echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    if (!hash_equals($fingerprint, coreAccountingInstallOption($argv, 'webroot-sha256'))) {
        throw new RuntimeException('Webroot changed since inspection; activation refused.');
    }

    coreAccountingInstallActivateFiles($package . '/public_html', $package . '/private_runtime',
        $webroot, $privateRuntime, $backupRoot, $apache, $manifest, $summary);
    echo json_encode($summary + ['private_runtime' => $privateRuntime,
        'backup_root' => $backupRoot], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
