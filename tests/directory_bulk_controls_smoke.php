<?php
/**
 * Source-level contract for reusable directory controls and audited bulk edits.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $message, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { echo "  OK {$message}\n"; $pass++; }
    else { echo "  FAIL {$message}\n"; $fail++; }
};

$root = dirname(__DIR__);
$bulk = (string) file_get_contents($root . '/dashboard/src/components/BulkEditBar.jsx');
$placementUi = (string) file_get_contents($root . '/modules/placements/ui/List.jsx');
$placementApi = (string) file_get_contents($root . '/modules/placements/api/placements.php');
$placementLib = (string) file_get_contents($root . '/modules/placements/lib/placements.php');
$clientUi = (string) file_get_contents($root . '/modules/staffing/ui/Clients.jsx');
$clientApi = (string) file_get_contents($root . '/modules/staffing/api/clients.php');
$clientLib = (string) file_get_contents($root . '/modules/staffing/lib/clients.php');

echo "\n1. Shared selection editor\n";
$assert('renders selected count, field, value, apply, and clear controls',
    str_contains($bulk, '${testid}-selected-count')
    && str_contains($bulk, '${testid}-field')
    && str_contains($bulk, '${testid}-value')
    && str_contains($bulk, '${testid}-apply')
    && str_contains($bulk, '${testid}-clear'));
$assert('uses familiar selection and clear icons',
    str_contains($bulk, 'CheckSquare') && str_contains($bulk, '<X'));
$assert('only appears after at least one row is selected',
    str_contains($bulk, 'if (count <= 0 || !field) return null'));

echo "\n2. Placement directory\n";
$assert('selects rows on every status view',
    str_contains($placementUi, 'data-testid="placements-bulk-select-all"')
    && str_contains($placementUi, 'data-testid={`placement-row-select-${p.id}`}')
    && !str_contains($placementUi, 'isDraftView &&'));
$assert('bulk edits status and non-status operational fields',
    str_contains($placementUi, "field === 'status'")
    && str_contains($placementUi, "action=bulk_status")
    && str_contains($placementUi, "action=bulk_update"));
$assert('person, client, and edit actions navigate to their real records',
    str_contains($placementUi, 'to={`/modules/people/${p.person_id}`}')
    && str_contains($placementUi, 'to={`/modules/staffing/clients?client_id=${p.client_id}`}')
    && str_contains($placementUi, 'data-testid={`placement-edit-${p.id}`}'));
$assert('whole-row search includes people, email, client, source id, status, type, and worksite',
    str_contains($placementLib, 'CONCAT_WS')
    && str_contains($placementLib, 'pe.email_primary LIKE')
    && str_contains($placementLib, 'COALESCE(ec.name, p.end_client_name) LIKE')
    && str_contains($placementLib, 'p.external_id LIKE')
    && str_contains($placementLib, 'p.engagement_type LIKE')
    && str_contains($placementLib, 'p.worksite_state LIKE'));
$assert('client drill-through filter is accepted by list API',
    str_contains($placementApi, "'end_client_company_id' => \$_GET['end_client_company_id']")
    && str_contains($placementLib, 'p.end_client_company_id = :end_client_company_id'));
$assert('bulk_update is permissioned, capped, audited, and reconciles economics',
    (bool) preg_match("/action === 'bulk_update'.*?placements\.manage/s", $placementApi)
    && str_contains($placementApi, 'Too many ids (max 500 per call)')
    && str_contains($placementApi, "'via' => 'bulk_update'")
    && str_contains($placementApi, 'placementEconomicsReconcile'));
$assert('bulk edits preserve explicit CoreFlux overrides for JobDiva rows',
    str_contains($placementApi, 'coreflux_overridden_fields')
    && str_contains($placementApi, "str_starts_with((string) (\$existing['external_id'] ?? ''), 'jd:')"));

echo "\n3. Client directory\n";
$assert('supports whole-row search and source filtering',
    str_contains($clientApi, 'c.industry LIKE')
    && str_contains($clientApi, 'c.primary_contact_name LIKE')
    && str_contains($clientApi, 'c.primary_contact_phone LIKE')
    && str_contains($clientApi, "\$source === 'placement'")
    && str_contains($clientApi, "\$source === 'accounting'"));
$assert('selects clients and uses the shared bulk editor',
    str_contains($clientUi, 'data-testid="staffing-clients-select-all"')
    && str_contains($clientUi, 'data-testid={`staffing-client-select-${r.id}`}')
    && str_contains($clientUi, '<BulkEditBar'));
$assert('client names, edit actions, and placement counts are interactive',
    str_contains($clientUi, 'data-testid={`staffing-client-open-${r.id}`}')
    && str_contains($clientUi, 'data-testid={`staffing-client-edit-${r.id}`}')
    && str_contains($clientUi, '/modules/placements/list?status=active&end_client_company_id='));
$assert('bulk client updates are permissioned, validated, and audited',
    (bool) preg_match("/action === 'bulk_update'.*?placements\.manage/s", $clientApi)
    && str_contains($clientApi, "['status','payment_terms_days','industry','msa_status']")
    && str_contains($clientApi, "'source' => 'bulk_update'"));
$assert('bulk close cannot strand active placements',
    str_contains($clientApi, 'Client has active placements'));
$assert('payment terms stay aligned with the canonical company',
    str_contains($clientLib, "'payment_terms_days' => 'payment_terms_days'"));

echo "\n4. PHP syntax\n";
foreach ([
    $root . '/modules/placements/api/placements.php',
    $root . '/modules/placements/lib/placements.php',
    $root . '/modules/staffing/api/clients.php',
    $root . '/modules/staffing/lib/clients.php',
] as $file) {
    $output = []; $code = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    $assert('php -l ' . basename($file), $code === 0);
}

echo "\nDirectory bulk controls smoke: {$pass} passed / {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
