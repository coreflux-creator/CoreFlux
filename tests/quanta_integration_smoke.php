<?php
/** Contract and safety smoke for the dormant Quanta time connector. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/quanta/sync.php';

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
        return ['status' => 200, 'body' => json_encode(['items' => [['id' => 'second']], 'next_cursor' => null, 'has_more' => false])];
    }
    return ['status' => 200, 'body' => json_encode(['items' => [['id' => 'first']], 'next_cursor' => 'next', 'has_more' => true])];
};
$entries = quantaListAll('fixture-key', '/time-entries');
$check('cursor pagination collects complete list', array_column($entries, 'id') === ['first', 'second']);
$check('read-only requests target the documented host', count($calls) === 2 && $calls[0][0] === 'GET'
    && str_starts_with($calls[0][1], 'https://helloquanta.app/api/v1/time-entries'));
$check('key goes in bearer header, not URL', str_contains(implode(' ', $calls[0][2]), 'Bearer fixture-key')
    && !str_contains($calls[0][1], 'fixture-key'));
$check('arbitrary API host/path is rejected', $throws(static fn () => quantaGet('fixture-key', '/../../../evil')));
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
$check('dimension ordering has a stable route identity', $entry['dimension_key'] === quantaDimensionKey(['client' => 'c-3', 'project' => 'p-7']));
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
$check('submitted source is allowed only in review mode', $throws(static fn () => quantaNormalizeEntry($submitted, $site, 'approved'))
    && quantaNormalizeEntry($submitted, $site, 'submitted,approved')['source_status'] === 'submitted');
$check('source total must equal classified hours', $throws(static fn () => quantaNormalizeEntry(
    array_replace($raw, ['duration_hours' => 9]), $site, 'approved'
)));

$route = [
    'id' => 7, 'worker_id' => 'worker-1', 'worksite_id' => 'work-1', 'placement_id' => 42,
    'dimension_key' => $entry['dimension_key'],
    'person_id' => 19, 'person_name' => 'Example Person', 'effective_from' => '2026-01-01',
    'effective_to' => null, 'placement_status' => 'active', 'start_date' => '2026-01-01',
    'end_date' => null, 'actual_end_date' => null, 'deleted_at' => null,
];
$check('route resolves worker and worksite by effective date', (int) quantaRouteFor([$route], $entry)['placement_id'] === 42);
$check('worker/worksite mismatch is not auto-routed', $throws(static fn () => quantaRouteFor([
    array_replace($route, ['worksite_id' => 'other']),
], $entry)));
$check('dimension mismatch is not auto-routed', $throws(static fn () => quantaRouteFor([
    array_replace($route, ['dimension_key' => quantaDimensionKey(['project' => 'another'])]),
], $entry)));
$check('overlapping routes block import', $throws(static fn () => quantaRouteFor([$route, $route], $entry)));
$check('placement dates block out-of-range time', $throws(static fn () => quantaRouteFor([
    array_replace($route, ['end_date' => '2026-09-01']),
], $entry)));

[$state] = quantaEntryDecision($entry, $route, []);
$check('new source entry is ready', $state === 'ready');
$prior = [];
foreach ($entry['components'] as $component => $hours) {
    $prior[$entry['id']][$component] = [
        'source_hash' => quantaComponentHash($entry, $route, $component, $hours),
        'entry_status' => 'pending_review', 'placement_id' => 42, 'person_id' => 19,
        'work_date' => $entry['work_date'], 'bill_extracted_at' => null,
        'ap_extracted_at' => null, 'payroll_extracted_at' => null,
    ];
}
$check('repeat import is idempotent', quantaEntryDecision($entry, $route, $prior)[0] === 'imported');
$revised = $entry;
$revised['components']['regular'] = 6.25;
$check('changed unapproved source can update', quantaEntryDecision($revised, $route, $prior)[0] === 'update');
$approved = $prior;
$approved[$entry['id']]['regular']['entry_status'] = 'approved';
$check('changed approved source is blocked', quantaEntryDecision($revised, $route, $approved)[0] === 'conflict');
$removed = $entry;
unset($removed['components']['overtime']);
$check('removed hour component is blocked for correction', quantaEntryDecision($removed, $route, $prior)[0] === 'conflict');

$root = dirname(__DIR__);
$api = (string) file_get_contents($root . '/api/quanta.php');
$migration = (string) file_get_contents($root . '/core/migrations/147_quanta_time_integration.sql');
$ui = (string) file_get_contents($root . '/dashboard/src/pages/QuantaSettings.jsx');
$check('connection and import are tenant-admin gated', str_contains($api, 'integrations.quanta.manage')
    && str_contains($api, 'confirm_tenant_id'));
$check('source component and CoreFlux row IDs have unique fences', str_contains($migration, 'uq_quanta_import_source (tenant_id, quanta_entry_id, component)')
    && str_contains($migration, 'uq_quanta_import_entry (tenant_id, time_entry_id)'));
$check('page exposes preview, explicit mapping, and selected import', str_contains($ui, 'save_routes')
    && str_contains($ui, 'Import {selected.length} selected') && str_contains($ui, 'Preview time'));
$check('integration hub links Quanta', str_contains((string) file_get_contents($root . '/dashboard/src/pages/IntegrationsHub.jsx'), 'integration-card-quanta'));

echo "Quanta integration: {$passed} passed, " . count($failed) . " failed\n";
exit($failed ? 1 : 0);
