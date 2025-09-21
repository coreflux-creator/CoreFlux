<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;
if (!$tenant_id) {
    die("Unauthorized");
}

$memo_dir = __DIR__ . "/../../private_uploads/memos/{$tenant_id}/";
$memos = [];

if (is_dir($memo_dir)) {
    $files = scandir($memo_dir);
    foreach ($files as $file) {
        if (str_ends_with($file, ".pdf")) {
            $memos[] = [
                'filename' => $file,
                'path' => $memo_dir . $file,
                'timestamp' => filemtime($memo_dir . $file),
            ];
        }
    }
}
usort($memos, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Memo Archive</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6 bg-gray-50">
    <h1 class="text-3xl font-bold text-blue-800 mb-6">🗂 Investor Memo Archive</h1>

    <?php if (empty($memos)): ?>
        <p class="text-gray-600">No memos found yet. Generate one to get started.</p>
    <?php else: ?>
        <table class="w-full bg-white rounded shadow-md table-auto border">
            <thead class="bg-gray-100 text-sm text-gray-700">
                <tr>
                    <th class="px-4 py-2 text-left">Scenario</th>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-left">Download</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($memos as $memo): 
                    preg_match('/^(.*?)__/', $memo['filename'], $matches);
                    $scenario_name = str_replace('_', ' ', $matches[1] ?? 'Unknown');
                    $date = date("Y-m-d H:i", $memo['timestamp']);
                ?>
                <tr class="border-t hover:bg-gray-50">
                    <td class="px-4 py-2 font-medium"><?= htmlspecialchars($scenario_name) ?></td>
                    <td class="px-4 py-2"><?= $date ?></td>
                    <td class="px-4 py-2">
                        <a class="text-blue-600 hover:underline" href="<?= '../../private_uploads/memos/' . $tenant_id . '/' . urlencode($memo['filename']) ?>" target="_blank">
                            📥 Download
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
