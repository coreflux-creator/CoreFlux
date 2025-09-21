<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;
if (!$tenant_id) die("Unauthorized");

$upload_dir = __DIR__ . "/../../private_uploads/investor_docs/{$tenant_id}/";
if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['doc_upload'])) {
    $file = $_FILES['doc_upload'];
    if ($file['error'] === 0 && is_uploaded_file($file['tmp_name'])) {
        $safe_name = time() . "_" . basename($file['name']);
        move_uploaded_file($file['tmp_name'], $upload_dir . $safe_name);
        $success = true;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Investor Document Upload</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6">
    <h1 class="text-2xl font-bold mb-4">Investor Document Upload</h1>

    <?php if ($success): ?>
        <div class="text-green-700 font-semibold mb-4">Upload successful!</div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <label class="block font-bold mb-2">Upload a file</label>
        <input type="file" name="doc_upload" required class="mb-4" />
        <br />
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Upload</button>
    </form>

    <div class="mt-6">
        <a href="scenario_compare.php" class="text-blue-600">← Back to Dashboard</a>
    </div>
</body>
</html>
