<?php
session_start();

if (!isset($_SESSION['user'])) {
  http_response_code(401);
  echo json_encode(['error' => 'Not logged in']);
  exit;
}

header('Content-Type: application/json');

echo json_encode([
  'user' => $_SESSION['user'],                       // Name, email, role, etc.
  'modules' => $_SESSION['modules'] ?? [],           // Available modules for this user
  'tenant' => $_SESSION['tenant'] ?? null,           // Currently active tenant
  'tenants' => $_SESSION['user']['tenants'] ?? [],   // List of all accessible tenants
  'active_module' => $_SESSION['active_module'] ?? null // Current module context
]);
exit;
