<?php
session_start();
$data = json_decode(file_get_contents('php://input'), true);
$moduleName = $data['module'] ?? '';

if (!$moduleName) {
  http_response_code(400);
  echo json_encode(['error' => 'Missing module name']);
  exit;
}

foreach ($_SESSION['modules'] as $mod) {
  if ($mod['name'] === $moduleName) {
    $_SESSION['active_module'] = $mod;
    echo json_encode(['status' => 'ok']);
    exit;
  }
}

http_response_code(404);
echo json_encode(['error' => 'Module not found']);
