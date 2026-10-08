<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/migration_policy.php';

$checks = [
    'standalone web requests cannot mutate schema' => !corefluxMayApplySchemaChanges('coreaccounting', 'fpm-fcgi'),
    'standalone CLI release can mutate schema' => corefluxMayApplySchemaChanges('coreaccounting', 'cli'),
    'ERP web migration behavior remains unchanged' => corefluxMayApplySchemaChanges('production', 'fpm-fcgi'),
    'staging bootstrap can mutate schema' => corefluxMayApplySchemaChanges('staging', 'cli'),
];

$bootstrap = (string) file_get_contents(__DIR__ . '/../core/api_bootstrap.php');
$runner = (string) file_get_contents(__DIR__ . '/../core/migrate.php');
$checks['API startup checks policy before running migrations'] =
    strpos($bootstrap, 'if (corefluxMayApplySchemaChanges(') < strpos($bootstrap, 'try { coreflux_run_migrations(); }');
$checks['known-column self-heal checks policy before DDL'] =
    strpos($bootstrap, 'function cf_self_heal_known_column')
        < strpos($bootstrap, 'if (!corefluxMayApplySchemaChanges(')
    && strpos($bootstrap, 'if (!corefluxMayApplySchemaChanges(')
        < strpos($bootstrap, '$pdo->exec("ALTER TABLE');
$checks['direct migration calls enforce the same policy'] =
    strpos($runner, 'function coreflux_run_migrations')
        < strpos($runner, 'if (!corefluxMayApplySchemaChanges(')
    && strpos($runner, 'if (!corefluxMayApplySchemaChanges(')
        < strpos($runner, '$pdo = getDB();');

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'OK  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$passed) $failed++;
}
echo $failed ? "Failed: {$failed}" . PHP_EOL : 'Passed: ' . count($checks) . PHP_EOL;
exit($failed ? 1 : 0);
