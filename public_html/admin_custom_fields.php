<?php
require_once '../config/db.php';
require_once '../helpers/custom_field_helpers.php';

$tenant_id = 1;
$module = $_GET['module'] ?? 'users';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("INSERT INTO custom_fields (tenant_id, module, field_name, field_label, field_type, is_required, options)
                           VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $tenant_id,
        $module,
        $_POST['field_name'],
        $_POST['field_label'],
        $_POST['field_type'],
        isset($_POST['is_required']) ? 1 : 0,
        $_POST['options'] ?? null
    ]);
    header("Location: custom_fields.php?module=$module");
    exit;
}

$fields = get_custom_fields($pdo, $module, $tenant_id);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Custom Fields</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
    <h1>Manage Custom Fields for <?= htmlspecialchars($module) ?></h1>
    <form method="POST">
        <input type="text" name="field_name" placeholder="Field Name" required>
        <input type="text" name="field_label" placeholder="Field Label" required>
        <select name="field_type">
            <option value="text">Text</option>
            <option value="number">Number</option>
            <option value="date">Date</option>
            <option value="boolean">Checkbox</option>
            <option value="dropdown">Dropdown</option>
        </select>
        <input type="text" name="options" placeholder="Options (comma-separated)">
        <label><input type="checkbox" name="is_required"> Required</label>
        <button type="submit">Add</button>
    </form>

    <h2>Existing Fields</h2>
    <ul>
        <?php foreach ($fields as $f): ?>
        <li><b><?= $f['field_label'] ?></b> (<?= $f['field_type'] ?>)</li>
        <?php endforeach; ?>
    </ul>
</body>
</html>