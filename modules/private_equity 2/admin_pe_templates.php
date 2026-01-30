<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

if (!isset($_SESSION['is_master_admin']) || !$_SESSION['is_master_admin']) {
    die("Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $template_name = $_POST['template_name'];
    $body = $_POST['body'];

    $db->query("INSERT INTO pe_memo_templates (template_name, body) VALUES (?, ?)", [
        $template_name, $body
    ]);
}

$templates = $db->query("SELECT * FROM pe_memo_templates ORDER BY id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Memo Template Library</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6">
    <h1 class="text-2xl font-bold mb-4">Memo Template Library</h1>

    <form method="post" class="space-y-4 max-w-xl mb-6">
        <div>
            <label class="block font-semibold">Template Name</label>
            <input type="text" name="template_name" class="w-full border px-3 py-2" required />
        </div>
        <div>
            <label class="block font-semibold">Body (HTML supported)</label>
            <textarea name="body" rows="8" class="w-full border px-3 py-2" required></textarea>
        </div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Add Template</button>
    </form>

    <h2 class="text-xl font-semibold mb-2">Saved Templates</h2>
    <table class="w-full border">
        <thead>
            <tr>
                <th>Name</th>
                <th>Preview</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($templates as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['template_name']) ?></td>
                    <td><?= nl2br(htmlspecialchars(substr($t['body'], 0, 200))) ?>...</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
