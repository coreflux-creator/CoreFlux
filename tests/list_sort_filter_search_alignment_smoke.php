<?php
/**
 * Smoke - operational list search/filter/sort alignment.
 *
 * Locks the placement-style list contract:
 *   - server-backed lists accept whitelisted sort/dir params
 *   - React list screens expose search + filter controls
 *   - table headers use the shared useTableList sortable header API
 *   - GraphQL company status is exposed so the client filter is real
 */
declare(strict_types=1);

$passes = 0;
$failures = [];
function check(string $label, bool $cond): void {
    global $passes, $failures;
    if ($cond) {
        $passes++;
        echo "  OK  {$label}\n";
    } else {
        $failures[] = $label;
        echo "  BAD {$label}\n";
    }
}

function src(string $path): string {
    $full = __DIR__ . '/../' . $path;
    return is_file($full) ? (string) file_get_contents($full) : '';
}

echo "\nList sort/filter/search alignment smoke\n";
echo "=======================================\n\n";

$helper = src('dashboard/src/lib/useTableList.jsx');
check('useTableList supports controlled sort', str_contains($helper, 'isControlledSort') && str_contains($helper, 'onSortChange({'));
check('useTableList keeps keyboard sortable headers', str_contains($helper, "e.key === 'Enter'") && str_contains($helper, "'aria-sort'"));

$serverFiles = [
    'placements API passes sort/dir' => ['modules/placements/api/placements.php', ["'sort'", "'dir'"]],
    'placements lib whitelists sort keys' => ['modules/placements/lib/placements.php', ['$sortMap', "'start_date'", "'end_client_name'"]],
    'people API passes sort/dir' => ['modules/people/api/people.php', ["'sort'", "'dir'"]],
    'people lib whitelists sort keys' => ['modules/people/lib/people.php', ['$sortMap', "'last_name'", "'email_primary'"]],
    'staffing jobs API whitelists sort keys' => ['modules/staffing/api/jobs.php', ['$sortMap', "'placement_count'", 'ORDER BY ']],
    'staffing clients API whitelists sort keys' => ['modules/staffing/api/clients.php', ['$sortMap', "'active_placements'", 'ORDER BY {$sortExpr}']],
    'companies API passes status/sort/dir' => ['modules/people/api/companies.php', ["'status'", "'sort'", "'dir'"]],
    'companies lib exposes status and whitelisted sort' => ['modules/people/lib/companies.php', ['c.status', '$sortMap', "'use_count'"]],
];
foreach ($serverFiles as $label => [$path, $needles]) {
    $s = src($path);
    check($label, array_reduce($needles, fn(bool $ok, string $needle): bool => $ok && str_contains($s, $needle), true));
}

$uiScreens = [
    'placements REST list' => [
        'path' => 'modules/placements/ui/List.jsx',
        'search' => 'placements-search',
        'filter' => 'placements-status-filter',
        'sort' => "headerProps('start_date'",
        'controlled' => 'onSortChange: next => { setSort(next); setPage(1); }',
    ],
    'people directory' => [
        'path' => 'modules/people/ui/Directory.jsx',
        'search' => 'people-directory-search',
        'filter' => 'people-directory-status-filter',
        'sort' => "headerProps('last_name'",
        'controlled' => 'onSortChange: next => { setSort(next); setPage(1); }',
    ],
    'staffing jobs' => [
        'path' => 'modules/staffing/ui/Jobs.jsx',
        'search' => 'staffing-jobs-search',
        'filter' => 'staffing-jobs-status-filter',
        'sort' => "headerProps('placement_count'",
        'controlled' => 'onSortChange: setSort',
    ],
    'staffing clients' => [
        'path' => 'modules/staffing/ui/Clients.jsx',
        'search' => 'staffing-clients-search',
        'filter' => 'staffing-clients-status-filter',
        'sort' => "headerProps('active_placements'",
        'controlled' => 'onSortChange: setSort',
    ],
    'placements GraphQL pilot' => [
        'path' => 'modules/placements/ui/ListGraphql.jsx',
        'search' => 'placements-gql-search',
        'filter' => 'placements-gql-etype-filter',
        'sort' => "headerProps('startDate'",
        'controlled' => 'useTableList(filtered',
    ],
    'clients GraphQL pilot' => [
        'path' => 'modules/staffing/ui/ClientsGraphql.jsx',
        'search' => 'clients-gql-search',
        'filter' => 'clients-gql-status-filter',
        'sort' => "headerProps('status'",
        'controlled' => 'useTableList(filtered',
    ],
    'placements expiring' => [
        'path' => 'modules/placements/ui/Expiring.jsx',
        'search' => 'placements-expiring-search',
        'filter' => 'placements-expiring-status-filter',
        'sort' => "headerProps('due_date'",
        'controlled' => 'useTableList(filtered',
    ],
    'draft rates queue' => [
        'path' => 'modules/placements/ui/DraftRatesQueue.jsx',
        'search' => 'placements-draft-rates-search',
        'filter' => 'placements-draft-rates-status-filter',
        'sort' => "headerProps('created_at'",
        'controlled' => 'items.map',
    ],
];
foreach ($uiScreens as $label => $cfg) {
    $s = src($cfg['path']);
    check("{$label}: imports shared sorter", str_contains($s, 'useTableList') && str_contains($s, 'SortIndicator'));
    check("{$label}: searchable", str_contains($s, $cfg['search']));
    check("{$label}: filterable", str_contains($s, $cfg['filter']));
    check("{$label}: sortable header", str_contains($s, $cfg['sort']));
    check("{$label}: maps sorted/controlled rows", str_contains($s, $cfg['controlled']));
}

$schema = src('graphql/subgraph-coreflux/schema.graphql');
$resolver = src('graphql/subgraph-coreflux/src/index.ts');
check('GraphQL Company exposes status', str_contains($schema, 'status: String') && str_contains($resolver, 'status: row.status ?? null'));

$total = $passes + count($failures);
echo "\n=======================================\n";
echo "list alignment smoke: {$passes} OK / " . count($failures) . " BAD\n";
echo "=======================================\n";
if ($failures) {
    foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
    exit(1);
}
exit(0);
