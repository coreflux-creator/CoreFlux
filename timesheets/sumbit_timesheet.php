<?php
require_once '../core/db/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = $_POST['user_id'];
    $date = $_POST['date'];
    $hours = $_POST['hours'];
    $notes = $_POST['notes'];

    $stmt = $pdo->prepare("INSERT INTO timesheets (user_id, date, hours, notes) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $date, $hours, $notes]);

    echo "Timesheet submitted.";
} else {
    echo "Invalid request.";
}
