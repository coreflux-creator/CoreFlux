<?php
require_once '../core/db/config.php';

$id = $_GET['id'] ?? null;
$action = $_GET['action'] ?? null;

if ($id && in_array($action, ['approve', 'reject'])) {
    $stmt = $pdo->prepare("UPDATE timesheets SET status = ? WHERE id = ?");
    $stmt->execute([$action, $id]);
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
}
