<?php
session_start();
require_once('../core/db_config.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modes = $_POST['design_mode'];
    $heros = $_POST['show_hero'] ?? [];

    foreach ($modes as $page_class => $mode) {
        $show_hero = isset($heros[$page_class]) ? 1 : 0;
        $stmt = $pdo->prepare("
            REPLACE INTO admin_design_modes (page_class, design_mode, show_hero)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$page_class, $mode, $show_hero]);
    }

    $_SESSION['msg'] = "Design settings saved.";
    header("Location: design_modes.php");
    exit;
}
?>
