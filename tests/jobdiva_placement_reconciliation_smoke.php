<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/jobdiva/placement_reconciliation.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "PASS" : "FAIL") . "  {$label}\n";
    if (!$ok) $failures++;
};

$source = [
    'person_id' => 225,
    'is_jobdiva_mapping' => true,
    'engagement_type' => 'w2',
    'status' => 'active',
    'start_date' => '2026-06-07',
    'end_client_name' => 'Rockefeller Capital Management (India)',
];
$legacy = [
    'person_id' => 225,
    'engagement_type' => 'w2',
    'status' => 'active',
    'start_date' => '2026-07-06',
    'end_client_name' => 'Rockefeller',
    'notes' => 'Imported from Placements.xlsx; source=Active Billables row 43.',
];
$match = jobdivaPlacementReconcilePair($source, $legacy);
$check('month/day swap and client alias are recognized',
    ($match['date_match'] ?? null) === 'month_day_swap'
    && ($match['client_match'] ?? null) === 'name_prefix_alias');

$adjacent = $legacy;
$adjacent['start_date'] = '2026-06-08';
$check('adjacent dates are recognized',
    (jobdivaPlacementReconcilePair($source, $adjacent)['date_match'] ?? null) === 'adjacent_day');

$exact = $legacy;
$exact['start_date'] = '2026-06-07';
$exact['end_client_name'] = 'Rockefeller Capital Management (India)';
$check('exact date and client are recognized',
    (jobdivaPlacementReconcilePair($source, $exact)['client_match'] ?? null) === 'exact_name');

$unrelated = $legacy;
$unrelated['start_date'] = '2026-01-15';
$check('separate engagements are rejected', jobdivaPlacementReconcilePair($source, $unrelated) === null);

$otherClient = $legacy;
$otherClient['end_client_name'] = 'Rockefeller University';
$check('shared first word without a safe prefix is rejected',
    jobdivaPlacementReconcilePair($source, $otherClient) === null);

$otherType = $legacy;
$otherType['engagement_type'] = 'c2c';
$check('different worker classifications are rejected',
    jobdivaPlacementReconcilePair($source, $otherType) === null);

$sourceWithId = $source;
$sourceWithId['id'] = 1;
$legacyWithId = $exact;
$legacyWithId['id'] = 2;
$ambiguousLegacy = $exact;
$ambiguousLegacy['id'] = 3;
$check('ambiguous duplicate candidates are left for review',
    jobdivaPlacementReconcileUniquePairs(
        [$sourceWithId],
        [$legacyWithId, $ambiguousLegacy]
    ) === []
);

$adjacentLegacy = $adjacent;
$adjacentLegacy['id'] = 3;
$best = jobdivaPlacementReconcileUniquePairs(
    [$sourceWithId],
    [$legacyWithId, $adjacentLegacy]
);
$check('a unique stronger match wins over a weaker candidate',
    count($best) === 1 && (int) ($best[0]['legacy']['id'] ?? 0) === 2
);

exit($failures === 0 ? 0 : 1);
