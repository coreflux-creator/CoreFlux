<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$integrations = (string) file_get_contents($root . '/api/admin/integrations_health.php');
$layerCard = (string) file_get_contents($root . '/dashboard/src/pages/LayerFiToggleCard.jsx');
$graphql = (string) file_get_contents($root . '/dashboard/src/pages/GraphqlSandbox.jsx');

$checks = [
    'integration health authenticates before checking the admin role' =>
        str_contains($integrations, '$ctx = api_require_auth();')
        && !str_contains($integrations, '$currentUser ?? []'),
    'disabled LayerFi feature does not render a Not found error card' =>
        str_contains($layerCard, 'e.status === 404')
        && str_contains($layerCard, 'layerfi-unavailable-card')
        && str_contains($layerCard, 'Not enabled in this environment.'),
    'GraphQL endpoint probe has a bounded timeout' =>
        str_contains($graphql, 'timedOut = true;')
        && str_contains($graphql, 'ctrl.abort();')
        && str_contains($graphql, 'did not respond within 8 seconds'),
    'GraphQL instructions do not tell users to extract browser cookies' =>
        !str_contains($graphql, 'Application → Cookies'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
