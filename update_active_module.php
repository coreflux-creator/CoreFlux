<?php
/**
 * Update Active Module
 * Called by React SPA when user switches modules
 */

session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Get JSON body
$input = json_decode(file_get_contents('php://input'), true);
$moduleId = $input['module'] ?? null;

if (!$moduleId) {
    http_response_code(400);
    echo json_encode(['error' => 'Module ID required']);
    exit;
}

// Find module in session
$modules = $_SESSION['modules'] ?? [];
$activeModule = null;

foreach ($modules as $mod) {
    $id = strtolower(str_replace(' ', '_', $mod['name'] ?? ''));
    if ($id === $moduleId || ($mod['id'] ?? '') === $moduleId) {
        $activeModule = $mod;
        break;
    }
}

if ($activeModule) {
    $_SESSION['active_module'] = $activeModule;
    echo json_encode(['success' => true, 'module' => $activeModule['name']]);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Module not found']);
}
