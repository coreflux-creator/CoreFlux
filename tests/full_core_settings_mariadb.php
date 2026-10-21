<?php
/** Exercise private preparation against a real disposable read-only MariaDB account. */
declare(strict_types=1);
$ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('CI') === 'true';
$local = getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE') === '1'
    && in_array('--confirm-disposable-fixture', $argv, true)
    && (string) getenv('COREFLUX_DELIVERY_TEST_PASSWORD') !== '';
if (!$ci && !$local) throw new RuntimeException('Explicit disposable fixture configuration required.');
require_once __DIR__ . '/../deploy/full_core_settings.php';
$rootPassword = $ci ? 'root' : (string) getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $rootPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$suffix = gmdate('YmdHis') . '_' . getmypid();
$database = 'coreflux_settings_fixture_' . $suffix;
$user = 'cfsettings_' . getmypid() . '_' . bin2hex(random_bytes(3));
$secret = "Fixture'\\\r\n\"" . bin2hex(random_bytes(12));
$temporary = str_replace('\\', '/', sys_get_temp_dir()) . '/core-settings-' . $suffix;
$createdDatabase = false; $createdUsers = []; $passed = 0;
$check = static function (bool $ok, string $message) use (&$passed): void {
    if (!$ok) throw new RuntimeException($message);
    $passed++; echo "PASS {$message}\n";
};
$fails = static function (callable $run): ?Throwable {
    try { $run(); return null; } catch (Throwable $error) { return $error; }
};
try {
    $pdo->exec('CREATE DATABASE ' . $database); $createdDatabase = true;
    $pdo->exec('CREATE TABLE ' . $database . '.unchanged (id INT PRIMARY KEY, value_text VARCHAR(50))');
    $pdo->exec("INSERT INTO {$database}.unchanged VALUES (1,'Synthetic balance marker'),(2,'Unchanged source')");
    foreach (['localhost', '127.0.0.1'] as $host) {
        $account = $pdo->quote($user) . '@' . $pdo->quote($host);
        $pdo->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $pdo->quote($secret));
        $createdUsers[] = $account;
        $pdo->exec('GRANT SELECT ON ' . $database . '.* TO ' . $account);
    }
    mkdir($temporary, 0700); mkdir($temporary . '/private', 0700); mkdir($temporary . '/live', 0700);
    file_put_contents($temporary . '/live/unchanged.txt', 'Existing application');
    $before = $pdo->query('SELECT * FROM ' . $database . '.unchanged ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $settings = ['DB_HOST' => '127.0.0.1', 'DB_NAME' => $database, 'DB_USER' => $user, 'DB_PASS' => $secret,
        'SMTP_HOST' => 'mail.synthetic.invalid', 'SMTP_PORT' => 587, 'SMTP_USER' => 'synthetic@example.invalid',
        'SMTP_PASS' => "Synthetic mail '\\\r\n\" $ value", 'SMTP_SECURE' => 'tls',
        'SMTP_FROM_EMAIL' => 'no-reply@example.invalid', 'SMTP_FROM_NAME' => 'Synthetic “Core”'];
    $source = dirname(__DIR__);
    $group = function_exists('posix_getegid') ? posix_getegid() : null;
    $prepare = static fn(string $revision, array $values): array => fullCorePrepareSettings(
        $temporary . '/private', $temporary . '/live', $source, $revision, $values, $group);
    $revision = str_repeat('a', 40);
    $receipt = $prepare($revision, $settings);
    $check($receipt['created'] && $receipt['cli_verification']['settings_preserved'], 'special characters survive private settings and the actual candidate bootstrap');
    $check($receipt['cli_verification']['database_connected'] && $receipt['cli_verification']['read_only_probe'], 'candidate connects through the disposable SELECT-only account');
    $check($receipt['cli_verification']['production_mode'], 'candidate is verified in full-Core production mode');
    $check(!$receipt['activation_performed'] && !$receipt['web_verification'], 'preparation cannot claim runtime or web activation');
    $check(!is_file($temporary . '/live/core/db.local.php') && file_get_contents($temporary . '/live/unchanged.txt') === 'Existing application', 'live tree is unchanged and activation bridge is not installed');
    $check($pdo->query('SELECT * FROM ' . $database . '.unchanged ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $before, 'database source rows remain unchanged');
    $check(!str_contains(json_encode($receipt), $secret) && !str_contains(json_encode($receipt), $settings['SMTP_PASS']), 'receipt contains no connection secrets');
    $check(!str_contains(file_get_contents($receipt['private_directory'] . '/db.local.php.bridge'), $secret), 'activation bridge contains only a private path');
    $settingsHash = hash_file('sha256', $receipt['private_settings']);
    $savedReceipt = file_get_contents($receipt['private_directory'] . '/prepared.json');
    $again = $prepare($revision, $settings);
    $check(!$again['created'] && file_get_contents($again['private_directory'] . '/prepared.json') === $savedReceipt, 'same preparation replays without replacing the receipt');
    $changed = $settings; $changed['SMTP_PASS'] .= '-changed';
    $check($fails(fn() => $prepare($revision, $changed)) !== null && hash_file('sha256', $receipt['private_settings']) === $settingsHash, 'changed settings cannot overwrite a prepared release');
    $bad = $settings; $bad['DB_PASS'] = 'synthetic-wrong-secret';
    $error = $fails(fn() => $prepare(str_repeat('b', 40), $bad));
    $check($error !== null && !str_contains($error->getMessage(), $bad['DB_PASS']) && !str_contains($error->getMessage(), $user), 'failed database authentication reports no credentials');
    $check(!file_exists($temporary . '/private/core-full-release-' . str_repeat('b', 40) . '/prepared.json'), 'failed bootstrap creates no success receipt');
    $check($fails(fn() => $prepare('not-a-revision', $settings)) !== null, 'preparation requires an exact revision');
    $extra = $settings; $extra['DATA_KEY'] = 'synthetic-do-not-copy';
    $check($fails(fn() => fullCorePrivateSettingsSource($extra)) !== null, 'encryption and provider keys cannot be swept into the connection snapshot');
    $invalid = $settings; $invalid['SMTP_PORT'] = '587';
    $check($fails(fn() => fullCorePrivateSettingsSource($invalid)) !== null, 'numeric setting types are preserved strictly');
    mkdir($temporary . '/live/private', 0700);
    $check($fails(fn() => fullCorePrepareSettings($temporary . '/live/private', $temporary . '/live', $source, str_repeat('c', 40), $settings)) !== null, 'settings inside the webroot are refused');
    $check($fails(fn() => fullCorePrepareSettings($source, $temporary . '/live', $source, str_repeat('c', 40), $settings)) !== null, 'settings cannot overlap the source package');
    $check($fails(fn() => fullCorePrepareSettings($temporary . '/private', $source, $source, str_repeat('c', 40), $settings)) !== null, 'candidate verification cannot run from the live tree');
    file_put_contents($receipt['private_directory'] . '/unrecognized.txt', 'Existing unrelated file');
    $check($fails(fn() => $prepare($revision, $settings)) !== null, 'unexpected pre-existing content is preserved and blocks reuse');
    unlink($receipt['private_directory'] . '/unrecognized.txt');
    if (PHP_OS_FAMILY !== 'Windows') {
        $check((fileperms($receipt['private_settings']) & 0777) === 0640 && (fileperms($receipt['private_directory']) & 0777) === 0750, 'private files and directories exclude world access');
        symlink($temporary . '/private', $temporary . '/linked-private');
        $check($fails(fn() => fullCorePrepareSettings($temporary . '/linked-private', $temporary . '/live', $source, str_repeat('c', 40), $settings)) !== null, 'linked private root is refused');
        $linked = $temporary . '/private/core-full-release-' . str_repeat('c', 40);
        symlink($receipt['private_directory'], $linked);
        $check($fails(fn() => $prepare(str_repeat('c', 40), $settings)) !== null, 'linked prepared directory is refused');
        unlink($linked);
    }
    echo "{$passed} private settings preparation checks passed.\n";
} finally {
    foreach ($createdUsers as $account) $pdo->exec('DROP USER ' . $account);
    if ($createdDatabase && preg_match('/^coreflux_settings_fixture_[0-9]+_[0-9]+$/D', $database)) $pdo->exec('DROP DATABASE ' . $database);
    // The synthetic settings directory stays available for inspection; it has no live credentials.
}
