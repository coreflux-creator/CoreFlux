<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

if (!isset($_SESSION['is_master_admin']) || !$_SESSION['is_master_admin']) {
    die("Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenant_id = $_POST['tenant_id'];
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    $exists = $db->query("SELECT * FROM tenant_features WHERE tenant_id = ? AND feature = 'private_equity'", [$tenant_id]);

    if ($exists) {
        $db->query("UPDATE tenant_features SET enabled = ? WHERE tenant_id = ? AND feature = 'private_equity'", [$enabled, $tenant_id]);
    } else {
        $db->query("INSERT INTO tenant_features (tenant_id, feature, enabled) VALUES (?, 'private_equity', ?)", [$tenant_id, $enabled]);
    }
}

$tenants = $db->query("SELECT id, tenant_name FROM tenants ORDER BY tenant_name ASC");
$features = $db->query("SELECT tenant_id, enabled FROM tenant_features WHERE feature = 'private_equity'");
$feature_map = [];
foreach ($features as $f) {
    $feature_map[$f['tenant_id']] = $f['enabled'];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>PE Feature Permissions</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6">
    <h1 class="text-2xl font-bold mb-4">Enable/Disable PE Module</h1>

    <form method="post" class="space-y-4 max-w-lg">
        <div>
            <label class="block font-semibold">Select Tenant</label>
            <select name="tenant_id" class="w-full border px-3 py-2">
                <?php foreach ($tenants as $t): ?>
                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['tenant_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="inline-flex items-center">
                <input type="checkbox" name="enabled" class="mr-2" />
                Enable Private Equity Module
            </label>
        </div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Update</button>
    </form>

    <h2 class="text-xl font-semibold mt-8 mb-2">Current Permissions</h2>
    <table class="w-full border">
        <thead>
            <tr>
                <th>Tenant</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tenants as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['tenant_name']) ?></td>
                    <td><?= ($feature_map[$t['id']] ?? 0) ? 'Enabled' : 'Disabled' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
