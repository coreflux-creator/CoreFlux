<?php
declare(strict_types=1);

require_once __DIR__ . '/../deploy/install_coreaccounting_clean_app.php';

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coreaccounting-clean-installer-' . bin2hex(random_bytes(6));
$source = $base . '/source';
$target = $base . '/target';
$privateSource = $base . '/private-source';
$privateTarget = $base . '/private-target';
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $action) use ($expect): void {
    try {
        $action();
    } catch (RuntimeException $error) {
        $expect(true, 'Expected refusal occurred.');
        return;
    }
    $expect(false, 'Expected refusal did not occur.');
};

if (!mkdir($source . '/nested', 0700, true)
    || !mkdir($target, 0700, true)
    || !mkdir($privateSource . '/vendor', 0700, true)
    || !mkdir($privateTarget, 0700, true)) {
    throw new RuntimeException('Could not prepare installer fixture.');
}
try {
    file_put_contents($source . '/.htaccess', "Options -Indexes\n");
    file_put_contents($source . '/nested/file.txt', 'first');
    file_put_contents($privateSource . '/vendor/autoload.php', "<?php\n");
    $expect(coreAccountingInstallAbsolute($base), 'An absolute test path was rejected.');
    $expect(coreAccountingInstallAbsolute('C:/accounting/public_html'), 'A drive path was rejected.');
    $expect(!coreAccountingInstallAbsolute('relative/public_html'), 'A relative path was accepted.');
    $expect(coreAccountingInstallWithin($source . '/nested/file.txt', $source), 'A child path was not recognized.');
    $expect(!coreAccountingInstallWithin($source . '-other', $source), 'A sibling was treated as a child.');
    $expect(coreAccountingInstallNewPath($base . '/new-backup', 'Backup')
        === realpath($base) . DIRECTORY_SEPARATOR . 'new-backup',
        'New private destination did not normalize.');
    $throws(static fn() => coreAccountingInstallNewPath($target, 'Backup'));

    $mailLog = $base . '/mail.jsonl';
    file_put_contents($mailLog, '');
    $expect(coreAccountingInstallMailLog($mailLog, $target, $source) === realpath($mailLog),
        'An empty private mail log was rejected.');
    $throws(static fn() => coreAccountingInstallMailLog($base . '/missing.jsonl', $target, $source));
    file_put_contents($mailLog, 'existing message');
    $throws(static fn() => coreAccountingInstallMailLog($mailLog, $target, $source));
    file_put_contents($mailLog, '');

    $before = coreAccountingInstallInventory($source);
    $expect(isset($before['d:nested'], $before['f:nested/file.txt'], $before['f:.htaccess']),
        'The source inventory missed a directory or file.');
    coreAccountingInstallCopyTree($source, $target);
    coreAccountingInstallCopyTree($privateSource, $privateTarget);
    $expect(coreAccountingInstallInventory($target) === $before, 'Copied release tree differs from its source.');
    $throws(static fn() => coreAccountingInstallCopyTree($source, $target));

    require_once __DIR__ . '/../deploy/coreaccounting_release_files.php';
    $manifest = [
        'files' => coreAccountingReleaseFileHashes($source),
        'private_files' => coreAccountingReleaseFileHashes($privateSource),
    ];
    $apache = coreAccountingInstallApache($source, 'fixture_db', 'https://qa.example.invalid',
        '/private/db.php', '/private/vendor/autoload.php', '/private/mail.log');
    $expect(str_contains($apache, 'SetEnv MAIL_DRIVER log')
        && str_contains($apache, 'SetEnv COREFLUX_STANDALONE_DATABASE fixture_db'),
        'Host Apache settings omitted the isolated database or log driver.');
    file_put_contents($target . '/.htaccess', $apache);
    coreAccountingInstallVerifyCopied($target, $privateTarget, $manifest, hash('sha256', $apache));
    $expect(true, 'Verified installed files matched both release trees.');
    file_put_contents($target . '/unexpected.txt', 'untracked');
    $throws(static fn() => coreAccountingInstallVerifyCopied(
        $target, $privateTarget, $manifest, hash('sha256', $apache)));
    unlink($target . '/unexpected.txt');

    $installed = $base . '/installed';
    mkdir($installed, 0700);
    file_put_contents($installed . '/placeholder.txt', 'before install');
    coreAccountingInstallActivateFiles($source, $privateSource, $installed,
        $base . '/installed-private', $base . '/installed-backup',
        $apache, $manifest, ['status' => 'fixture']);
    $expect(is_file($installed . '/nested/file.txt')
        && is_file($base . '/installed-backup/original/placeholder.txt')
        && is_file($base . '/installed-backup/install-receipt.json'),
        'Successful activation did not retain the release and rollback point.');

    $rollbackTarget = $base . '/rollback-target';
    mkdir($rollbackTarget, 0700);
    file_put_contents($rollbackTarget . '/placeholder.txt', 'restore me');
    $changedManifest = $manifest;
    $changedManifest['files']['nested/file.txt'] = str_repeat('0', 64);
    $throws(static fn() => coreAccountingInstallActivateFiles($source, $privateSource,
        $rollbackTarget, $base . '/rollback-private', $base . '/rollback-backup',
        $apache, $changedManifest, ['status' => 'fixture']));
    $expect(file_get_contents($rollbackTarget . '/placeholder.txt') === 'restore me'
        && !file_exists($rollbackTarget . '/nested')
        && is_file($base . '/rollback-backup/failed/nested/file.txt'),
        'Failed activation did not restore the original webroot.');

    file_put_contents($source . '/nested/file.txt', 'changed');
    $after = coreAccountingInstallInventory($source);
    $expect($after !== $before, 'A changed webroot file retained its prior inventory.');
    file_put_contents($source . '/.htaccess', "SetEnv MAIL_DRIVER resend\nOptions -Indexes\n");
    $throws(static fn() => coreAccountingInstallApache($source, 'fixture_db',
        'https://qa.example.invalid', '/private/db.php',
        '/private/vendor/autoload.php', '/private/mail.log'));

    echo "CoreAccounting clean installer: {$checks} checks passed\n";
} finally {
    $resolved = realpath($base);
    $temp = realpath(sys_get_temp_dir());
    if ($resolved === false || $temp === false
        || !str_starts_with($resolved, $temp . DIRECTORY_SEPARATOR . 'coreaccounting-clean-installer-')) {
        throw new RuntimeException('Refusing fixture cleanup outside the test directory.');
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($resolved);
}
