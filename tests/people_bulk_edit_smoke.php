<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$ui = (string) file_get_contents($root . '/modules/people/ui/Directory.jsx');
$api = (string) file_get_contents($root . '/modules/people/api/people.php');

$checks = [
    'people directory exposes select-all and row selection' =>
        str_contains($ui, 'people-bulk-select-all')
        && str_contains($ui, 'people-row-select-${p.id}'),
    'people directory uses the shared bulk editor' =>
        str_contains($ui, '<BulkEditBar')
        && str_contains($ui, 'testid="people-bulk"'),
    'people bulk editor covers common operational fields' =>
        str_contains($ui, "key: 'status'")
        && str_contains($ui, "key: 'classification'")
        && str_contains($ui, "key: 'work_auth_status'")
        && str_contains($ui, "key: 'pay_frequency'"),
    'people API limits and validates bulk updates' =>
        str_contains($api, 'if ($action === \'bulk_update\')')
        && str_contains($api, 'Too many ids (max 500 per call)')
        && str_contains($api, 'rbac_legacy_require($user, \'people.manage\')'),
    'people bulk updates remain tenant scoped and audited' =>
        str_contains($api, 'scopedUpdate(\'people\', $id, [$field => $value])')
        && str_contains($api, '\'source\' => \'bulk_update\''),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
