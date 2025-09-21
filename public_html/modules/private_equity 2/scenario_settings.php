<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;
if (!$tenant_id) die("Unauthorized");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $preset_name = $_POST['preset_name'] ?? '';
    $share_classes = $_POST['share_classes'] ?? [];

    $db->query("INSERT INTO pe_settings_presets (tenant_id, name) VALUES (?, ?)", [$tenant_id, $preset_name]);
    $preset_id = $db->lastInsertId();

    foreach ($share_classes as $class) {
        $db->query("INSERT INTO pe_settings_share_classes (preset_id, class_name, liquidation_priority, participation) VALUES (?, ?, ?, ?)", [
            $preset_id,
            $class['class_name'],
            $class['liquidation_priority'],
            $class['participation']
        ]);
    }
}

$presets = $db->query("SELECT * FROM pe_settings_presets WHERE tenant_id = ?", [$tenant_id]);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Scenario Settings</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6">
    <h1 class="text-2xl font-bold mb-4">Cap Table Presets & Share Classes</h1>

    <form method="post">
        <div class="mb-4">
            <label class="block font-bold">Preset Name</label>
            <input type="text" name="preset_name" required class="w-full border rounded px-3 py-2" />
        </div>
        <div id="classList" class="mb-4">
            <label class="block font-bold mb-2">Share Classes</label>
            <div class="flex space-x-4 mb-2">
                <input type="text" name="share_classes[0][class_name]" placeholder="Class Name" class="border px-2 py-1" />
                <input type="number" step="0.1" name="share_classes[0][liquidation_priority]" placeholder="Priority" class="border px-2 py-1" />
                <select name="share_classes[0][participation]" class="border px-2 py-1">
                    <option value="None">None</option>
                    <option value="Capped">Capped</option>
                    <option value="Uncapped">Uncapped</option>
                </select>
            </div>
        </div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Preset</button>
    </form>

    <h2 class="text-xl font-bold mt-8">Saved Presets</h2>
    <ul class="list-disc pl-6">
        <?php foreach ($presets as $p): ?>
            <li><?= htmlspecialchars($p['name']) ?></li>
        <?php endforeach; ?>
    </ul>

    <script>
        let counter = 1;
        function addClassRow() {
            const container = document.getElementById('classList');
            const row = document.createElement('div');
            row.className = 'flex space-x-4 mb-2';
            row.innerHTML = `
                <input type="text" name="share_classes[\${counter}][class_name]" placeholder="Class Name" class="border px-2 py-1" />
                <input type="number" step="0.1" name="share_classes[\${counter}][liquidation_priority]" placeholder="Priority" class="border px-2 py-1" />
                <select name="share_classes[\${counter}][participation]" class="border px-2 py-1">
                    <option value="None">None</option>
                    <option value="Capped">Capped</option>
                    <option value="Uncapped">Uncapped</option>
                </select>
            `;
            container.appendChild(row);
            counter++;
        }
    </script>
    <button onclick="addClassRow()" type="button" class="mt-2 text-blue-600">+ Add Share Class</button>
</body>
</html>
