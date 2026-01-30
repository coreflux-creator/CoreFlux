<?php
require_once '../core/db/config.php';

$stmt = $pdo->query("SELECT t.*, u.name FROM timesheets t JOIN users u ON t.user_id = u.id ORDER BY t.date DESC");
$timesheets = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Review Timesheets</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="/time_sheet_review/js/review_timesheets.js" defer></script>
</head>
<body>
    <div class="container">
        <h2>Review Timesheets</h2>
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Date</th>
                    <th>Hours</th>
                    <th>Notes</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($timesheets as $row): ?>
                <tr class="timesheet-row" data-id="<?= $row['id'] ?>">
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= $row['date'] ?></td>
                    <td><?= $row['hours'] ?></td>
                    <td><?= $row['notes'] ?></td>
                    <td><?= $row['status'] ?></td>
                    <td>
                        <button class="approve-btn">Approve</button>
                        <button class="reject-btn">Reject</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
