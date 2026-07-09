<?php
/**
 * Smoke - JobDiva placement engagement inference.
 *
 * Guards against stale imported defaults where every placement remains w2
 * even though JobDiva carries C2C / 1099 evidence in assignment facets.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/jobdiva/sync.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ok {$msg}\n"; $pass++; }
    else { echo "  x {$msg}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. Normalizer can test without guessing w2\n";
$a('unknown value with empty fallback returns empty string',
    jobdivaNormalisePlacementEngagementType('not a classification', '') === '');
$a('unknown value with default fallback still returns w2',
    jobdivaNormalisePlacementEngagementType('not a classification') === 'w2');

echo "\n2. C2C evidence overrides stale w2 fallback\n";
$a('assignment crop_to_crop flag Y -> c2c',
    jobdivaInferPlacementEngagementTypeFromPayload([
        'assignment' => ['crop_to_crop' => 'Y'],
    ], 'w2') === 'c2c');
$a('_jd_start corp-to-corp text -> c2c',
    jobdivaInferPlacementEngagementTypeFromPayload([
        '_jd_start' => ['position type' => 'Corp to Corp'],
    ], 'w2') === 'c2c');
$a('observed typo crop to crop text -> c2c',
    jobdivaInferPlacementEngagementTypeFromPayload([
        'assignment' => ['position_type' => 'crop to crop'],
    ], 'w2') === 'c2c');

echo "\n3. Other classifications still resolve\n";
$a('workerType 1099 -> 1099',
    jobdivaInferPlacementEngagementTypeFromPayload(['workerType' => '1099'], 'w2') === '1099');
$a('hireType Direct Hire -> direct_hire',
    jobdivaInferPlacementEngagementTypeFromPayload(['hireType' => 'Direct Hire'], 'w2') === 'direct_hire');
$a('contractType Contract to Hire -> temp_to_perm',
    jobdivaInferPlacementEngagementTypeFromPayload(['contractType' => 'Contract to Hire'], 'w2') === 'temp_to_perm');

echo "\n4. Negative/absent source does not fabricate C2C\n";
$a('crop_to_crop N keeps fallback w2',
    jobdivaInferPlacementEngagementTypeFromPayload(['crop_to_crop' => 'N'], 'w2') === 'w2');
$a('silent payload preserves non-w2 fallback',
    jobdivaInferPlacementEngagementTypeFromPayload(['title' => 'Analyst'], 'c2c') === 'c2c');
$a('silent payload with empty fallback stays empty',
    jobdivaInferPlacementEngagementTypeFromPayload(['title' => 'Analyst'], '') === '');

echo "\nJobDiva engagement inference smoke: {$pass} ok / {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
