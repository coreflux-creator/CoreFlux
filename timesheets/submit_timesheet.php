<?php
require_once 'includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = $_SESSION['user_id'];
    $date = $_POST['date'];
    $hours = $_POST['hours'];
    $project = $_POST['project'];

    $stmt = $pdo->prepare("INSERT INTO timesheets (employee_id, date, hours, project, status) VALUES (?, ?, ?, ?, 'Submitted')");
    $stmt->execute([$employee_id, $date, $hours, $project]);

    echo "Timesheet submitted!";
    exit;
}
?>

<h2>Submit Timesheet</h2>
<form method="POST">
    <label>Date:</label>
    <input type="date" name="date" required><br><br>
    
    <label>Hours Worked:</label>
    <input type="number" name="hours" step="0.25" required><br><br>

    <label>Project:</label>
    <input type="text" name="project" required><br><br>

    <input type="submit" value="Submit">
</form>
