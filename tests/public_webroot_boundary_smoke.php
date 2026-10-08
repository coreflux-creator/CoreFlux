<?php
declare(strict_types=1);

$htaccess = file_get_contents(__DIR__ . '/../.htaccess');
if ($htaccess === false) {
    throw new RuntimeException('Missing webroot rules');
}

$rules = [];
foreach (explode("\n", $htaccess) as $line) {
    if (preg_match('/^RedirectMatch 404 (.+)$/', trim($line), $match)) {
        $rules[] = '~' . $match[1] . '~';
    }
}

$isDenied = static function (string $path) use ($rules): bool {
    foreach ($rules as $rule) {
        if (preg_match($rule, $path) === 1) return true;
    }
    return false;
};

$private = [
    '/AI_INTEGRATION_RULES.md',
    '/composer.lock',
    '/dashboard/package.json',
    '/dashboard/src/App.jsx',
    '/deploy/bootstrap_coreaccounting.php',
    '/core/config.php',
    '/core/db.local.php',
    '/core/migrations/013_user_tenants_baseline.sql',
    '/modules/accounting/ui/AccountingModule.jsx',
    '/sim/runner.php',
    '/vendor/autoload.php',
];
$public = [
    '/index.html',
    '/spa-assets/index-current.js',
    '/api/index.php',
    '/modules/accounting/api/reports.php',
    '/core/api/payment_rails.php',
    '/vendor/portal',
    '/sim',
    '/sim/',
];

$failures = [];
foreach ($private as $path) {
    if (!$isDenied($path)) $failures[] = "exposed: $path";
}
foreach ($public as $path) {
    if ($isDenied($path)) $failures[] = "blocked: $path";
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo 'Passed: ' . (count($private) + count($public)) . " webroot route checks\n";
