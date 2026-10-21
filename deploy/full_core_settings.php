<?php
/** Preserve legacy connection settings before a separately approved full-Core activation. */
declare(strict_types=1);

const FULL_CORE_CONNECTION_SETTINGS = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
    'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_SECURE', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME'];

function fullCoreConnectionSettings(array $values): array
{
    if (array_diff(array_keys($values), FULL_CORE_CONNECTION_SETTINGS)
        || array_diff(FULL_CORE_CONNECTION_SETTINGS, array_keys($values))) {
        throw new RuntimeException('Exactly the supported connection settings are required.');
    }
    $ordered = [];
    foreach (FULL_CORE_CONNECTION_SETTINGS as $name) {
        $value = $values[$name];
        if (($name === 'SMTP_PORT' && (!is_int($value) || $value < 1 || $value > 65535))
            || ($name !== 'SMTP_PORT' && !is_string($value))) {
            throw new RuntimeException('Connection setting has an invalid type.');
        }
        if (str_starts_with($name, 'DB_') && trim((string) $value) === '') {
            throw new RuntimeException('Database connection settings are incomplete.');
        }
        if (is_string($value) && str_contains($value, "\0")) throw new RuntimeException('Invalid connection setting.');
        $ordered[$name] = $value;
    }
    return $ordered;
}

function fullCorePrivateSettingsSource(array $settings): string
{
    $settings = fullCoreConnectionSettings($settings);
    $source = "<?php\n// Private connection settings. Keep outside every public webroot.\n";
    foreach ($settings as $name => $value) {
        $literal = var_export($value, true);
        $source .= "if (defined('{$name}') && constant('{$name}') !== {$literal}) throw new RuntimeException('Conflicting private connection setting.');\n";
        $source .= "if (!defined('{$name}')) define('{$name}', {$literal});\n";
    }
    return $source;
}

function fullCorePrivatePath(string $path): string
{
    $resolved = realpath($path);
    if ($resolved === false || is_link($path)) throw new RuntimeException('Private path must exist without a link.');
    return str_replace('\\', '/', $resolved);
}

function fullCorePathWithin(string $path, string $root): bool
{
    if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }
    return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
}

function fullCorePrivateDirectory(string $path, ?int $group): void
{
    if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new RuntimeException('Invalid private directory.');
    if (!is_dir($path) && !mkdir($path, 0700)) throw new RuntimeException('Cannot create private directory.');
    if (PHP_OS_FAMILY !== 'Windows') {
        if ($group !== null && !chgrp($path, $group)) throw new RuntimeException('Cannot preserve application group access.');
        if (!chmod($path, $group === null ? 0700 : 0750)) throw new RuntimeException('Cannot protect private directory.');
    }
}

function fullCorePrivateFile(string $path, string $bytes, ?int $group): void
{
    if (is_link($path)) throw new RuntimeException('Linked private files are forbidden.');
    if (file_exists($path)) {
        $stat = stat($path);
        if (!$stat || $stat['nlink'] !== 1
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            throw new RuntimeException('Private file ownership is not exclusive.');
        }
        if (!is_file($path) || !hash_equals(hash('sha256', $bytes), hash_file('sha256', $path))) {
            throw new RuntimeException('Existing private file differs; it was not overwritten.');
        }
    } else {
        $oldMask = umask(0077);
        try { $handle = fopen($path, 'x'); } finally { umask($oldMask); }
        if (!$handle) throw new RuntimeException('Cannot reserve private file.');
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!$written) throw new RuntimeException('Private file write did not complete.');
                $offset += $written;
            }
            if (!fflush($handle)) throw new RuntimeException('Private file flush failed.');
        } finally { fclose($handle); }
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        if ($group !== null && !chgrp($path, $group)) throw new RuntimeException('Cannot set private file group.');
        if (!chmod($path, $group === null ? 0600 : 0640)) throw new RuntimeException('Cannot protect private file.');
    }
}

function fullCoreVerifyPreparedConnection(string $candidate, string $settingsFile, array $settings): array
{
    $settings = fullCoreConnectionSettings($settings);
    $proofKey = bin2hex(random_bytes(32));
    $expected = hash_hmac('sha256', json_encode($settings, JSON_THROW_ON_ERROR), $proofKey);
    $code = <<<'PHP'
    try {
        ini_set('display_errors', '0');
        $proof = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
        require getenv('COREFLUX_SETTINGS_CANDIDATE') . '/core/db.php';
        $settings = [];
        foreach ($proof['names'] as $name) {
            if (!defined($name)) throw new RuntimeException('Missing setting');
            $settings[$name] = constant($name);
        }
        if (!hash_equals($proof['signature'], hash_hmac('sha256', json_encode($settings, JSON_THROW_ON_ERROR), $proof['key']))) {
            throw new RuntimeException('Connection settings differ');
        }
        $db = getDB();
        if (!$db || $db->query('SELECT DATABASE()')->fetchColumn() !== DB_NAME) throw new RuntimeException('Database mismatch');
        $db->exec('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        $ok = (int) $db->query('SELECT 1')->fetchColumn() === 1;
        $db->rollBack();
        if (!$ok || COREFLUX_STAGING || APP_URL !== 'https://www.corefluxapp.com') throw new RuntimeException('Bootstrap mismatch');
        echo json_encode(['settings_preserved'=>true, 'database_connected'=>true,
            'read_only_probe'=>true, 'production_mode'=>true]);
    } catch (Throwable $error) {
        // Never forward PDO/configuration diagnostics or credential values.
        echo json_encode(['error'=>'Candidate connection verification failed.']);
        exit(1);
    }
    PHP;
    $command = [PHP_BINARY];
    if (PHP_OS_FAMILY === 'Windows') {
        $command = array_merge($command, ['-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_mysql']);
    }
    $command = array_merge($command, ['-r', $code]);
    $environment = getenv();
    $environment['COREFLUX_ENV'] = 'production';
    $environment['COREFLUX_DB_CONFIG_PATH'] = $settingsFile;
    $environment['COREFLUX_SETTINGS_CANDIDATE'] = $candidate;
    unset($environment['COREFLUX_DISABLE_DATABASE']);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $candidate, $environment);
    if (!is_resource($process)) throw new RuntimeException('Cannot start candidate verification.');
    fwrite($pipes[0], json_encode(['key' => $proofKey, 'signature' => $expected,
        'names' => FULL_CORE_CONNECTION_SETTINGS], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    // Consume but never print private bootstrap diagnostics.
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    $result = json_decode($output, true);
    if ($exit !== 0 || !is_array($result) || ($result['settings_preserved'] ?? false) !== true
        || ($result['database_connected'] ?? false) !== true || ($result['read_only_probe'] ?? false) !== true
        || ($result['production_mode'] ?? false) !== true) {
        throw new RuntimeException('Candidate connection verification failed; live configuration is unchanged.');
    }
    return $result;
}

function fullCorePrepareSettings(string $privateParent, string $webroot, string $package,
    string $commit, array $settings, ?int $group = null): array
{
    $settings = fullCoreConnectionSettings($settings);
    if (!preg_match('/^[a-f0-9]{40}$/D', $commit)) throw new RuntimeException('Exact candidate revision required.');
    $parent = fullCorePrivatePath($privateParent);
    $web = fullCorePrivatePath($webroot);
    $source = fullCorePrivatePath($package);
    if (!is_dir($parent) || !is_dir($web) || !is_dir($source)
        || fullCorePathWithin($parent, $web) || fullCorePathWithin($web, $parent)
        || fullCorePathWithin($parent, $source) || fullCorePathWithin($source, $parent)
        || fullCorePathWithin($source, $web) || fullCorePathWithin($web, $source)) {
        throw new RuntimeException('Private settings, live webroot and candidate must be separate.');
    }
    foreach (['core/config.php', 'core/db.php'] as $file) {
        if (is_link($source . '/' . $file) || !is_file($source . '/' . $file)
            || !fullCorePathWithin(fullCorePrivatePath($source . '/' . $file), $source)) {
            throw new RuntimeException('Candidate bootstrap is unavailable.');
        }
    }
    $directory = $parent . '/core-full-release-' . $commit;
    $created = !file_exists($directory);
    if (!$created) {
        $allowed = ['settings.php', 'candidate', 'candidate/core', 'candidate/core/config.php',
            'candidate/core/db.php', 'db.local.php.bridge', 'prepared.json'];
        if (is_link($directory) || !is_dir($directory)
            || (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid())) {
            throw new RuntimeException('Existing private preparation is not owned by this operator.');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $relative = substr(str_replace('\\', '/', $entry->getPathname()), strlen($directory) + 1);
            if ($entry->isLink() || !in_array($relative, $allowed, true)) {
                throw new RuntimeException('Unexpected content in private preparation.');
            }
        }
    }
    fullCorePrivateDirectory($directory, $group);
    if (!fullCorePathWithin(fullCorePrivatePath($directory), $parent)) throw new RuntimeException('Private directory escaped its parent.');
    $settingsFile = $directory . '/settings.php';
    fullCorePrivateFile($settingsFile, fullCorePrivateSettingsSource($settings), $group);
    fullCorePrivateDirectory($directory . '/candidate', $group);
    fullCorePrivateDirectory($directory . '/candidate/core', $group);
    foreach (['core/config.php', 'core/db.php'] as $file) {
        fullCorePrivateFile($directory . '/candidate/' . $file, (string) file_get_contents($source . '/' . $file), $group);
    }
    $bridge = "<?php\n// Activation bridge; contains no connection secrets.\nrequire_once " . var_export($settingsFile, true) . ";\n";
    fullCorePrivateFile($directory . '/db.local.php.bridge', $bridge, $group);
    $verified = fullCoreVerifyPreparedConnection($directory . '/candidate', $settingsFile, $settings);
    $receipt = ['candidate_commit' => $commit, 'private_directory' => $directory,
        'private_settings' => $settingsFile, 'candidate_config_sha256' => hash_file('sha256', $source . '/core/config.php'),
        'candidate_db_sha256' => hash_file('sha256', $source . '/core/db.php'),
        'cli_verification' => $verified, 'web_verification' => false, 'activation_performed' => false];
    fullCorePrivateFile($directory . '/prepared.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", $group);
    return $receipt + ['created' => $created];
}
