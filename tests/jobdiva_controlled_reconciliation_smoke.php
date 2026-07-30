<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/CsvImportService.php';
require_once __DIR__ . '/../modules/placements/lib/jobdiva_reconciliation.php';

use Core\CsvImportService;

$passed = 0;
$failed = 0;
$assert = static function (string $label, bool $condition) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL {$label}\n";
};

CsvImportService::registerSchema(
    'jobdiva_placement_reconciliation',
    jobdivaReconciliationSchema()
);

echo "Controlled JobDiva reconciliation normalization\n";
$assert('epoch milliseconds become a date',
    jobdivaReconciliationNormaliseDate('1783350000000') === '2026-07-06');
$assert('C2C normalizes without a transform',
    jobdivaReconciliationNormaliseEngagement('Corp-to-Corp') === 'c2c');
$assert('W2 normalizes without a transform',
    jobdivaReconciliationNormaliseEngagement('W-2 Employee') === 'w2');
$assert('truthy C2C flag uses the source header',
    jobdivaReconciliationNormaliseEngagement('1', 'CROP_TO_CROP') === 'c2c');
$assert('truthy W2 flag uses the source header',
    jobdivaReconciliationNormaliseEngagement('yes', 'TCS_W2') === 'w2');
$assert('unknown classification does not default to W2',
    jobdivaReconciliationNormaliseEngagement('mystery') === null);
$assert('currency-formatted rates normalize',
    jobdivaReconciliationNormaliseNumber('$1,234.50 / hour') === 1234.5);
$assert('scheduled statuses normalize to pending_start',
    jobdivaReconciliationNormaliseStatus('Scheduled Start') === 'pending_start');
$assert('payment terms normalize to canonical values',
    jobdivaReconciliationNormaliseTerms('Paid when paid - net 60') === 'PWP_NET60');

echo "\nCSV inspection and exact Start identity\n";
$csv = implode("\n", [
    'Start ID,Candidate Email,Position Type,Start Date,Final Bill Rate,Agreed Pay Rate',
    '00057219188,worker@example.com,Corp-to-Corp,1783350000000,$119.00,$82.00',
]);
$inspection = jobdivaReconciliationInspect($csv);
$assert('Start ID header is auto-mapped',
    ($inspection['auto_map'][0] ?? null) === 'start_id');
$assert('rate headers are auto-mapped',
    ($inspection['auto_map'][4] ?? null) === 'bill_rate'
    && ($inspection['auto_map'][5] ?? null) === 'pay_rate');
$parsed = jobdivaReconciliationParse($csv, $inspection['auto_map']);
$first = array_values($parsed['rows'])[0] ?? [];
$assert('opaque Start ID keeps leading zeroes',
    ($first['start_id'] ?? null) === '00057219188');
$assert('classification is canonical',
    ($first['engagement_type'] ?? null) === 'c2c');
$assert('rates remain distinct',
    ($first['bill_rate'] ?? null) === 119.0 && ($first['pay_rate'] ?? null) === 82.0);
$assert('valid sample has no parse errors', $parsed['error_count'] === 0);

$duplicateCsv = implode("\n", [
    'Start ID,Title',
    '57219188,Engineer',
    '57219188,Engineer',
]);
$duplicateInspection = jobdivaReconciliationInspect($duplicateCsv);
$duplicate = jobdivaReconciliationParse($duplicateCsv, $duplicateInspection['auto_map']);
$assert('duplicate Start IDs are blocked before DB matching', $duplicate['error_count'] === 1);

$canonicalDuplicateCsv = implode("\n", [
    'Start ID,Title',
    'jd:57219188,Engineer',
    '57219188,Engineer',
]);
$canonicalDuplicateInspection = jobdivaReconciliationInspect($canonicalDuplicateCsv);
$canonicalDuplicate = jobdivaReconciliationParse(
    $canonicalDuplicateCsv,
    $canonicalDuplicateInspection['auto_map']
);
$assert('canonical duplicate Start IDs block both rows',
    count($canonicalDuplicate['errors']) === 2);

$scientificCsv = implode("\n", [
    'Start ID,Title',
    '5.7219188E+7,Engineer',
]);
$scientificInspection = jobdivaReconciliationInspect($scientificCsv);
$scientific = jobdivaReconciliationParse($scientificCsv, $scientificInspection['auto_map']);
$assert('scientific-notation Start IDs are blocked', $scientific['error_count'] === 1);

echo "\nGuarded API and UI contract\n";
$api = (string) file_get_contents(__DIR__ . '/../modules/placements/api/jobdiva_reconciliation.php');
$lib = (string) file_get_contents(__DIR__ . '/../modules/placements/lib/jobdiva_reconciliation.php');
$ui = (string) file_get_contents(__DIR__ . '/../modules/placements/ui/JobDivaReconciliation.jsx');
$routes = (string) file_get_contents(__DIR__ . '/../modules/placements/ui/PlacementsModule.jsx');
$assert('apply requires exact preview token', str_contains($api, 'hash_equals'));
$assert('apply requires explicit confirmation phrase',
    str_contains($api, 'APPLY_EXACT_START_ID_RECONCILIATION'));
$assert('selected writes are transactional',
    str_contains($api, 'beginTransaction') && str_contains($api, 'rollBack'));
$assert('approved rates are never updated',
    str_contains($api, 'approved_at IS NULL'));
$assert('economic graph reconciliation is required',
    str_contains($api, 'placementEconomicsReconcile'));
$assert('identity lookup uses only mapping/external Start IDs',
    str_contains($lib, 'jobdivaReconciliationFetchExactPlacement')
    && !str_contains($lib, 'person_id + title + start_date'));
$assert('UI starts with no selected rows', str_contains($ui, 'setSelected(new Set())'));
$assert('UI advertises non-destructive behavior',
    str_contains($ui, 'No fuzzy matching') && str_contains($ui, 'No delete or archive'));
$assert('placement route is registered',
    str_contains($routes, 'path="jobdiva-reconciliation"'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
