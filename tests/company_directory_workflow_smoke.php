<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = (string) file_get_contents($root . '/modules/people/api/companies.php');
$lib = (string) file_get_contents($root . '/modules/people/lib/companies.php');
$ui = (string) file_get_contents($root . '/modules/people/ui/DirectoryModule.jsx');

$duplicatesAt = strpos($api, "if (\$method === 'GET' && \$action === 'duplicates')");
$listAt = strpos($api, "if (\$method === 'GET')");
$addressPatchAt = strpos($api, "if (\$method === 'PATCH' && \$action === 'address')");
$genericPatchAt = strpos($api, "if (\$method === 'PATCH')");
$addressDeleteAt = strpos($api, "if (\$method === 'DELETE' && \$action === 'address')");
$genericDeleteAt = strpos($api, "if (\$method === 'DELETE')");

$checks = [
    'duplicate candidates route is reachable before the generic list route' =>
        $duplicatesAt !== false && $listAt !== false && $duplicatesAt < $listAt,
    'address edit route is reachable before the generic company patch route' =>
        $addressPatchAt !== false && $genericPatchAt !== false && $addressPatchAt < $genericPatchAt,
    'address delete route is reachable before the generic company delete route' =>
        $addressDeleteAt !== false && $genericDeleteAt !== false && $addressDeleteAt < $genericDeleteAt,
    'company role and contact writes validate tenant ownership' =>
        substr_count($api, '$requireCompany($id)') >= 5,
    'company list supports a server-side role set' =>
        str_contains($api, "'roles'    => \$_GET['roles']")
        && str_contains($lib, "cr.role IN (' . implode(',', \$roleParams) . ')"),
    'client and vendor lists expose status filters sorting and pagination' =>
        str_contains($ui, 'data-testid={`${mode}-status-filter`}')
        && str_contains($ui, "sortProps('name')")
        && str_contains($ui, 'Page {page} of {totalPages}'),
    'client and vendor lists expose selection and shared bulk editing' =>
        str_contains($ui, 'data-testid={`${mode}-select-all`}')
        && str_contains($ui, '<BulkEditBar')
        && str_contains($api, "\$action === 'bulk_update'"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
