<?php
declare(strict_types=1);
require_once __DIR__ . '/../deploy/full_core_database_transfer.php';

// Inspect the actual child command without opening a database or starting a transfer.
// This regression matters on Windows too: an independent 30-minute child timeout
// previously killed transfers despite the reviewed 50-minute PHP budget.
$class = new ReflectionClass(FullCoreDatabaseTransfer::class);
$transfer = $class->newInstanceWithoutConstructor();
$arguments = ['--no-defaults', '--user=grcudkpvcd', '--default-character-set=utf8mb4', '--host=localhost'];
$class->getProperty('arguments')->setValue($transfer, $arguments);
$checks = 0;
$check = static function (bool $ok, string $why) use (&$checks): void {
    if (!$ok) throw new RuntimeException($why);
    $checks++;
};
$check($class->getConstant('MAX_TRANSFER_SECONDS') === 3000, 'Reviewed transfer budget remains fifty minutes.');
foreach (['capture' => '/usr/bin/mariadb-dump', 'restore' => '/usr/bin/mariadb'] as $mode => $client) {
    $command = $class->getMethod('command')->invoke($transfer, $mode);
    $check(array_slice($command, 0, 5) === ['/usr/bin/timeout', '--signal=TERM', '--kill-after=5s', '3000s', $client],
        $mode . ' child must receive the full reviewed budget and retain forced cleanup.');
    $check(array_slice($command, 5, count($arguments)) === $arguments && end($command) === 'grcudkpvcd',
        $mode . ' retains the exact database identity.');
    $allowances = $transfer->writerAllowances($mode);
    $check($allowances === [
        'timeout' => ['parent' => 'owner', 'command_title_sha256' => hash('sha256', implode("\0", $command))],
        'client' => ['parent' => 'timeout', 'command_title_sha256' => hash('sha256', implode("\0", array_slice($command, 4)))],
    ], $mode . ' observer must authenticate the same bounded command that will execute.');
}
echo "full_core_database_transfer_deadline_smoke: $checks checks passed\n";
