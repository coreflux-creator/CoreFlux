<?php
require_once 'core/db.php';

if (!isset($_GET['id']) || !isset($_GET['action'])) {
    echo "Invalid request.";
    exit;
}

$timesheet_id = intval($_GET['id']);
$action = $_GET['action'] === 'approve' ? 'approved' : 'rejected';

$stmt = $pdo->prepare("UPDATE timesheets SET status = ? WHERE id = ?");
$stmt->execute([$action, $timesheet_id]);

$stmt2 = $pdo->prepare("INSERT INTO timesheet_history (timesheet_id, action) VALUES (?, ?)");
$stmt2->execute([$timesheet_id, $action]);

echo "Timesheet has been {$action}.";
?>
