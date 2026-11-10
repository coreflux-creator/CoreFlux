<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !is_file('/run/core-channel-fixture') || realpath('/fixture') !== '/fixture') {
    throw new RuntimeException('Disposable container required.');
}
require __DIR__ . '/../deploy/full_core_maintenance.php';
require __DIR__ . '/../deploy/full_core_maintenance_channel.php';
mkdir('/fixture/public_html', 0755); mkdir('/fixture/private', 0700);
$operation = bin2hex(random_bytes(16)); $token = bin2hex(random_bytes(32));
$path = 'core-maintenance-' . $operation . '-' . bin2hex(random_bytes(16)) . '.php';
$held = fullCoreMaintenanceWebRule($operation, $path);
copy(__DIR__ . '/../deploy/full_core_maintenance_channel.php', '/fixture/private/full_core_maintenance_channel.php');
chmod('/fixture/private/full_core_maintenance_channel.php', 0600);
$spec = ['operation' => $operation, 'candidate_commit' => str_repeat('c', 40), 'path' => $path,
    'root' => '/fixture/public_html', 'private_directory' => '/fixture/private', 'host' => 'fixture.local', 'pool' => 'www',
    'token_sha256' => hash('sha256', $token), 'held_web_sha256' => hash('sha256', $held),
    'library_sha256' => hash_file('sha256', '/fixture/private/full_core_maintenance_channel.php'),
    'issued_at' => time() - 1, 'expires' => time() + 600];
file_put_contents('/fixture/public_html/' . $path, fullCoreMaintenanceChannelRender($spec));
file_put_contents('/fixture/client.json', json_encode(['spec' => $spec, 'token' => $token])); chmod('/fixture/client.json', 0600);
file_put_contents('/fixture/held.htaccess', $held);
$original = "Options -Indexes\nRewriteEngine On\n";
file_put_contents('/fixture/original.htaccess', $original);
file_put_contents('/fixture/public_html/.htaccess', $original);
foreach (['first', 'second'] as $name) file_put_contents('/fixture/public_html/' . $name . '.php', '<?php return "old-' . $name . '";');
file_put_contents('/fixture/public_html/read.php', '<?php echo (include __DIR__."/first.php")."|".(include __DIR__."/second.php");');
file_put_contents('/fixture/public_html/handler.php', '<?php file_put_contents("/fixture/private/business-hits", "hit", FILE_APPEND); echo "handler-executed";');
foreach (['api', 'dashboard', 'modules/accounting/api'] as $directory) {
    mkdir('/fixture/public_html/' . $directory, 0755, true);
    copy('/fixture/public_html/handler.php', '/fixture/public_html/' . $directory . '/probe.php');
    file_put_contents('/fixture/public_html/' . $directory . '/.htaccess', "RewriteEngine On\nRewriteRule ^anything$ probe.php [L]\n");
}
file_put_contents('/fixture/public_html/busy.php', <<<'PHP'
<?php
$finishing = ($_GET['finish'] ?? '') === '1';
file_put_contents('/fixture/private/busy-started', '1');
echo 'started';
if ($finishing) fastcgi_finish_request();
$until = microtime(true) + 15;
do { clearstatcache(true, '/fixture/private/release-busy'); if (is_file('/fixture/private/release-busy')) break; usleep(20000); } while (microtime(true) < $until);
PHP);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/fixture', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($iterator as $item) {
    if ($item->getPathname() === '/fixture/client.json') continue;
    chown($item->getPathname(), 33); chgrp($item->getPathname(), 33);
}
echo "fixture ready\n";
