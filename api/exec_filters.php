<?php
/**
 * /api/exec_filters.php — populate the dashboard filter dropdowns.
 *
 * Returns end-clients, recruiters (users with placement_commissions of role
 * 'recruiter'), placement types, and worksite states pulled from the active
 * tenant's actual data.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';

$ctx      = api_require_auth();
$tenantId = (int) (currentTenantId() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

function _execFiltersSafe(PDO $pdo, string $sql, array $p): array {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($p); return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { return []; }
}

$clients = _execFiltersSafe($pdo,
    "SELECT id, client_name AS name, use_count FROM tenant_end_clients
      WHERE tenant_id = :t ORDER BY use_count DESC, client_name ASC LIMIT 200",
    ['t' => $tenantId]
);

$recruiters = _execFiltersSafe($pdo,
    "SELECT DISTINCT u.id, u.name, u.email
       FROM placement_commissions pc
       JOIN users u ON u.id = pc.user_id
      WHERE pc.tenant_id = :t AND pc.role = 'recruiter' AND pc.user_id IS NOT NULL
   ORDER BY u.name ASC LIMIT 200",
    ['t' => $tenantId]
);

$states = _execFiltersSafe($pdo,
    "SELECT DISTINCT worksite_state AS state FROM placements
      WHERE tenant_id = :t AND worksite_state IS NOT NULL AND worksite_state <> ''
   ORDER BY worksite_state",
    ['t' => $tenantId]
);

api_ok([
    'clients'         => $clients,
    'recruiters'      => $recruiters,
    'placement_types' => ['w2','1099','c2c','direct_hire','temp_to_perm'],
    'worksite_states' => array_column($states, 'state'),
]);
