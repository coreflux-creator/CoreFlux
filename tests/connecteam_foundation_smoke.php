<?php
/** Connecteam read-only connector foundation smoke. */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;
$assert = static function (string $message, bool $condition) use (&$pass, &$fail): void {
    if ($condition) { echo "  ok  {$message}\n"; $pass++; }
    else { echo "FAIL  {$message}\n"; $fail++; }
};
$contains = static fn(string $haystack, string $needle): bool => strpos($haystack, $needle) !== false;

echo "Migration and server surface\n";
$migration = (string) file_get_contents($ROOT . '/core/migrations/133_connecteam_integration_foundation.sql');
$client = (string) file_get_contents($ROOT . '/core/connecteam/client.php');
$reconcile = (string) file_get_contents($ROOT . '/core/connecteam/reconcile.php');
$api = (string) file_get_contents($ROOT . '/api/connecteam.php');
$assert('connection table is declared', $contains($migration, 'CREATE TABLE IF NOT EXISTS connecteam_connections'));
$assert('audit table is declared', $contains($migration, 'CREATE TABLE IF NOT EXISTS connecteam_sync_audit'));
$assert('credential column is encrypted storage', $contains($migration, 'api_key_ct'));
$assert('capability snapshot is persisted', $contains($migration, 'capability_snapshot'));
$assert('client uses CoreFlux encryption', $contains($client, 'encryptField(') && $contains($client, 'decryptField('));
$assert('US and Australian endpoints exist', $contains($client, 'api.connecteam.com') && $contains($client, 'api-au.connecteam.com'));
$assert('probe is explicitly read only', $contains($client, "'read_only' => true"));
$assert('API exposes connect', $contains($api, "case 'connect'"));
$assert('API exposes probe', $contains($api, "case 'probe'"));
$assert('API exposes dry-run preview', $contains($api, "case 'preview'"));
$assert('API exposes people search', $contains($api, "case 'people_search'"));
$assert('API exposes source-specific person links', $contains($api, "case 'link_person'") && $contains($api, "'connecteam', 'person'"));
$assert('API exposes safe unlink', $contains($api, "case 'unlink_person'"));
$assert('API supports Connecteam-only person creation', $contains($api, "case 'create_person'"));
$assert('API exposes disconnect', $contains($api, "case 'disconnect'"));
$assert('API resolves shared People and Placement catalogs', $contains($api, "effectiveTenantIdForModule('people'") && $contains($api, "effectiveTenantIdForModule('placements'"));
$assert('reconciliation forbids job-created placements', $contains($reconcile, 'A Connecteam job can never create a CoreFlux placement.'));
$assert('reconciliation uses universal identity links', $contains($reconcile, 'external_entity_mappings') && $contains($reconcile, 'source_system = "connecteam"'));

foreach (['status', 'connect', 'probe', 'preview', 'people_search', 'link_person', 'unlink_person', 'create_person', 'disconnect'] as $shim) {
    $assert("{$shim} endpoint shim exists", file_exists($ROOT . "/api/connecteam/{$shim}.php"));
}

echo "\nSyntax\n";
foreach ([
    'core/connecteam/client.php',
    'core/connecteam/reconcile.php',
    'api/connecteam.php',
] as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($ROOT . '/' . $file) . ' 2>&1', $output, $code);
    $assert("php -l {$file}", $code === 0);
}

echo "\nCapability probe contract\n";
require_once $ROOT . '/core/connecteam/client.php';
$calls = [];
$GLOBALS['__connecteam_transport'] = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
    $calls[] = compact('method', 'url', 'headers', 'body');
    if (str_ends_with($url, '/me')) {
        return ['status' => 200, 'body' => ['data' => ['company' => ['id' => 'co-7', 'name' => 'Thunderhawk Ops']]], 'headers' => []];
    }
    if (str_contains($url, '/users/v1/users?')) {
        return ['status' => 200, 'body' => ['data' => ['users' => [
            ['userId' => 101, 'firstName' => 'Ada', 'lastName' => 'Lovelace', 'email' => 'ada@example.com'],
            ['userId' => 102, 'firstName' => 'Grace', 'lastName' => 'Hopper', 'email' => 'grace@example.com'],
        ]]], 'headers' => []];
    }
    if (str_contains($url, '/jobs/v1/jobs?')) {
        return ['status' => 200, 'body' => ['data' => ['jobs' => [
            ['jobId' => 'job-1', 'title' => 'Service Desk Analyst', 'code' => 'PL-238'],
        ]]], 'headers' => []];
    }
    if (str_contains($url, '/forms/v1/forms')) {
        return ['status' => 403, 'body' => ['detail' => 'Forms API is not included'], 'headers' => []];
    }
    $collections = [
        '/users/v1/custom-fields' => 'customFields',
        '/scheduler/v1/schedulers' => 'schedulers',
        '/time-clock/v1/time-clocks' => 'timeClocks',
        '/time-off/v1/policy-types' => 'policyTypes',
        '/company-policies/v1/pay-rule-policies' => 'payRulesPolicies',
        '/company-policies/v1/working-hours-policies' => 'workingHoursPolicies',
        '/company-policies/v1/scheduling-rule-policies' => 'schedulingRulePolicies',
        '/tasks/v1/taskboards' => 'taskBoards',
        '/settings/v1/webhooks' => 'webhooks',
    ];
    foreach ($collections as $path => $collection) {
        if (str_contains($url, $path)) {
            return ['status' => 200, 'body' => ['data' => [$collection => []]], 'headers' => []];
        }
    }
    return ['status' => 404, 'body' => ['detail' => 'Unexpected test URL'], 'headers' => []];
};

$probe = connecteamProbeWithKey('test-connecteam-key-1234', 'us');
$assert('account is identified', $probe['account']['name'] === 'Thunderhawk Ops');
$assert('all feature contracts are checked', $probe['summary']['total'] === 12);
$assert('one restricted feature is reported without failing connection', $probe['summary']['restricted'] === 1);
$assert('users are inventoried', $probe['inventory']['users']['visible_count'] === 2);
$assert('job sample keeps placement code', $probe['inventory']['jobs']['sample'][0]['code'] === 'PL-238');
$assert('API key is sent in a header', (bool) array_filter($calls[0]['headers'], static fn(string $header): bool => str_starts_with($header, 'X-API-KEY:')));
$assert('probe used only GET requests', count(array_filter($calls, static fn(array $call): bool => $call['method'] !== 'GET')) === 0);
unset($GLOBALS['__connecteam_transport']);

echo "\nReconciliation rules\n";
require_once $ROOT . '/core/connecteam/reconcile.php';
$peoplePreview = connecteamBuildPeoplePreview(
    [
        ['userId' => '101', 'firstName' => 'Ada', 'lastName' => 'Lovelace', 'email' => 'ada@example.com'],
        ['userId' => '102', 'firstName' => 'Grace', 'lastName' => 'Hopper', 'email' => 'grace@example.com'],
        ['userId' => '103', 'firstName' => 'New', 'lastName' => 'Worker', 'email' => 'new@example.com'],
    ],
    [
        ['id' => 1, 'external_id' => 'connecteam:101', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email_primary' => 'ada@example.com', 'phone_primary' => ''],
        ['id' => 2, 'external_id' => null, 'first_name' => 'Grace', 'last_name' => 'Hopper', 'email_primary' => 'grace@example.com', 'phone_primary' => ''],
    ]
);
$assert('stored user ID is exact', $peoplePreview['counts']['exact'] === 1);
$assert('email match stays review-only', $peoplePreview['counts']['suggested'] === 1);
$assert('unknown person stays unmatched', $peoplePreview['counts']['unmatched'] === 1);
$namePreview = connecteamBuildPeoplePreview(
    [['userId' => '104', 'firstName' => 'Katherine', 'lastName' => 'Johnson']],
    [['id' => 4, 'external_id' => null, 'first_name' => 'Katherine', 'last_name' => 'Johnson', 'email_primary' => 'kj@coreflux.test', 'phone_primary' => '']]
);
$assert('unique normalized name is a reviewable suggestion', $namePreview['counts']['suggested'] === 1 && $namePreview['rows'][0]['method'] === 'Unique name');
$nestedContactPreview = connecteamBuildPeoplePreview(
    [['userId' => '105', 'contactDetails' => ['firstName' => 'Dorothy', 'lastName' => 'Vaughan', 'email' => 'dorothy@example.com']]],
    [['id' => 5, 'external_id' => null, 'first_name' => 'Dorothy', 'last_name' => 'Vaughan', 'email_primary' => 'dorothy@example.com', 'phone_primary' => '']]
);
$assert('nested Connecteam contact details participate in matching', $nestedContactPreview['counts']['suggested'] === 1);
$secondaryIdentityPreview = connecteamBuildPeoplePreview(
    [['userId' => '106', 'firstName' => 'Mary', 'lastName' => 'Jackson', 'email' => 'mj@example.com']],
    [['id' => 6, 'external_id' => null, 'first_name' => 'Mary', 'last_name' => 'Jackson', 'email_primary' => 'mary@coreflux.test', 'email_secondary' => 'mj@example.com', 'phone_primary' => '', 'phone_secondary' => '']]
);
$assert('secondary CoreFlux contact fields participate in matching', $secondaryIdentityPreview['counts']['suggested'] === 1);

$jobsPreview = connecteamBuildJobsPreview(
    [
        ['jobId' => 'job-1', 'title' => 'Analyst', 'code' => 'PL-238'],
        ['jobId' => 'job-2', 'title' => 'Engineer', 'code' => ''],
        ['jobId' => 'job-3', 'title' => 'Unknown', 'code' => ''],
    ],
    [
        ['id' => 238, 'external_id' => 'jd:123', 'title' => 'Analyst'],
        ['id' => 239, 'external_id' => 'jd:456', 'title' => 'Engineer'],
    ]
);
$assert('PL job code is exact', $jobsPreview['counts']['exact'] === 1);
$assert('title-only job stays review-only', $jobsPreview['counts']['suggested'] === 1);
$assert('unknown job cannot create a placement', $jobsPreview['counts']['unmatched'] === 1);
$flatJobs = connecteamFlattenJobs([
    ['jobId' => 'parent', 'title' => 'Client', 'subJobs' => [
        ['jobId' => 'child', 'title' => 'Assignment', 'code' => 'PL-238'],
    ]],
]);
$assert('nested sub-jobs are reconciled independently', count($flatJobs) === 2 && $flatJobs[1]['jobId'] === 'child');

echo "\nUI wiring\n";
$ui = (string) file_get_contents($ROOT . '/dashboard/src/pages/ConnecteamSettings.jsx');
$admin = (string) file_get_contents($ROOT . '/dashboard/src/pages/AdminModule.jsx');
$hub = (string) file_get_contents($ROOT . '/dashboard/src/pages/IntegrationsHub.jsx');
$rbac = (string) file_get_contents($ROOT . '/core/rbac/legacy_map.php');
$mappingApi = (string) file_get_contents($ROOT . '/api/integrations/mappings.php');
$assert('settings page has connection input', $contains($ui, 'data-testid="connecteam-api-key-input"'));
$assert('settings page has capability table', $contains($ui, 'data-testid="connecteam-capabilities-table"'));
$assert('settings page has reconciliation dry run', $contains($ui, 'data-testid="connecteam-preview-btn"'));
$assert('settings page can search and link an existing person', $contains($ui, 'connecteam-person-search-') && $contains($ui, 'Link to P-'));
$assert('settings page can create and link a person', $contains($ui, 'connecteam-create-person-') && $contains($ui, 'Create and link'));
$assert('settings page explains multi-source identity', $contains($ui, 'can all link to the same P-ID'));
$assert('admin route is mounted', $contains($admin, '/integrations/connecteam'));
$assert('integration hub card is mounted', $contains($hub, 'integration-card-connecteam'));
$assert('view RBAC is registered', $contains($rbac, "'integrations.connecteam.view'"));
$assert('manage RBAC is registered', $contains($rbac, "'integrations.connecteam.manage'"));
$assert('person source panel permits Connecteam viewers', $contains($mappingApi, "'integrations.connecteam.view'"));
$assert('person source panel resolves shared catalog scope', $contains($mappingApi, '_integrationMappingsTenantId') && $contains($mappingApi, "'person', 'employee' => 'people'"));

echo "\n=========================================\n";
echo "Connecteam foundation smoke: {$pass} ok / {$fail} fail\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
