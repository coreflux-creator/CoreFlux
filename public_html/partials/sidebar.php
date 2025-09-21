<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define default (empty) context menu
$sidebarLinks = [];

// Determine current context/module (set this in your view file)
$activeModule = $_SESSION['active_module'] ?? '';

// Define context-aware menus
$contextMenus = [
    'timesheets' => [
        ['name' => 'Submit Timesheet', 'link' => '/timesheets/submit.php'],
        ['name' => 'Edit Timesheet', 'link' => '/timesheets/edit.php'],
        ['name' => 'View Approval Status', 'link' => '/timesheets/status.php']
    ],
    'documents' => [
        ['name' => 'My Files', 'link' => '/documents/my_files.php'],
        ['name' => 'Shared With Me', 'link' => '/documents/shared.php'],
        ['name' => 'Upload New', 'link' => '/documents/upload.php']
    ],
    'dashboard' => [
        ['name' => 'Overview', 'link' => '/dashboard.php'],
        ['name' => 'My Tasks', 'link' => '/dashboard/tasks.php'],
        ['name' => 'Notifications', 'link' => '/dashboard/notifications.php']
    ]
];

// Load the correct menu for the current context
if (isset($contextMenus[$activeModule])) {
    $sidebarLinks = $contextMenus[$activeModule];
}
?>

<aside class="sidebar">
  <ul class="sidebar-nav">
    <?php foreach ($sidebarLinks as $item): ?>
      <li>
        <a href="<?= htmlspecialchars($item['link']) ?>">
          <?= htmlspecialchars($item['name']) ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</aside>
