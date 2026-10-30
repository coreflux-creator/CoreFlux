<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/staffing/lib/dimension_snapshot.php';

$snapshot = [
    'dimensions' => ['worker' => 4, 'client' => 12],
    'engagement_type' => 'w2',
    'vendor_dimension' => null,
];
$databaseForm = [
    'vendor_dimension' => null,
    'engagement_type' => 'w2',
    'dimensions' => ['client' => 12, 'worker' => 4],
];
$hash = hash('sha256', staffingDimensionSnapshotCanonicalJson($snapshot));
if ($hash !== hash('sha256', staffingDimensionSnapshotCanonicalJson($databaseForm))) {
    fwrite(STDERR, "Snapshot hash depends on JSON key order\n");
    exit(1);
}
$databaseForm['dimensions']['worker'] = 5;
if ($hash === hash('sha256', staffingDimensionSnapshotCanonicalJson($databaseForm))) {
    fwrite(STDERR, "Snapshot hash did not detect changed dimensions\n");
    exit(1);
}
echo "staffing dimension snapshot smoke: ok\n";
