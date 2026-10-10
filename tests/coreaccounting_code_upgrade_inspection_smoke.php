<?php
declare(strict_types=1);

require_once __DIR__ . '/../deploy/inspect_coreaccounting_code_upgrade.php';

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$refuses = static function (callable $action) use ($expect): void {
    try {
        $action();
    } catch (RuntimeException) {
        $expect(true, 'Expected refusal occurred.');
        return;
    }
    $expect(false, 'Expected refusal did not occur.');
};

$options = ['--inspect'];
foreach (['old-package', 'old-commit', 'old-manifest-sha256', 'package',
    'commit', 'manifest-sha256', 'webroot', 'private-runtime', 'database',
    'origin', 'db-config', 'mail-log'] as $name) {
    $options[] = '--' . $name . '=fixture';
}
$parsed = coreAccountingUpgradeOptions(array_merge(['script.php'], $options));
$expect(count($parsed) === 13 && $parsed['mode'] === 'inspect',
    'An exact upgrade inspection option set was rejected.');
$refuses(static fn() => coreAccountingUpgradeOptions(array_merge(['script.php'],
    array_slice($options, 0, -1))));
$refuses(static fn() => coreAccountingUpgradeOptions(array_merge(['script.php'],
    $options, ['--database=other'])));
$refuses(static fn() => coreAccountingUpgradeOptions(array_merge(['script.php'],
    $options, ['--activate'])));
$activation = array_merge(['script.php', '--confirm-code-only-upgrade'],
    array_slice($options, 1), ['--backup-root=/private/backup',
        '--webroot-sha256=' . str_repeat('a', 64),
        '--private-sha256=' . str_repeat('b', 64)]);
$expect(coreAccountingUpgradeOptions($activation)['mode'] === 'confirm-code-only-upgrade',
    'A complete guarded activation request was rejected.');
$refuses(static fn() => coreAccountingUpgradeOptions(array_slice($activation, 0, -1)));

$matched = ['database' => 'qa_db', 'counts' => [
    'shipped' => 282, 'matched' => 282, 'pending' => 0, 'changed' => 0,
    'failed' => 0, 'recorded_not_packaged' => 0]];
$expect(coreAccountingUpgradeMigrationCounts($matched, 'qa_db')['matched'] === 282,
    'An exact migration ledger was rejected.');
$refuses(static fn() => coreAccountingUpgradeMigrationCounts($matched, 'other_db'));
foreach (['pending', 'changed', 'failed', 'recorded_not_packaged'] as $name) {
    $divergent = $matched;
    $divergent['counts'][$name] = 1;
    $refuses(static fn() => coreAccountingUpgradeMigrationCounts($divergent, 'qa_db'));
}
$divergent = $matched;
$divergent['counts']['matched']--;
$refuses(static fn() => coreAccountingUpgradeMigrationCounts($divergent, 'qa_db'));

$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coreaccounting-upgrade-inspect-'
    . bin2hex(random_bytes(6));
if (!mkdir($fixture, 0700)) throw new RuntimeException('Could not prepare upgrade fixture.');
try {
    $file = $fixture . '/file.txt';
    file_put_contents($file, 'first');
    $expect(coreAccountingUpgradeDirectory($fixture, 'Fixture') === realpath($fixture),
        'An existing absolute directory was rejected.');
    $expect(coreAccountingUpgradeFile($file, 'Fixture') === realpath($file),
        'An existing absolute file was rejected.');
    $before = coreAccountingUpgradeFingerprint($fixture);
    file_put_contents($file, 'second');
    $expect(coreAccountingUpgradeFingerprint($fixture) !== $before,
        'A changed live file retained its inspection fingerprint.');
    $refuses(static fn() => coreAccountingUpgradeDirectory('relative', 'Fixture'));
    $refuses(static fn() => coreAccountingUpgradeFile($fixture . '/missing', 'Fixture'));
    echo "CoreAccounting code upgrade inspection: {$checks} checks passed\n";
} finally {
    $resolved = realpath($fixture);
    $temp = realpath(sys_get_temp_dir());
    if ($resolved === false || $temp === false
        || !str_starts_with($resolved, $temp . DIRECTORY_SEPARATOR . 'coreaccounting-upgrade-inspect-')) {
        throw new RuntimeException('Refusing fixture cleanup outside the test directory.');
    }
    unlink($resolved . '/file.txt');
    rmdir($resolved);
}
