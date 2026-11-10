<?php
/** Post-deploy verification for the disposable CoreAccounting QA app only. */
declare(strict_types=1);

const QA_PRIVATE = '/home/master/.coreaccounting-cleanqa';
const QA_ROOT = '/home/1516771.cloudwaysapps.com/aqdcpvafpj/public_html';
const QA_CONFIG = '/home/master/.coreaccounting-cleanqa2/db.local.php';
const QA_DATABASE = 'aqdcpvafpj';
const QA_ORIGIN = 'https://phpstack-1516771-6717961.cloudwaysapps.com';

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !in_array('--confirm-disposable-qa', $argv, true)
    || realpath(__DIR__) !== QA_PRIVATE || realpath(QA_ROOT) !== QA_ROOT
    || getenv('COREFLUX_ACCOUNTING_DB_CONFIG_PATH') !== QA_CONFIG
    || getenv('COREFLUX_STANDALONE_DATABASE') !== QA_DATABASE
    || getenv('COREFLUX_PUBLIC_ORIGIN') !== QA_ORIGIN) {
    fwrite(STDERR, "Disposable QA CLI with its exact app, database and HTTPS origin only.\n");
    exit(2);
}

function qaReleaseRun(string $script, array $args): array
{
    $path = QA_PRIVATE . '/' . $script;
    if (realpath($path) !== $path || is_link($path) || !is_file($path)) {
        throw new RuntimeException("Pinned QA check is unavailable: $script");
    }
    $command = array_merge([PHP_BINARY, $path], $args);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException("Could not run QA check: $script");
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException("QA check $script failed ($status): " . trim((string) $stderr)
            . ' ' . trim((string) $stdout));
    }
    $result = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result)) throw new RuntimeException("QA check $script returned no result.");
    return $result;
}

try {
    $audit = qaReleaseRun('audit_coreaccounting_migrations.php',
        ['--database=' . QA_DATABASE, '--root=' . QA_ROOT]);
    if (($audit['database'] ?? null) !== QA_DATABASE
        || ($audit['counts']['matched'] ?? 0) < 1
        || array_sum(array_intersect_key($audit['counts'] ?? [], array_flip([
            'pending', 'changed', 'failed', 'recorded_not_packaged',
        ]))) !== 0) {
        throw new RuntimeException('QA migration ledger is not fully matched.');
    }

    $purge = qaReleaseRun('qa2_purge_cache.php', ['--confirm-disposable-qa']);
    if (($purge['host'] ?? null) !== parse_url(QA_ORIGIN, PHP_URL_HOST)
        || ($purge['purged_paths'] ?? 0) < 1 || ($purge['failed_count'] ?? -1) !== 0) {
        throw new RuntimeException('QA app cache purge is incomplete.');
    }

    $inventory = qaReleaseRun('qa2_public_file_inventory.php', [
        '--confirm-disposable-qa', '--root=' . QA_ROOT, '--origin=' . QA_ORIGIN,
    ]);
    if (($inventory['root'] ?? null) !== QA_ROOT || ($inventory['files'] ?? 0) < 1
        || ($inventory['retired_files'] ?? 0) < 1
        || count($inventory['served'] ?? []) < 1) {
        throw new RuntimeException('QA outside-in public-file inventory is incomplete.');
    }
    foreach (['unexpected_served', 'missing_served', 'content_mismatch',
        'stale_retired', 'unexpected_status', 'errors'] as $problem) {
        if (!array_key_exists($problem, $inventory) || $inventory[$problem] !== []) {
            throw new RuntimeException("QA outside-in inventory failed: $problem");
        }
    }

    echo json_encode([
        'app' => QA_ORIGIN,
        'database' => QA_DATABASE,
        'matched_migrations' => $audit['counts']['matched'],
        'purged_paths' => $purge['purged_paths'],
        'current_non_php_files' => $inventory['files'],
        'retired_non_php_paths' => $inventory['retired_files'],
        'byte_matched_public_files' => count($inventory['served']),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
