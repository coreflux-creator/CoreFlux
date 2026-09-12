<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$placementApi = (string) file_get_contents($root . '/modules/placements/api/documents.php');
$peopleApi = (string) file_get_contents($root . '/modules/people/api/documents.php');
$placementUi = (string) file_get_contents($root . '/modules/placements/ui/PlacementDetail.jsx');
$peopleUi = (string) file_get_contents($root . '/modules/people/ui/PersonDetail.jsx');
$storage = (string) file_get_contents($root . '/core/storage_register.php');

$checks = [
    'placement documents register the storage key returned to the browser' =>
        str_contains($placementApi, "api_require_fields(\$body, ['storage_key', 'filename'])")
        && str_contains($placementApi, 'registerStorageObject('),
    'people documents register the storage key returned to the browser' =>
        str_contains($peopleApi, "api_require_fields(\$body, ['storage_key', 'filename'])")
        && str_contains($peopleApi, 'registerStorageObject('),
    'document uploads validate the owning tenant and entity path' =>
        str_contains($placementApi, '$requirePlacement($pid)')
        && str_contains($placementApi, '"placements/{$tid}/document/{$pid}/"')
        && str_contains($peopleApi, '$requirePerson($personId)')
        && str_contains($peopleApi, '"people/{$tid}/document/{$personId}/"'),
    'storage idempotency lookup is tenant scoped' =>
        str_contains($storage, 'WHERE tenant_id = :t AND s3_key = :k'),
    'placement detail exposes upload and open controls' =>
        str_contains($placementUi, 'data-testid="placement-document-upload"')
        && str_contains($placementUi, 'openDocument(d.id)'),
    'person detail exposes upload and open controls' =>
        str_contains($peopleUi, 'data-testid="person-document-upload"')
        && str_contains($peopleUi, 'openDocument(d.id)'),
    'person and placement details have primary headings' =>
        str_contains($placementUi, '<h1 data-testid="placement-detail-title"')
        && str_contains($peopleUi, '<h1 data-testid="person-detail-name"'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
