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
$role    = $user['role']        ?? $_SESSION['role']        ?? 'employee';
$modules = function_exists('getUserModules')
    ? getUserModules($role)
    : ($_SESSION['modules'] ?? []);

// Format modules with ID for React routing
$formattedModules = array_map(function($mod) {
    $id = strtolower(str_replace(' ', '_', $mod['name'] ?? $mod['id'] ?? 'module'));
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
    $id = strtolower(str_replace(' ', '_', $activeModule['name'] ?? 'module'));
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
