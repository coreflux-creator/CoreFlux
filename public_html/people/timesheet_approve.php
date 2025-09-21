<?php
require_once '../core/db.php';

$token = $_GET['token'] ?? '';
$action = $_GET['action'] ?? '';

if (!$token || !in_array($action, ['approve', 'reject'])) {
    die("Invalid link.");
}

// Validate token
$stmt = $pdo->prepare("SELECT * FROM timesheet_tokens WHERE token = ?");
$stmt->execute([$token]);
$tokenRow = $stmt->fetch();

if (!$tokenRow) {
    die("Invalid or expired token.");
}

$timesheet_id = $tokenRow['timesheet_id'];

// Update status
$stmt = $pdo->prepare("UPDATE timesheets SET status = ? WHERE id = ?");
$stmt->execute([$action, $timesheet_id]);

// Record history
$stmt = $pdo->prepare("INSERT INTO timesheet_history (timesheet_id, action) VALUES (?, ?)");
$stmt->execute([$timesheet_id, $action]);

// Delete token to prevent reuse
$stmt = $pdo->prepare("DELETE FROM timesheet_tokens WHERE token = ?");
$stmt->execute([$token]);

echo "Timesheet has been $action" . "d.";
?>
