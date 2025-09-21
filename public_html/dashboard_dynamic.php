
<?php
session_start();
$user = $_SESSION['user'] ?? ['name' => 'User'];
$module = $_GET['module'] ?? 'People';
$dashboardHtml = '';

$path = __DIR__ . "/config/modules/{$module}_module_actions.json";
if (file_exists($path)) {
    $data = json_decode(file_get_contents($path), true);
    $dashboardHtml .= "<div class='dashboard'>";
    $dashboardHtml .= "<h2>Welcome back, {$user['name']}!</h2>";
    $dashboardHtml .= "<p>Your workspace and updates are below.</p>";
    $dashboardHtml .= "<div class='action-cards'>";

    foreach ($data['actions'] as $action) {
        $dashboardHtml .= "<div class='action-card'>";
        $dashboardHtml .= "<img src='{$action['icon']}' alt='{$action['name']}'>";
        $dashboardHtml .= "<h3>{$action['name']}</h3>";
        $dashboardHtml .= "<p>{$action['description']}</p>";
        $dashboardHtml .= "<a href='{$action['link']}'>Go</a>";
        $dashboardHtml .= "</div>";
    }

    $dashboardHtml .= "</div></div>";
}

include 'layout.php';
echo $dashboardHtml;
include 'footer.php';
?>
