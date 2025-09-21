<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

if (!isset($_SESSION['is_master_admin']) || !$_SESSION['is_master_admin']) {
    die("Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_name = $_POST['class_name'];
    $liquidation_multiple = $_POST['liquidation_multiple'];
    $participation_cap = $_POST['participation_cap'];
    $convertible = isset($_POST['convertible']) ? 1 : 0;

    $db->query("INSERT INTO pe_class_presets (class_name, liquidation_multiple, participation_cap, convertible) VALUES (?, ?, ?, ?)", [
        $class_name, $liquidation_multiple, $participation_cap, $convertible
    ]);
}

$presets = $db->query("SELECT * FROM pe_class_presets ORDER BY id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Global Share Class Presets</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6">
    <h1 class="text-2xl font-bold mb-4">Global Share Class Presets</h1>

    <form method="post" class="space-y-4 max-w-md mb-6">
        <div>
            <label class="block font-semibold">Class Name</label>
            <input type="text" name="class_name" class="w-full border px-3 py-2" required />
        </div>
        <div>
            <label class="block font-semibold">Liquidation Multiple</label>
            <input type="number" step="0.01" name="liquidation_multiple" class="w-full border px-3 py-2" />
        </div>
        <div>
            <label class="block font-semibold">Participation Cap</label>
            <input type="number" step="0.1" name="participation_cap" class="w-full border px-3 py-2" />
        </div>
        <div>
            <label class="inline-flex items-center">
                <input type="checkbox" name="convertible" class="mr-2" />
                Convertible Note
            </label>
        </div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Add Preset</button>
    </form>

    <h2 class="text-xl font-semibold mb-2">Current Presets</h2>
    <table class="w-full border">
        <thead>
            <tr>
                <th>Class</th>
                <th>Multiple</th>
                <th>Cap</th>
                <th>Convertible</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($presets as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['class_name']) ?></td>
                    <td><?= number_format($p['liquidation_multiple'], 2) ?>x</td>
                    <td><?= number_format($p['participation_cap'], 2) ?>x</td>
                    <td><?= $p['convertible'] ? 'Yes' : 'No' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
