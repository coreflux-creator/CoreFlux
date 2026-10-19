<?php
/** Install only on the existing synthetic full-Core staging app. Never accepts a target override. */
declare(strict_types=1);

const INTEGRATION_STAGE_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const INTEGRATION_STAGE_DATABASE = 'muzqvdvqbx';
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !in_array($argv[1] ?? '', ['--inspect', '--apply'], true)
    || realpath(INTEGRATION_STAGE_ROOT) !== INTEGRATION_STAGE_ROOT) {
    throw new RuntimeException('Exact isolated full-Core staging CLI required.');
}
$package = realpath(dirname(__DIR__));
if (!$package || $package === INTEGRATION_STAGE_ROOT || str_starts_with($package, INTEGRATION_STAGE_ROOT . '/')) {
    throw new RuntimeException('Run the installer from its private staged package.');
}
$manifest = json_decode((string) file_get_contents($package . '/integration-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['product'] ?? '') !== 'full-core-staging'
    || !preg_match('/^[a-f0-9]{40}$/D', $manifest['commit'] ?? '')) {
    throw new RuntimeException('Invalid full-Core release manifest.');
}
foreach ($manifest['files'] as $relative => $hash) {
    if (!preg_match('~^[A-Za-z0-9_. /-]+$~D', $relative) || str_starts_with($relative, '/')
        || in_array('..', explode('/', $relative), true) || str_contains($relative, '.local.')
        || is_link($package . '/' . $relative)
        || !is_file($package . '/' . $relative)
        || !hash_equals($hash, hash_file('sha256', $package . '/' . $relative))) {
        throw new RuntimeException('Invalid release file: ' . $relative);
    }
}
require_once INTEGRATION_STAGE_ROOT . '/core/db.php';
require_once INTEGRATION_STAGE_ROOT . '/core/mail_bootstrap.php';
if (!function_exists('corefluxMigrationHashMatches')) require_once $package . '/core/migration_hash.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== INTEGRATION_STAGE_DATABASE
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=999')->fetchColumn() !== 1) {
    throw new RuntimeException('Staging database and synthetic tenant identity did not match.');
}
$mail = cf_mail_bootstrap();
if (!defined('COREFLUX_STAGING') || !COREFLUX_STAGING
    || $mail->default_driver_name() !== 'log' || $mail->driver('resend') !== null) {
    throw new RuntimeException('Staging must have log-only mail before deployment.');
}
$ledger = $pdo->query('SELECT filename, sha256 FROM _migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
$pending = [];
foreach (array_keys($manifest['files']) as $relative) {
    if (!preg_match('~^(core/migrations|modules/[^_/][^/]*/migrations)/[^/]+\.sql$~D', $relative)) continue;
    $key = str_starts_with($relative, 'core/') ? basename($relative) : $relative;
    if (!isset($ledger[$key])) $pending[] = $relative;
    elseif (!corefluxMigrationHashMatches($ledger[$key], (string) file_get_contents($package . '/' . $relative))) {
        throw new RuntimeException('Applied migration differs from release: ' . $key);
    }
}
echo json_encode(['commit' => $manifest['commit'], 'database_verified' => true,
    'mail' => 'log-only', 'pending_migrations' => $pending, 'file_count' => count($manifest['files'])],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
if ($argv[1] === '--inspect') exit(0);

$backupParent = dirname(INTEGRATION_STAGE_ROOT) . '/private_core_integration';
if (!is_dir($backupParent) && !mkdir($backupParent, 0700)) throw new RuntimeException('Cannot create private backup parent.');
if (is_link($backupParent) || realpath($backupParent) !== $backupParent) throw new RuntimeException('Invalid backup location.');
$backup = $backupParent . '/' . $manifest['commit'] . '-' . gmdate('YmdHis');
if (!mkdir($backup, 0700)) throw new RuntimeException('Cannot create new release backup.');
$ini = $backup . '/dump.cnf';
$quote = static fn(string $s): string => '"' . str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], $s) . '"';
file_put_contents($ini, "[client]\nhost=" . $quote(DB_HOST) . "\nuser=" . $quote(DB_USER) . "\npassword=" . $quote(DB_PASS) . "\n");
chmod($ini, 0600);
try {
    $dump = proc_open(['mysqldump', '--defaults-extra-file=' . $ini, '--single-transaction',
        '--skip-lock-tables', '--hex-blob', INTEGRATION_STAGE_DATABASE],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $backup . '/database.sql', 'w'],
         2 => ['file', $backup . '/dump-errors.log', 'w']], $pipes);
    if (!is_resource($dump) || proc_close($dump) !== 0 || filesize($backup . '/database.sql') < 100) {
        throw new RuntimeException('Database backup failed; runtime unchanged.');
    }
    chmod($backup . '/database.sql', 0600);
} finally {
    if (is_file($ini)) unlink($ini);
}
$changed = [];
foreach ($manifest['files'] as $relative => $hash) {
    $target = INTEGRATION_STAGE_ROOT . '/' . $relative;
    if (is_link($target)) throw new RuntimeException('Refusing linked target: ' . $relative);
    if (is_file($target) && hash_equals($hash, hash_file('sha256', $target))) continue;
    if (is_file($target)) {
        $saved = $backup . '/files/' . $relative;
        if (!is_dir(dirname($saved))) mkdir(dirname($saved), 0700, true);
        if (!copy($target, $saved)) throw new RuntimeException('Backup failed: ' . $relative);
    }
    $changed[] = $relative;
}
file_put_contents($backup . '/changed-files.json', json_encode($changed, JSON_PRETTY_PRINT));
foreach ($changed as $relative) {
    $target = INTEGRATION_STAGE_ROOT . '/' . $relative;
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
    $temporary = tempnam(dirname($target), '.core-integration-');
    if (!$temporary || !copy($package . '/' . $relative, $temporary) || !chmod($temporary, 0644)
        || !rename($temporary, $target)) throw new RuntimeException('Runtime copy failed: ' . $relative);
}
echo 'Backup: ' . $backup . "\nInstalled changed files: " . count($changed) . "\n";
// A fresh process loads the installed source with the preserved private DB settings.
$process = proc_open([PHP_BINARY, INTEGRATION_STAGE_ROOT . '/deploy/migrate_core_integration_staging.php'],
    [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, INTEGRATION_STAGE_ROOT);
if (!is_resource($process) || proc_close($process) !== 0) {
    throw new RuntimeException('Staging migration failed; backup retained at ' . $backup);
}
foreach ($manifest['files'] as $relative => $hash) {
    if (!hash_equals($hash, hash_file('sha256', INTEGRATION_STAGE_ROOT . '/' . $relative))) {
        throw new RuntimeException('Installed checksum mismatch: ' . $relative);
    }
}
file_put_contents($backup . '/installed-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
echo "Full-Core staging runtime and schema installed and verified.\n";
