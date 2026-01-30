<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;
$scenario_id = $_GET['scenario_id'] ?? null;

if (!$tenant_id || !$scenario_id) {
    die("Unauthorized or missing scenario ID");
}

$scenario = $db->query("SELECT * FROM pe_scenarios WHERE id = ? AND tenant_id = ?", [$scenario_id, $tenant_id])[0] ?? null;
$cap_table = $db->query("SELECT * FROM pe_cap_tables WHERE scenario_id = ? AND tenant_id = ?", [$scenario_id, $tenant_id]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db->query("DELETE FROM pe_cap_tables WHERE scenario_id = ? AND tenant_id = ?", [$scenario_id, $tenant_id]);

    foreach ($_POST['shareholder'] as $i => $name) {
        $db->query("INSERT INTO pe_cap_tables (tenant_id, scenario_id, shareholder, class, ownership_pct, invested_amount, convertible_note)
                    VALUES (?, ?, ?, ?, ?, ?, ?)", [
            $tenant_id,
            $scenario_id,
            $name,
            $_POST['class'][$i],
            $_POST['ownership_pct'][$i],
            $_POST['invested_amount'][$i],
            isset($_POST['convertible_note'][$i]) ? 1 : 0
        ]);
    }

    header("Location: pe_dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Cap Table</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6 bg-gray-50">
    <h1 class="text-3xl font-bold text-blue-800 mb-6">🧾 Cap Table for: <?= htmlspecialchars($scenario['scenario_name']) ?></h1>

    <form method="post" class="space-y-6 bg-white p-6 shadow rounded-lg">
        <div id="rows">
            <?php for ($i = 0; $i < max(count($cap_table), 3); $i++): 
                $row = $cap_table[$i] ?? ['shareholder'=>'', 'class'=>'', 'ownership_pct'=>'', 'invested_amount'=>'', 'convertible_note'=>0]; ?>
            <div class="grid grid-cols-5 gap-4 mb-2">
                <input name="shareholder[]" type="text" placeholder="Shareholder" value="<?= htmlspecialchars($row['shareholder']) ?>" class="border px-2 py-1 rounded" />
                <input name="class[]" type="text" placeholder="Class" value="<?= htmlspecialchars($row['class']) ?>" class="border px-2 py-1 rounded" />
                <input name="ownership_pct[]" type="number" step="0.01" placeholder="% Ownership" value="<?= $row['ownership_pct'] ?>" class="border px-2 py-1 rounded" />
                <input name="invested_amount[]" type="number" placeholder="Invested $" value="<?= $row['invested_amount'] ?>" class="border px-2 py-1 rounded" />
                <label class="inline-flex items-center space-x-2">
                    <input name="convertible_note[<?= $i ?>]" type="checkbox" <?= $row['convertible_note'] ? 'checked' : '' ?> />
                    <span>Convertible</span>
                </label>
            </div>
            <?php endfor; ?>
        </div>

        <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded shadow hover:bg-blue-700">💾 Save Cap Table</button>
    </form>
</body>
</html>
