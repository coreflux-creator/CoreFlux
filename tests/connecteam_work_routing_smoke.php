<?php
/** Connecteam work assignment and approved-time routing smoke. */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;
$assert = static function (string $message, bool $condition) use (&$pass, &$fail): void {
    if ($condition) { echo "  ok  {$message}\n"; $pass++; }
    else { echo "FAIL  {$message}\n"; $fail++; }
};
$contains = static fn(string $haystack, string $needle): bool => strpos($haystack, $needle) !== false;

echo "Database and API surface\n";
$migration = (string) file_get_contents($ROOT . '/core/migrations/134_connecteam_work_routing.sql');
$api = (string) file_get_contents($ROOT . '/api/connecteam.php');
$assert('overhead destinations are durable', $contains($migration, 'CREATE TABLE IF NOT EXISTS connecteam_overhead_categories'));
$assert('effective-dated work routes are durable', $contains($migration, 'CREATE TABLE IF NOT EXISTS connecteam_work_routes'));
$assert('time staging preserves routing outcomes', $contains($migration, 'CREATE TABLE IF NOT EXISTS connecteam_time_staging'));
$assert('route destination is placement or overhead', $contains($migration, "ENUM('placement','overhead')"));
$assert('API exposes routing snapshot', $contains($api, "case 'routing'"));
$assert('API exposes overhead management', $contains($api, "case 'save_overhead'") && $contains($api, "case 'delete_overhead'"));
$assert('API exposes route management', $contains($api, "case 'save_route'") && $contains($api, "case 'delete_route'"));
$assert('API exposes read-only time preview', $contains($api, "case 'time_preview'"));
$assert('placement route must belong to linked person', $contains($api, 'belongs to a different CoreFlux person'));
$assert('placement route cannot outlive its placement', $contains($api, 'cannot be after the placement end date'));
$assert('overlapping routes are rejected', $contains($api, 'overlapping route'));

foreach (['routing', 'save_overhead', 'delete_overhead', 'save_route', 'delete_route', 'time_preview'] as $shim) {
    $assert("{$shim} endpoint shim exists", file_exists($ROOT . "/api/connecteam/{$shim}.php"));
}

echo "\nSyntax\n";
foreach ([
    'core/connecteam/work_routing.php',
    'api/connecteam.php',
] as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($ROOT . '/' . $file) . ' 2>&1', $output, $code);
    $assert("php -l {$file}", $code === 0);
}

echo "\nPure routing rules\n";
require_once $ROOT . '/core/connecteam/client.php';
require_once $ROOT . '/core/connecteam/reconcile.php';
require_once $ROOT . '/core/connecteam/work_routing.php';

$row = [
    'source_job_id' => 'internal',
    'source_sub_job_id' => '',
    'source_user_id' => 'worker-7',
    'work_date' => '2026-09-08',
];
$routes = [
    [
        'id' => 1, 'active' => 1, 'source_job_id' => 'internal', 'source_sub_job_id' => '',
        'source_user_id' => '', 'destination_type' => 'overhead', 'overhead_category_id' => 9,
        'effective_from' => '2026-01-01', 'effective_to' => null, 'priority' => 100,
    ],
    [
        'id' => 2, 'active' => 1, 'source_job_id' => 'internal', 'source_sub_job_id' => '',
        'source_user_id' => 'worker-7', 'destination_type' => 'placement', 'placement_id' => 238,
        'effective_from' => '2026-09-01', 'effective_to' => null, 'priority' => 100,
    ],
];
$resolved = connecteamResolveWorkRoute($row, $routes);
$assert('worker-specific route beats general category route', $resolved['status'] === 'ready' && (int) $resolved['route']['id'] === 2);

$expired = $routes;
$expired[1]['effective_to'] = '2026-09-07';
$resolved = connecteamResolveWorkRoute($row, $expired);
$assert('expired worker route falls back to effective general route', $resolved['status'] === 'ready' && (int) $resolved['route']['id'] === 1);

$ambiguous = [$routes[1], array_merge($routes[1], ['id' => 3])];
$resolved = connecteamResolveWorkRoute($row, $ambiguous);
$assert('equally specific routes fail closed', $resolved['status'] === 'ambiguous_route' && $resolved['route'] === null);

$resolved = connecteamResolveWorkRoute(array_merge($row, ['source_job_id' => 'unknown']), $routes);
$assert('unknown category remains unmapped', $resolved['status'] === 'unmapped_work');

echo "\nTimesheet normalization\n";
$normalized = connecteamNormalizeTimesheetRows('clock-1', [
    'data' => ['users' => [[
        'userId' => 'worker-7',
        'dailyRecords' => [[
            'date' => '2026-09-08', 'dailyTotalWorkHours' => 8, 'isApproved' => true, 'isSubmitted' => true,
            'records' => [
                ['timeActivityId' => 'activity-a', 'start' => ['timestamp' => 1000], 'end' => ['timestamp' => 11800], 'resources' => [['resourceId' => 'client', 'subResourceId' => 'site-a']]],
                ['timeActivityId' => 'activity-b', 'start' => ['timestamp' => 11800], 'end' => ['timestamp' => 29800], 'resources' => [['resourceId' => 'internal']]],
            ],
        ]],
    ]]],
], ['worker-7' => ['name' => 'Ada Worker']], [
    'client' => ['name' => 'Client work'], 'internal' => ['name' => 'Internal'],
]);
$assert('one source row is produced per time activity', count($normalized) === 2);
$assert('resource and sub-resource IDs are preserved', $normalized[0]['source_job_id'] === 'client' && $normalized[0]['source_sub_job_id'] === 'site-a');
$assert('approved and submitted flags are preserved', $normalized[0]['source_approved'] === true && $normalized[0]['source_submitted'] === true);
$assert('daily hours are proportionally allocated', abs((float) $normalized[0]['hours'] - 3.0) < 0.001 && abs((float) $normalized[1]['hours'] - 5.0) < 0.001);

echo "\nUI contract\n";
$settingsUi = (string) file_get_contents($ROOT . '/dashboard/src/pages/ConnecteamSettings.jsx');
$routingUi = (string) file_get_contents($ROOT . '/dashboard/src/pages/ConnecteamWorkRouting.jsx');
$client = (string) file_get_contents($ROOT . '/core/connecteam/client.php');
$assert('settings page mounts work routing', $contains($settingsUi, '<ConnecteamWorkRouting'));
$assert('old placement-job reconciliation label is removed', !$contains($settingsUi, 'Placement jobs'));
$assert('route editor distinguishes placement and overhead', $contains($routingUi, 'connecteam-route-form') && $contains($routingUi, "changeDestination('placement')") && $contains($routingUi, "changeDestination('overhead')"));
$assert('overhead destinations have their own editor', $contains($routingUi, 'connecteam-overhead-form'));
$assert('time preview says it creates no records', $contains($routingUi, 'does not create time, payroll, billing, accounting, or placement records'));
$assert('Connecteam jobs are labeled as routing categories', $contains($client, 'Work categories used by time routing'));

echo "\n=========================================\n";
echo "Connecteam work routing smoke: {$pass} ok / {$fail} fail\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
