<?php
/**
 * CoreFlux Session API
 * Returns current user session data for React SPA
 */

session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Credentials: true');

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Get session data
$user = $_SESSION['user'];
$tenant = $_SESSION['tenant'] ?? null;
$tenantId = $_SESSION['tenant_id'] ?? null;
$activeModule = $_SESSION['active_module'] ?? null;

// Resolve modules FRESH from core/modules.php on every request — never from
// $_SESSION['modules']. The session cookie's serialized module list is taken
// at login time; if we ship a new module (e.g. AP) or rename actions,
// already-logged-in users would keep seeing the stale sidebar until they
// logged out. Reading from getUserModules() each call eliminates that.
require_once __DIR__ . '/core/modules.php';
require_once __DIR__ . '/core/db.php';
$role    = $user['role']        ?? $_SESSION['role']        ?? 'employee';
$globalRole = $user['global_role'] ?? $_SESSION['global_role'] ?? $role;
$modules = function_exists('getUserModules')
    ? getUserModules($role)
    : ($_SESSION['modules'] ?? []);

// Tenant-subscription gate: every non-master_admin user only sees modules
// their active tenant has subscribed to in `tenant_modules`. master_admin
// continues to see every module regardless of subscription (platform ops).
// Tenants with NO `tenant_modules` rows are treated as "all enabled" so a
// freshly-provisioned tenant still works before an admin walks the toggles.
if ($globalRole !== 'master_admin' && $tenantId) {
    try {
        $pdo = getDB();
        if ($pdo) {
            $stmt = $pdo->prepare(
                "SELECT module_key, is_enabled FROM tenant_modules WHERE tenant_id = ?"
            );
            $stmt->execute([(int)$tenantId]);
            $sub = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sub[(string)$r['module_key']] = (int)$r['is_enabled'] === 1;
            }
            if (!empty($sub)) {
                $modules = array_values(array_filter($modules, function ($m) use ($sub) {
                    $key = $m['id'] ?? '';
                    // If the tenant has an explicit row, honour it; if no row
                    // exists for this module, default to enabled (greenfield).
                    return !array_key_exists($key, $sub) || $sub[$key];
                }));
            }
        }
    } catch (Throwable $e) {
        // Silent fall-through: prefer showing the role's full module list
        // over breaking the SPA if tenant_modules query trips on schema drift.
        error_log('session.php tenant_modules filter failed: ' . $e->getMessage());
    }
}

// Format modules with ID for React routing.
// IMPORTANT: prefer the explicit `id` from getModuleDefinitions(); deriving
// the slug from `name` would turn "Accounts Payable" into `accounts_payable`,
// which doesn't match the React route `/modules/ap/*` and falls through to
// GenericModule ("This module is being developed").
$formattedModules = array_map(function($mod) {
    $id = $mod['id'] ?? strtolower(str_replace(' ', '_', $mod['name'] ?? 'module'));
    return [
        'id' => $id,
        'name' => $mod['name'] ?? ucfirst($id),
        'icon' => '/assets/icons/icon-' . $id . '.png',
        'description' => $mod['description'] ?? 'Access ' . ($mod['name'] ?? ucfirst($id)) . ' module',
        'actions' => $mod['actions'] ?? [['name' => 'Overview', 'route' => 'overview']],
    ];
}, $modules);

// Format active module if set
$formattedActiveModule = null;
if ($activeModule) {
    $id = $activeModule['id'] ?? strtolower(str_replace(' ', '_', $activeModule['name'] ?? 'module'));
    $formattedActiveModule = [
        'id' => $id,
        'name' => $activeModule['name'] ?? ucfirst($id),
        'icon' => '/assets/icons/icon-' . $id . '.png',
        'description' => $activeModule['description'] ?? '',
        'actions' => $activeModule['actions'] ?? [['name' => 'Overview', 'route' => 'overview']],
    ];
}

// Build response
$response = [
    'user' => [
        'id' => $user['id'] ?? null,
        'first_name' => $user['first_name'] ?? $user['name'] ?? 'User',
        'last_name' => $user['last_name'] ?? '',
        'email' => $user['email'] ?? '',
        'role' => $user['role'] ?? 'employee',
        'global_role' => $user['global_role'] ?? $_SESSION['global_role'] ?? 'employee',
        'avatar' => $user['avatar'] ?? null,
    ],
    'modules' => $formattedModules,
    'tenant' => $tenant,
    'tenant_id' => $tenantId,
    'tenants' => $user['tenants'] ?? [],
    'active_module' => $formattedActiveModule,
];

echo json_encode($response);
exit;
