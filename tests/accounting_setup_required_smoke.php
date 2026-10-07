<?php
/** The shared API reports missing legal-entity setup as a recoverable conflict. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

if (in_array('--probe', $argv, true)) {
    putenv('COREFLUX_DISABLE_DATABASE=1');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require_once __DIR__ . '/../core/api_bootstrap.php';
    require_once __DIR__ . '/../modules/accounting/lib/accounting.php';
    throw new AccountingSetupRequired('Set up an active legal entity before recording accounting activity.');
}

$process = proc_open([PHP_BINARY, __FILE__, '--probe'], [
    1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
], $pipes);
if (!is_resource($process)) throw new RuntimeException('Could not start setup-required probe.');
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);
$response = json_decode($output, true);
if ($status !== 0 || !is_array($response)
    || ($response['status'] ?? null) !== 409
    || ($response['kind'] ?? null) !== 'accounting_setup_required'
    || ($response['setup_path'] ?? null) !== '/modules/accounting/entities') {
    fwrite(STDERR, "Accounting setup-required API mapping failed. {$errors}\n");
    exit(1);
}
echo "Accounting setup-required API mapping: 1 check passed.\n";
