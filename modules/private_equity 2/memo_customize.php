<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;

if (!$tenant_id) {
    die("Unauthorized");
}

$scenarios = $db->query("SELECT * FROM pe_scenarios WHERE tenant_id = ?", [$tenant_id]);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Investor Memo Customization</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6 bg-gray-50">
    <h1 class="text-3xl font-bold text-blue-800 mb-6">📄 Generate Investor Memo</h1>

    <form action="memo_generate.php" method="post" enctype="multipart/form-data" class="bg-white shadow-md rounded-lg p-6 space-y-4 max-w-3xl">
        <div>
            <label class="block font-semibold mb-1">Select Scenario</label>
            <select name="scenario_id" required class="w-full border px-3 py-2 rounded">
                <option value="">-- Choose Scenario --</option>
                <?php foreach ($scenarios as $scenario): ?>
                    <option value="<?= $scenario['id'] ?>"><?= htmlspecialchars($scenario['scenario_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block font-semibold mb-1">Cover Letter (will be shown on D24 Capital letterhead)</label>
            <textarea name="cover_letter" rows="10" placeholder="Write your investor letter..." required class="w-full border px-3 py-2 rounded font-mono"></textarea>
        </div>

        <div>
            <label class="block font-semibold mb-1">Attach Pages or Images</label>
            <input type="file" name="attachments[]" multiple class="w-full border px-3 py-2 rounded" />
            <small class="text-gray-500">You can upload PDFs or images (JPG, PNG, etc).</small>
        </div>

        <div>
            <label class="block font-semibold mb-1">Send To (optional)</label>
            <input type="email" name="send_to" placeholder="investor@example.com" class="w-full border px-3 py-2 rounded" />
            <small class="text-gray-500">Leave blank to preview only. Enter an email to send the memo.</small>
        </div>

        <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded shadow hover:bg-green-700">📤 Generate PDF</button>
    </form>
</body>
</html>
