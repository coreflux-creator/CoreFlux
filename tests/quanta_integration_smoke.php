<?php
/** Contract and safety smoke for the Quanta time connector. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/quanta/sync.php';
require_once dirname(__DIR__) . '/modules/people/lib/people.php';

$passed = 0;
$failed = [];
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if ($ok) $passed++; else $failed[] = $label;
};
$throws = static function (callable $call): bool {
    try { $call(); return false; } catch (Throwable $e) { return true; }
};

$calls = [];
$GLOBALS['__quanta_transport'] = static function (string $method, string $url, array $headers) use (&$calls): array {
    $calls[] = [$method, $url, $headers];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    if (($query['cursor'] ?? '') === 'next') {
        return ['status' => 200, 'body' => json_encode(['data' => [['id' => 'second']], 'pagination' => ['next_cursor' => null, 'has_more' => false, 'limit' => 2]])];
    }
    return ['status' => 200, 'body' => json_encode(['data' => [['id' => 'first']], 'pagination' => ['next_cursor' => 'next', 'has_more' => true, 'limit' => 2]])];
};
$entries = quantaListAll('fixture-key', QUANTA_APPROVED_ENTRIES_PATH);
$check('cursor pagination collects complete list', array_column($entries, 'id') === ['first', 'second']);
$check('read-only requests target the documented host', count($calls) === 2 && $calls[0][0] === 'GET'
    && str_starts_with($calls[0][1], 'https://helloquanta.app/api/v1/time-entries/approved'));
$check('key goes in bearer header, not URL', str_contains(implode(' ', $calls[0][2]), 'Bearer fixture-key')
    && !str_contains($calls[0][1], 'fixture-key'));
$check('arbitrary API host/path is rejected', $throws(static fn () => quantaGet('fixture-key', '/../../../evil')));
$check('approved-entry contract rejects old items envelope', $throws(static fn () => quantaListPage(['items' => [], 'has_more' => false], QUANTA_APPROVED_ENTRIES_PATH)));
$check('catalog accepts its existing items-only response', quantaListPage(['items' => []], '/workers') === [[], ['items' => [], 'has_more' => false]]);
unset($GLOBALS['__quanta_transport']);

$GLOBALS['__quanta_transport'] = static function (string $method, string $url, array $headers): array {
    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($path === '/api/v1/dimensions') {
        return ['status' => 200, 'body' => '{"items":[{"key":"client","label":"Client","values":[{"code":"BOC_BOC_CAPITAL","label":"BOC & BOC Capital"}]}],"has_more":false}'];
    }
    return ['status' => 200, 'body' => '{"items":[],"has_more":false}'];
};
$catalog = quantaCatalog('fixture-key');
$check('dimension catalog includes source codes and labels',
    $catalog['dimensions_access'] && $catalog['dimensions'][0]['values'][0]['code'] === 'BOC_BOC_CAPITAL');
$GLOBALS['__quanta_transport'] = static function (string $method, string $url, array $headers): array {
    if ((string) parse_url($url, PHP_URL_PATH) === '/api/v1/dimensions') {
        return ['status' => 403, 'body' => '{"detail":"Missing dimensions:read"}'];
    }
    return ['status' => 200, 'body' => '{"items":[],"has_more":false}'];
};
$catalog = quantaCatalog('fixture-key');
$check('missing dimension scope is visible without breaking worker catalog',
    $catalog['dimensions_access'] === false && $catalog['dimensions'] === [] && $catalog['workers'] === []);
unset($GLOBALS['__quanta_transport']);

$probeCalls = [];
$denyTimeEntries = true;
$missingTimeEntries = false;
$GLOBALS['__quanta_transport'] = static function (string $method, string $url, array $headers) use (&$probeCalls, &$denyTimeEntries, &$missingTimeEntries): array {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $probeCalls[] = $url;
    if ($path === '/api/v1/time-entries/approved' && $missingTimeEntries) {
        return ['status' => 404, 'body' => '{"detail":"Not Found"}'];
    }
    if ($path === '/api/v1/time-entries/approved' && $denyTimeEntries) {
        return ['status' => 403, 'body' => '{"detail":"Missing time-entry scope"}'];
    }
    if ($path === '/api/v1/time-entries/approved') {
        return ['status' => 200, 'body' => '{"data":[],"pagination":{"next_cursor":null,"has_more":false,"limit":1}}'];
    }
    return ['status' => 200, 'body' => '{"items":[]}'];
};
$probeSucceeded = true;
try { quantaProbeConnection('fixture-key'); } catch (Throwable $e) { $probeSucceeded = false; }
$check('catalog connection probes workers, worksites, and timesheets', $probeSucceeded
    && array_map(static fn (string $url): string => (string) parse_url($url, PHP_URL_PATH), $probeCalls)
        === ['/api/v1/workers', '/api/v1/worksites', '/api/v1/timesheets']);
$check('denied time entries do not masquerade as import access', !quantaTimeEntryAccess('fixture-key'));
$check('time access probe uses server-enforced approved-only endpoint',
    str_contains(end($probeCalls), '/time-entries/approved?')
    && !str_contains(end($probeCalls), 'timesheet_status='));
$timeError = '';
try { quantaEntries('fixture-key', '2026-09-01'); } catch (QuantaApiException $e) { $timeError = $e->getMessage(); }
$check('denied import explains that no hours moved', str_contains($timeError, 'No time was imported'));
$check('submitted source filter is rejected before any request', $throws(static fn () => quantaEntries('fixture-key', '2026-09-01', 'submitted,approved')));
$denyTimeEntries = false;
$missingTimeEntries = true;
$unpublishedMessage = '';
try { quantaTimeEntryAccess('fixture-key'); } catch (QuantaApiException $e) { $unpublishedMessage = $e->getMessage(); }
$check('unpublished approved endpoint has actionable no-import message',
    str_contains($unpublishedMessage, 'not published') && str_contains($unpublishedMessage, 'No hours have been imported'));
$missingTimeEntries = false;
$check('granted time-entry access is reported separately', quantaTimeEntryAccess('fixture-key'));
$requestStart = count($probeCalls);
quantaEntries('fixture-key', '2026-09-01');
$check('time listing omits forbidden source-status query', count($probeCalls) === $requestStart + 1
    && !str_contains(end($probeCalls), 'timesheet_status='));
unset($GLOBALS['__quanta_transport']);

$site = ['work-1' => ['timezone_override' => 'America/New_York']];
$raw = [
    'id' => 'q-101', 'worker_id' => 'worker-1', 'worksite_id' => 'work-1',
    'clock_in_at' => '2026-09-24T01:00:00Z', 'timesheet_status' => 'approved',
    'dimension_values' => ['project' => 'p-7', 'client' => 'c-3'],
    'reg_hours' => 6.5, 'ot_hours' => 1.5, 'dt_hours' => 0, 'pto_hours' => 0,
    'duration_hours' => 8, 'notes' => 'Customer support',
];
$entry = quantaNormalizeEntry($raw, $site, 'approved');
$check('work date honors worksite timezone', $entry['work_date'] === '2026-09-23');
$check('regular and OT remain separate components', $entry['components'] === ['regular' => 6.5, 'overtime' => 1.5]);
$check('client work remains billable', quantaComponentCategory('regular') === ['regular', 'regular_billable', 1]);
$check('internal regular and overtime cannot enter client billing',
    quantaComponentCategory('regular', true) === ['regular', 'regular_nonbillable', 0]
    && quantaComponentCategory('overtime', true) === ['overtime', 'OT_nonbillable', 0]
    && quantaComponentCategory('doubletime', true) === ['doubletime', 'OT_nonbillable', 0]);
$check('dimension ordering has a stable route identity', $entry['dimension_key'] === quantaDimensionKey(['client' => 'c-3', 'project' => 'p-7']));
$context = quantaSourceContext($entry, ['work-1' => ['name' => 'BOC worksite']]);
$check('import context retains source worksite and dimension codes',
    $context['source_worksite_id'] === 'work-1' && $context['source_worksite_name'] === 'BOC worksite'
    && json_decode($context['source_dimension_values_json'], true) === ['client' => 'c-3', 'project' => 'p-7']);
$check('entries without dimensions use a stable empty route', quantaNormalizeEntry(array_diff_key($raw, ['dimension_values' => true]), $site, 'approved')['dimension_key'] === quantaDimensionKey([]));
$check('unclassified duration is blocked', $throws(static fn () => quantaNormalizeEntry([
    'id' => 'q-102', 'worker_id' => 'worker-1', 'work_date' => '2026-09-23',
    'duration_minutes' => 480, 'timesheet_status' => 'approved',
], $site, 'approved')));
$check('PTO with no leave subtype is blocked', $throws(static fn () => quantaNormalizeEntry(
    array_replace($raw, ['pto_hours' => 1, 'reg_hours' => 7, 'ot_hours' => 0]), $site, 'approved'
)));
$pto = array_replace($raw, ['pto_hours' => 1, 'reg_hours' => 7, 'ot_hours' => 0, 'pto_type' => 'sick']);
$check('classified sick time stays distinct', isset(quantaNormalizeEntry($pto, $site, 'approved')['components']['pto_sick']));
$submitted = $raw;
$submitted['timesheet_status'] = 'submitted';
$check('submitted source is not accepted as approved', $throws(static fn () => quantaNormalizeEntry($submitted, $site, 'approved')));
$check('unreported source approval is not inferred from the filter', $throws(static fn () => quantaNormalizeEntry(
    array_diff_key($raw, ['timesheet_status' => true]), $site, 'approved'
)));
$check('source total must equal classified hours', $throws(static fn () => quantaNormalizeEntry(
    array_replace($raw, ['duration_hours' => 9]), $site, 'approved'
)));

$draftForRouting = array_replace($raw, ['timesheet_status' => 'draft']);
$routeDiscovery = quantaRouteCandidates([
    $draftForRouting,
    array_replace($draftForRouting, ['id' => 'q-102', 'clock_in_at' => '2026-09-22T01:00:00Z']),
    ['id' => 'bad-entry'],
], $site);
$check('draft time identifies a placement route without importing hours',
    count($routeDiscovery['rows']) === 1 && $routeDiscovery['rows'][0]['entry_count'] === 2
    && $routeDiscovery['rows'][0]['work_date'] === '2026-09-21' && $routeDiscovery['skipped'] === 1);
$check('draft time remains blocked from approved import', $throws(static fn () => quantaNormalizeEntry($draftForRouting, $site, 'approved')));
$check('provisional person creation rejects missing identity before database access', $throws(static fn () => peopleCreateExternalCandidate(7, [
    'first_name' => 'Example', 'last_name' => '', 'email_primary' => 'invalid', 'status' => 'active',
], 'quanta')));

$quantaRow = [
    'id' => 'entry-approved-1', 'worker_id' => 'worker-1', 'worksite_id' => 'work-1',
    'work_date' => '2026-03-29', 'in_time' => '2026-03-29T08:00:00-07:00',
    'out_time' => '2026-03-29T16:00:00-07:00', 'timezone' => 'America/Los_Angeles',
    'duration_minutes' => 480, 'regular_minutes' => 450, 'overtime_minutes' => 30,
    'doubletime_minutes' => 0, 'pto_minutes' => 0, 'classification' => 'mixed',
    'timesheet_id' => 'sheet-approved-1', 'timesheet_status' => 'approved',
    'dimension_values' => ['department' => 'department-1'], 'updated_at' => '2026-04-05T17:00:00Z',
];
$normalizedQuantaRow = quantaNormalizeEntry($quantaRow, $site, 'approved');
$check('Quanta approved-entry minutes keep regular and overtime distinct',
    $normalizedQuantaRow['components'] === ['regular' => 7.5, 'overtime' => 0.5]
    && $normalizedQuantaRow['work_date'] === '2026-03-29');
$check('Quanta minute breakdown must exactly match source duration', $throws(static fn () => quantaNormalizeEntry(
    array_replace($quantaRow, ['duration_minutes' => 479]), $site, 'approved'
)));
$check('Quanta incomplete minute breakdown is blocked', $throws(static fn () => quantaNormalizeEntry(
    array_diff_key($quantaRow, ['overtime_minutes' => true]), $site, 'approved'
)));
$quantaPto = array_replace($quantaRow, [
    'id' => 'entry-approved-pto', 'worksite_id' => null, 'work_date' => '2026-03-31',
    'duration_minutes' => 480, 'regular_minutes' => 0, 'overtime_minutes' => 0,
    'pto_minutes' => 480, 'classification' => 'pto', 'pto_type' => 'VAC',
    'dimension_values' => [],
]);
$check('Quanta VAC code preserves classified PTO',
    quantaNormalizeEntry($quantaPto, $site, 'approved')['components'] === ['pto_vacation' => 8.0]);
$check('Quanta submitted rows remain blocked in approved source window', $throws(static fn () => quantaNormalizeEntry(
    array_replace($quantaRow, ['timesheet_status' => 'submitted']), $site, 'approved'
)));

$route = [
    'id' => 7, 'worker_id' => 'worker-1', 'worksite_id' => 'work-1', 'placement_id' => 42,
    'dimension_key' => $entry['dimension_key'],
    'person_id' => 19, 'person_name' => 'Example Person', 'effective_from' => '2026-01-01',
    'effective_to' => null, 'placement_status' => 'active', 'start_date' => '2026-01-01',
    'end_date' => null, 'actual_end_date' => null, 'deleted_at' => null,
];
$workerPeople = ['worker-1' => 19];
$check('identity links resolve by immutable source worker ID', quantaWorkerPersonMap([
    ['worker_id' => 'worker-1', 'person_id' => '19'],
]) === $workerPeople);
$check('route resolves worker and worksite by effective date', (int) quantaRouteFor([$route], $entry, $workerPeople)['placement_id'] === 42);
$check('internal classification changes the import fingerprint',
    quantaComponentHash($entry, $route, 'regular', 6.5) !== quantaComponentHash(
        $entry, array_replace($route, ['engagement_type' => 'internal']), 'regular', 6.5
    ));
$check('unlinked worker cannot be routed', $throws(static fn () => quantaRouteFor([$route], $entry, [])));
$check('worker cannot route to another person placement', $throws(static fn () => quantaRouteFor([$route], $entry, ['worker-1' => 20])));
$check('worker/worksite mismatch is not auto-routed', $throws(static fn () => quantaRouteFor([
    array_replace($route, ['worksite_id' => 'other']),
], $entry, $workerPeople)));
$check('dimension mismatch is not auto-routed', $throws(static fn () => quantaRouteFor([
    array_replace($route, ['dimension_key' => quantaDimensionKey(['project' => 'another'])]),
], $entry, $workerPeople)));
$check('overlapping routes block import', $throws(static fn () => quantaRouteFor([$route, $route], $entry, $workerPeople)));
$check('placement dates block out-of-range time', $throws(static fn () => quantaRouteFor([
    array_replace($route, ['end_date' => '2026-09-01']),
], $entry, $workerPeople)));
$routeUntil = array_replace($route, ['end_date' => '2026-09-23', 'effective_to' => '2026-09-23']);
$unroutedLaterWork = quantaRouteCandidates([
    array_replace($raw, ['id' => 'q-covered', 'work_date' => '2026-09-23']),
    array_replace($raw, ['id' => 'q-uncovered', 'work_date' => '2026-09-24']),
], $site, [$routeUntil], $workerPeople);
$check('later work remains visible after an earlier placement route ends',
    count($unroutedLaterWork['rows']) === 1
    && $unroutedLaterWork['rows'][0]['work_date'] === '2026-09-24'
    && $unroutedLaterWork['rows'][0]['entry_count'] === 1);

[$state] = quantaEntryDecision($entry, $route, []);
$check('new source entry is ready', $state === 'ready');
$prior = [];
foreach ($entry['components'] as $component => $hours) {
    $prior[$entry['id']][$component] = [
        'source_hash' => quantaComponentHash($entry, $route, $component, $hours),
        'source_worksite_id' => 'work-1',
        'entry_status' => 'pending_review', 'placement_id' => 42, 'person_id' => 19,
        'work_date' => $entry['work_date'], 'bill_extracted_at' => null,
        'ap_extracted_at' => null, 'payroll_extracted_at' => null,
    ];
}
$check('repeat import is idempotent', quantaEntryDecision($entry, $route, $prior)[0] === 'imported');
$movedWorksite = array_replace($entry, ['worksite_id' => 'work-2']);
$check('worksite change is not hidden by unchanged hours and dimensions',
    quantaComponentHash($movedWorksite, $route, 'regular', 6.5) !== quantaComponentHash($entry, $route, 'regular', 6.5)
    && quantaEntryDecision($movedWorksite, $route, $prior)[0] === 'update');
$legacy = $prior;
foreach ($entry['components'] as $component => $hours) {
    $legacy[$entry['id']][$component]['source_hash'] = quantaLegacyComponentHash($entry, $route, $component, $hours);
    $legacy[$entry['id']][$component]['source_worksite_id'] = null;
}
$check('unapproved legacy import can capture verified source context',
    quantaEntryDecision($entry, $route, $legacy)[0] === 'update');
$legacyApproved = $legacy;
$legacyApproved[$entry['id']]['regular']['entry_status'] = 'approved';
$check('approved legacy import does not silently acquire a guessed worksite',
    quantaEntryDecision($entry, $route, $legacyApproved)[0] === 'conflict');
$internalRoute = array_replace($route, ['engagement_type' => 'internal']);
$check('internal reclassification updates only unapproved imported time',
    quantaEntryDecision($entry, $internalRoute, $prior)[0] === 'update');
$revised = $entry;
$revised['components']['regular'] = 6.25;
$check('changed unapproved source can update', quantaEntryDecision($revised, $route, $prior)[0] === 'update');
$approved = $prior;
$approved[$entry['id']]['regular']['entry_status'] = 'approved';
$check('changed approved source is blocked', quantaEntryDecision($revised, $route, $approved)[0] === 'conflict');
$check('approved source cannot silently move worksites',
    quantaEntryDecision($movedWorksite, $route, $approved)[0] === 'conflict');
$check('approved imported time cannot silently become non-billable',
    quantaEntryDecision($entry, $internalRoute, $approved)[0] === 'conflict');
$removed = $entry;
unset($removed['components']['overtime']);
$check('removed hour component is blocked for correction', quantaEntryDecision($removed, $route, $prior)[0] === 'conflict');

$root = dirname(__DIR__);
$api = (string) file_get_contents($root . '/api/quanta.php');
$migration = (string) file_get_contents($root . '/core/migrations/147_quanta_time_integration.sql');
$identityMigration = (string) file_get_contents($root . '/core/migrations/148_quanta_worker_identity.sql');
$contextMigration = (string) file_get_contents($root . '/core/migrations/149_quanta_time_source_context.sql');
$ui = (string) file_get_contents($root . '/dashboard/src/pages/QuantaSettings.jsx');
$check('connection and import are tenant-admin gated', str_contains($api, 'integrations.quanta.manage')
    && str_contains($api, 'confirm_tenant_id'));
$check('time access is checked separately from catalog connection', str_contains($api, "\$action === 'time_access'")
    && str_contains($ui, 'timeAccess?.available === false') && str_contains($ui, 'disabled={!!busy || !timeAccess?.available}'));
$check('source component and CoreFlux row IDs have unique fences', str_contains($migration, 'uq_quanta_import_source (tenant_id, quanta_entry_id, component)')
    && str_contains($migration, 'uq_quanta_import_entry (tenant_id, time_entry_id)'));
$check('worker and person links are one-to-one per workspace', str_contains($identityMigration, 'uq_quanta_worker_identity (tenant_id, worker_id)')
    && str_contains($identityMigration, 'uq_quanta_person_identity (tenant_id, person_id)'));
$check('imports retain source worker identity for guarded unlink', str_contains($identityMigration, 'ADD COLUMN worker_id')
    && str_contains((string) file_get_contents($root . '/core/quanta/sync.php'), "'worker_id' => \$entry['worker_id']"));
$check('imported time preserves the Quanta source context for timesheet review',
    str_contains($contextMigration, 'source_worksite_id')
    && str_contains($contextMigration, 'source_dimension_values_json')
    && str_contains((string) file_get_contents($root . '/modules/staffing/api/timesheets.php'), 'qi.source_dimension_values_json')
    && str_contains((string) file_get_contents($root . '/modules/staffing/ui/TimesheetDetail.jsx'), 'sourceDimensions(e.source_dimension_values_json)'));
$check('page exposes preview, explicit mapping, and selected import', str_contains($ui, 'save_routes')
    && str_contains($ui, 'save_worker_links') && str_contains($ui, 'Import {selected.length} selected') && str_contains($ui, 'Preview time'));
$check('new person requires people permission and is linked in one transaction',
    str_contains($api, "\$action === 'create_worker_person'") && str_contains($api, "'people.manage'")
    && str_contains($api, 'peopleCreateExternalCandidate') && str_contains($api, 'cf_tx_commit($pdo, $owns)'));
$check('provisional people do not guess employment type or activate a placement',
    str_contains((string) file_get_contents($root . '/modules/people/lib/people.php'), '"candidate", :status, "unknown"')
    && str_contains($api, 'must be activated before routing time'));
$check('placement creation from Quanta requires reviewed type and date',
    str_contains((string) file_get_contents($root . '/modules/placements/ui/PlacementCreate.jsx'), "engagement_type: fromQuanta ? '' : 'w2'")
    && str_contains($ui, 'Create and link') && str_contains($ui, 'Find work to route'));
$placementCreateApi = (string) file_get_contents($root . '/modules/placements/api/placements.php');
$check('placement creation preserves the shared database connection',
    str_contains($placementCreateApi, '$createTransactionPdo = null;')
    && !str_contains($placementCreateApi, '$pdo = null;'));
$check('new route defaults to uncovered work and ends with the selected placement',
    str_contains($ui, 'effective_from: routeStartDrafts[row.routeKey] || row.work_date')
    && str_contains($ui, 'effective_to: placement?.end_date || null'));
$check('integration hub links Quanta', str_contains((string) file_get_contents($root . '/dashboard/src/pages/IntegrationsHub.jsx'), 'integration-card-quanta'));

require_once $root . '/scripts/quanta_reclassify_connecteam_soie.php';
$correctionWorkers = [
    ['id' => 'alice-id', 'full_name' => 'Alice Mesonzhnik'],
    ['id' => 'erica-id', 'first_name' => 'Erica', 'last_name' => 'Maye'],
];
$correctionSites = [
    ['id' => 'soie-id', 'code' => 'CT-SOIE'],
    ['id' => 'boc-id', 'code' => 'CT-BOC'],
    ['id' => 'internal-id', 'code' => 'CT-INTERNAL'],
];
$correctionEntries = [['id' => 'alice-entry', 'worker_id' => 'alice-id', 'worksite_id' => 'soie-id',
    'duration_minutes' => 180, 'timesheet_status' => 'draft',
    'dimension_values' => ['client' => 'SOIE', 'department' => 'BOC', 'task' => 'review']]];
for ($i = 0; $i < 25; $i++) {
    $correctionEntries[] = ['id' => 'erica-' . $i, 'worker_id' => 'erica-id', 'worksite_id' => 'soie-id',
        'duration_minutes' => 216, 'timesheet_status' => 'draft',
        'dimension_values' => ['client' => 'SOIE', 'department' => 'Internal']];
}
$correctionPlan = quantaSoiePlan($correctionWorkers, $correctionSites, $correctionEntries);
$check('SOIE correction preserves other dimensions and targets exactly 26 known entries',
    count($correctionPlan) === 26
    && $correctionPlan[0]['worksite_id'] === 'boc-id'
    && $correctionPlan[0]['dimension_values'] === ['client' => 'BOC_BOC_CAPITAL', 'department' => 'BOC', 'task' => 'review']
    && $correctionPlan[25]['worksite_id'] === 'internal-id');
$correctionDrift = $correctionEntries;
$correctionDrift[1]['duration_minutes'] = 215;
try { quantaSoiePlan($correctionWorkers, $correctionSites, $correctionDrift); $rejectedDrift = false; }
catch (RuntimeException $e) { $rejectedDrift = true; }
$check('SOIE correction refuses unexpected source hour totals', $rejectedDrift);

echo "Quanta integration: {$passed} passed, " . count($failed) . " failed\n";
exit($failed ? 1 : 0);
