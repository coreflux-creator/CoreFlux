<?php
/** Guarded preflight and code-only upgrade for populated standalone CoreAccounting. */
declare(strict_types=1);

require_once __DIR__ . '/install_coreaccounting_clean_app.php';

function coreAccountingUpgradeOptions(array $argv): array
{
    $names = ['old-package', 'old-commit', 'old-manifest-sha256', 'package',
        'commit', 'manifest-sha256', 'webroot', 'private-runtime', 'database',
        'origin', 'db-config', 'mail-log'];
    $activationNames = ['backup-root', 'webroot-sha256', 'private-sha256'];
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (in_array($arg, ['--inspect', '--confirm-code-only-upgrade'], true)
            && !isset($options['mode'])) {
            $options['mode'] = substr($arg, 2);
            continue;
        }
        if (!preg_match('/^--([a-z0-9-]+)=(.+)$/D', $arg, $matches)
            || !in_array($matches[1], array_merge($names, $activationNames), true)
            || isset($options[$matches[1]])) {
            throw new RuntimeException('Provide each documented upgrade inspection option exactly once.');
        }
        $options[$matches[1]] = $matches[2];
    }
    if (!isset($options['mode']) || count(array_diff($names, array_keys($options))) > 0
        || ($options['mode'] === 'inspect' && count($options) !== count($names) + 1)
        || ($options['mode'] === 'confirm-code-only-upgrade'
            && (count($options) !== count($names) + count($activationNames) + 1
                || count(array_diff($activationNames, array_keys($options))) > 0))) {
        throw new RuntimeException('Upgrade requires one mode and all exact host/release identities.');
    }
    return $options;
}

function coreAccountingUpgradeDirectory(string $requested, string $label): string
{
    $path = realpath($requested);
    if (!coreAccountingInstallAbsolute($requested) || $path === false
        || is_link($requested) || !is_dir($path)) {
        throw new RuntimeException("$label must be an existing absolute directory without a link.");
    }
    return $path;
}

function coreAccountingUpgradeFile(string $requested, string $label): string
{
    $path = realpath($requested);
    if (!coreAccountingInstallAbsolute($requested) || $path === false
        || is_link($requested) || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException("$label must be an existing absolute regular file without a link.");
    }
    return $path;
}

function coreAccountingUpgradeFingerprint(string $root): string
{
    return hash('sha256', json_encode(coreAccountingInstallInventory($root),
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function coreAccountingUpgradeMigrationCounts(array $result, string $database): array
{
    $counts = $result['counts'] ?? null;
    if (!is_array($counts) || ($result['database'] ?? null) !== $database
        || ($counts['shipped'] ?? 0) < 1
        || ($counts['matched'] ?? 0) !== $counts['shipped']) {
        throw new RuntimeException('Migration audit returned an incomplete or inconsistent result.');
    }
    foreach (['pending', 'changed', 'failed', 'recorded_not_packaged'] as $name) {
        if (($counts[$name] ?? -1) !== 0) {
            throw new RuntimeException('Code-only upgrade requires zero migration differences.');
        }
    }
    return $counts;
}

function coreAccountingUpgradeAuditMigrations(string $package, string $database,
    string $dbConfig, string $origin): array
{
    $command = [PHP_BINARY, __DIR__ . '/audit_coreaccounting_migrations.php',
        '--database=' . $database, '--root=' . $package . '/public_html'];
    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $environment['COREFLUX_ENV'] = 'coreaccounting';
    $environment['COREFLUX_STANDALONE_WEBROOT'] = $package . '/public_html';
    $environment['COREFLUX_STANDALONE_DATABASE'] = $database;
    $environment['COREFLUX_ACCOUNTING_DB_CONFIG_PATH'] = $dbConfig;
    $environment['COREFLUX_PUBLIC_ORIGIN'] = $origin;
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Could not start read-only migration audit.');
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('Code-only upgrade refused by migration audit: '
            . trim((string) $output . ' ' . (string) $error));
    }
    $result = json_decode((string) $output, true);
    if (!is_array($result)) throw new RuntimeException('Migration audit did not return JSON.');
    return coreAccountingUpgradeMigrationCounts($result, $database);
}

function coreAccountingUpgradeInspect(array $options): array
{
    if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting') {
        throw new RuntimeException('Only the isolated CoreAccounting CLI may inspect a populated upgrade.');
    }
    $oldPackage = coreAccountingUpgradeDirectory($options['old-package'], 'Old package');
    $package = coreAccountingUpgradeDirectory($options['package'], 'New package');
    $webroot = coreAccountingUpgradeDirectory($options['webroot'], 'Live webroot');
    $privateRuntime = coreAccountingUpgradeDirectory($options['private-runtime'], 'Live private runtime');
    $dbConfig = coreAccountingUpgradeFile($options['db-config'], 'Private database config');
    $mailLog = coreAccountingUpgradeFile($options['mail-log'], 'Private mail log');
    $database = $options['database'];
    $origin = $options['origin'];
    $parts = parse_url($origin);
    if (basename($webroot) !== 'public_html'
        || basename($privateRuntime) !== 'private_runtime'
        || !preg_match('/^[A-Za-z0-9_]+$/D', $database)
        || !preg_match('/^[a-f0-9]{40}$/D', $options['old-commit'])
        || !preg_match('/^[a-f0-9]{64}$/D', $options['old-manifest-sha256'])
        || !preg_match('/^[a-f0-9]{40}$/D', $options['commit'])
        || !preg_match('/^[a-f0-9]{64}$/D', $options['manifest-sha256'])
        || $options['old-commit'] === $options['commit']
        || $database !== getenv('COREFLUX_STANDALONE_DATABASE')
        || $origin !== getenv('COREFLUX_PUBLIC_ORIGIN')
        || $webroot !== realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'))
        || $dbConfig !== realpath((string) getenv('COREFLUX_ACCOUNTING_DB_CONFIG_PATH'))
        || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host'])
        || array_intersect(['user', 'pass', 'port', 'path', 'query', 'fragment'], array_keys($parts))) {
        throw new RuntimeException('Exact isolated webroot, database, HTTPS origin and two trusted commits are required.');
    }
    $roots = [$oldPackage, $package, $webroot, $privateRuntime];
    foreach ($roots as $index => $root) {
        foreach ($roots as $otherIndex => $other) {
            if ($index !== $otherIndex && coreAccountingInstallWithin($root, $other)) {
                throw new RuntimeException('Release, webroot and private runtime directories must not overlap.');
            }
        }
    }
    foreach ([$dbConfig, $mailLog] as $privateFile) {
        foreach ([$oldPackage, $package, $webroot] as $root) {
            if (coreAccountingInstallWithin($privateFile, $root)) {
                throw new RuntimeException('Host configuration and mail log must be outside releases and webroot.');
            }
        }
    }
    if (pathinfo($dbConfig, PATHINFO_EXTENSION) !== 'php'
        || $mailLog === $dbConfig || !is_writable($mailLog)) {
        throw new RuntimeException('Private database config and writable mail log must be separate regular files.');
    }

    $old = coreAccountingInstallVerifiedPackage($oldPackage,
        $options['old-commit'], $options['old-manifest-sha256']);
    $new = coreAccountingInstallVerifiedPackage($package,
        $options['commit'], $options['manifest-sha256']);
    $apache = coreAccountingInstallApache($oldPackage . '/public_html', $database,
        $origin, $dbConfig, $privateRuntime . '/vendor/autoload.php', $mailLog);
    coreAccountingInstallVerifyCopied($webroot, $privateRuntime, $old, hash('sha256', $apache));
    $migrationCounts = coreAccountingUpgradeAuditMigrations($package, $database, $dbConfig, $origin);
    return [
        'status' => 'ready_for_code_only_upgrade',
        'database' => $database,
        'origin' => $origin,
        'from_commit' => $options['old-commit'],
        'to_commit' => $options['commit'],
        'live_webroot_sha256' => coreAccountingUpgradeFingerprint($webroot),
        'live_private_sha256' => coreAccountingUpgradeFingerprint($privateRuntime),
        'live_apache_sha256' => hash('sha256', $apache),
        'release_public_files' => count($new['files']),
        'release_private_files' => count($new['private_files']),
        'migration_counts' => $migrationCounts,
    ];
}

function coreAccountingUpgradeActivate(array $options, array $inspection): array
{
    foreach (['webroot-sha256', 'private-sha256'] as $name) {
        if (!preg_match('/^[a-f0-9]{64}$/D', $options[$name])) {
            throw new RuntimeException('Activation requires exact inspected file fingerprints.');
        }
    }
    if (!hash_equals($inspection['live_webroot_sha256'], $options['webroot-sha256'])
        || !hash_equals($inspection['live_private_sha256'], $options['private-sha256'])) {
        throw new RuntimeException('Installed files changed since inspection; activation refused.');
    }
    $backup = coreAccountingInstallNewPath($options['backup-root'], 'Private upgrade backup');
    $webroot = realpath($options['webroot']);
    $privateRuntime = realpath($options['private-runtime']);
    $package = realpath($options['package']);
    $oldPackage = realpath($options['old-package']);
    if ($webroot === false || $privateRuntime === false || $package === false || $oldPackage === false) {
        throw new RuntimeException('Upgrade paths disappeared after inspection.');
    }
    foreach ([$webroot, $privateRuntime, $package, $oldPackage] as $root) {
        if (coreAccountingInstallWithin($backup, $root)
            || coreAccountingInstallWithin($root, $backup)) {
            throw new RuntimeException('Private upgrade backup must not overlap any installed or release tree.');
        }
    }
    $device = stat(dirname($backup))['dev'] ?? null;
    if ($device === null || $device !== (stat($webroot)['dev'] ?? null)
        || $device !== (stat($privateRuntime)['dev'] ?? null)) {
        throw new RuntimeException('Backup and installed trees must be on the same filesystem.');
    }
    if (!hash_equals($inspection['live_webroot_sha256'], coreAccountingUpgradeFingerprint($webroot))
        || !hash_equals($inspection['live_private_sha256'], coreAccountingUpgradeFingerprint($privateRuntime))) {
        throw new RuntimeException('Installed files changed immediately before activation.');
    }

    $manifest = coreAccountingInstallVerifiedPackage($package,
        $options['commit'], $options['manifest-sha256']);
    $dbConfig = coreAccountingUpgradeFile($options['db-config'], 'Private database config');
    $mailLog = coreAccountingUpgradeFile($options['mail-log'], 'Private mail log');
    $apache = coreAccountingInstallApache($package . '/public_html',
        $options['database'], $options['origin'], $dbConfig,
        $privateRuntime . '/vendor/autoload.php', $mailLog);
    if (!mkdir($backup, 0700) || !mkdir($backup . '/staged-private', 0755)
        || !mkdir($backup . '/original-public_html', 0700)
        || !mkdir($backup . '/failed-public_html', 0700)) {
        throw new RuntimeException('Could not prepare a private upgrade rollback point.');
    }
    coreAccountingInstallCopyTree($package . '/private_runtime', $backup . '/staged-private');
    $stagedHashes = coreAccountingReleaseFileHashes($backup . '/staged-private');
    $expectedPrivate = $manifest['private_files'];
    ksort($expectedPrivate, SORT_STRING);
    if ($stagedHashes !== $expectedPrivate) {
        throw new RuntimeException('Staged private runtime differs from the verified release.');
    }

    $movedPublic = [];
    $privateBackedUp = false;
    $privateActivated = false;
    $publicCopyStarted = false;
    try {
        if (!rename($privateRuntime, $backup . '/original-private_runtime')) {
            throw new RuntimeException('Could not back up the old private runtime.');
        }
        $privateBackedUp = true;
        if (!rename($backup . '/staged-private', $privateRuntime)) {
            throw new RuntimeException('Could not activate the new private runtime.');
        }
        $privateActivated = true;
        foreach (coreAccountingInstallEntries($webroot) as $name) {
            if (!rename($webroot . '/' . $name, $backup . '/original-public_html/' . $name)) {
                throw new RuntimeException('Could not back up an installed public entry.');
            }
            $movedPublic[] = $name;
        }
        $publicCopyStarted = true;
        coreAccountingInstallCopyTree($package . '/public_html', $webroot);
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
        coreAccountingInstallVerifyCopied($webroot, $privateRuntime,
            $manifest, hash('sha256', $apache));
        $receipt = array_merge($inspection, ['status' => 'code_only_upgrade_installed',
            'backup_root' => $backup,
            'new_webroot_sha256' => coreAccountingUpgradeFingerprint($webroot),
            'new_private_sha256' => coreAccountingUpgradeFingerprint($privateRuntime)]);
        $json = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($backup . '/upgrade-receipt.json', $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('Could not retain the upgrade receipt.');
        }
        return $receipt;
    } catch (Throwable $error) {
        $restored = true;
        if ($publicCopyStarted) {
            foreach (coreAccountingInstallEntries($webroot) as $name) {
                if (!rename($webroot . '/' . $name, $backup . '/failed-public_html/' . $name)) {
                    $restored = false;
                }
            }
        }
        foreach ($movedPublic as $name) {
            if (file_exists($webroot . '/' . $name) || is_link($webroot . '/' . $name)
                || !rename($backup . '/original-public_html/' . $name, $webroot . '/' . $name)) {
                $restored = false;
            }
        }
        if ($privateActivated && !rename($privateRuntime, $backup . '/failed-private_runtime')) {
            $restored = false;
        }
        if ($privateBackedUp && (file_exists($privateRuntime) || is_link($privateRuntime)
            || !rename($backup . '/original-private_runtime', $privateRuntime))) {
            $restored = false;
        }
        throw new RuntimeException($restored
            ? 'Code upgrade failed; original public and private runtimes were restored.'
            : 'Code upgrade failed and manual recovery is required from the private backup.', 0, $error);
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;

try {
    $options = coreAccountingUpgradeOptions($argv);
    $inspection = coreAccountingUpgradeInspect($options);
    $result = $options['mode'] === 'inspect'
        ? $inspection : coreAccountingUpgradeActivate($options, $inspection);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
