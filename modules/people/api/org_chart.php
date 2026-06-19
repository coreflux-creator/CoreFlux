<?php
/**
 * People — Org Chart
 * Returns a tree built from manager_id relationships.
 * GET ?root_employee_id=N  returns subtree rooted at that employee
 * GET                      returns the full tenant tree (forest — list of roots)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/employees.php';

$ctx = api_require_auth();
$user = $ctx['user'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'people.view');

$rows = scopedQuery(
    "SELECT id, legal_first_name, preferred_name, legal_last_name, job_title,
            department, location, manager_id, photo_url, employment_type, flsa_class
     FROM people_employees
     WHERE tenant_id = :tenant_id AND status IN ('active','on_leave')
     ORDER BY legal_last_name, legal_first_name"
);

// Build a parent→children index
$byId = [];
$childrenOf = [];
foreach ($rows as $r) {
    $node = $r;
    $node['children'] = [];
    $byId[$r['id']] = $node;
    $childrenOf[$r['manager_id'] ?? 0][] = $r['id'];
}

$root = (int) (api_query('root_employee_id') ?? 0);
if ($root > 0) {
    if (!isset($byId[$root])) api_error('Root not found', 404);
    api_ok(['tree' => _buildSubtree($byId, $childrenOf, $root)]);
}

// Roots = anyone with no manager OR manager outside the active set
$rootIds = $childrenOf[0] ?? [];
foreach ($rows as $r) {
    if ($r['manager_id'] !== null && !isset($byId[$r['manager_id']])) {
        $rootIds[] = $r['id'];
    }
}
$rootIds = array_values(array_unique($rootIds));
$forest = array_map(fn($id) => _buildSubtree($byId, $childrenOf, $id), $rootIds);
api_ok(['forest' => $forest]);


function _buildSubtree(array $byId, array $childrenOf, int $id): array {
    $node = $byId[$id];
    $node['children'] = [];
    foreach ($childrenOf[$id] ?? [] as $childId) {
        $node['children'][] = _buildSubtree($byId, $childrenOf, $childId);
    }
    return $node;
}
