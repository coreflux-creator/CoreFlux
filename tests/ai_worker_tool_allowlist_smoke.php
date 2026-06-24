<?php
/**
 * AI worker tool allowlist smoke test.
 *
 * Locks the AI-native worker-runtime rule that registered workers are limited
 * by declared queue and tool capabilities before they can claim background
 * jobs.
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}\n";
    }
};

$root = dirname(__DIR__);
$worker = (string) file_get_contents($root . '/core/ai/worker.php');
$cron = (string) file_get_contents($root . '/cron/ai_worker.php');
$migration = (string) file_get_contents($root . '/core/migrations/109_ai_workers_and_jobs.sql');
$alignment = (string) file_get_contents($root . '/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md');

echo "Worker claim allowlist\n";
$a('worker has capability-list helper', str_contains($worker, 'function aiWorkerCapabilityList(array $worker, array $keys): array'));
$a('worker has tool allowlist helper', str_contains($worker, 'function aiWorkerToolAllowlist(array $worker): array'));
$a('tool helper reads tool_allowlist aliases', str_contains($worker, "['tool_allowlist', 'allowed_tools', 'tools']"));
$a('wildcard/all allow all tools', str_contains($worker, "in_array('*', \$tools, true)") && str_contains($worker, "in_array('all', \$tools, true)"));
$a('claim loads registered worker', str_contains($worker, '$worker = aiWorkerGet($workerId);'));
$a('claim rejects unknown worker ids', str_contains($worker, "throw new \\InvalidArgumentException('worker not registered')"));
$a('claim derives allowed tools', str_contains($worker, '$allowedTools = aiWorkerToolAllowlist($worker);'));
$a('claim filters by tool_name before FOR UPDATE', strpos($worker, "tool_name IN (") !== false
    && strpos($worker, "tool_name IN (") < strpos($worker, 'FOR UPDATE'));
$a('queue filter remains in claim path', str_contains($worker, 'queue IN (') && str_contains($worker, "status = 'queued'"));

echo "\nCron worker capabilities\n";
$a('usage documents --tools', str_contains($cron, '[--tools=coreflux.close_packet,...]'));
$a('getopt accepts tools', str_contains($cron, "['queue::', 'tools::', 'max-jobs::', 'label::', 'once', 'verbose']"));
$a('cron parses toolAllowlist', str_contains($cron, '$toolAllowlist = isset($opts[\'tools\'])'));
$a('cron defaults tools to wildcard', str_contains($cron, ": ['*'];"));
$a('worker registration stores tool_allowlist', str_contains($cron, "'tool_allowlist'  => \$toolAllowlist"));
$a('startup log includes tools', str_contains($cron, '", tools=" . implode(\',\', $toolAllowlist)'));
$a('tool invocation caller context includes worker id', str_contains($cron, "'_worker_id' => \$workerId"));
$a('tool invocation caller context includes job id', str_contains($cron, "'_worker_job_id' => \$jobId"));

echo "\nSchema and alignment docs\n";
$a('migration documents tool_allowlist capability', str_contains($migration, '"tool_allowlist":["coreflux.x"]'));
$a('alignment documents tool capabilities', str_contains($alignment, 'AI worker processes must declare queue and tool capabilities'));
$a('alignment documents claim-time tool_name filter', str_contains($alignment, '`aiWorkerClaim()` filters queued work by'));

echo "\nSyntax checks\n";
foreach ([
    'core/ai/worker.php',
    'cron/ai_worker.php',
] as $file) {
    $out = [];
    $rc = 0;
    @exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $out, $rc);
    $a("{$file} parses", $rc === 0);
}

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
