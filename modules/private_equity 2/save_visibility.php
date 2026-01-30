<?php
// Example save handler for module/feature visibility toggles
session_start();
require_once('../core/db_config.php'); // Your DB connection

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = [
        'people_module_visible' => isset($_POST['people_module_visible']) ? 1 : 0,
        'finance_module_visible' => isset($_POST['finance_module_visible']) ? 1 : 0,
        'wealth_module_visible' => isset($_POST['wealth_module_visible']) ? 1 : 0,
        'feature_employee_list' => isset($_POST['feature_employee_list']) ? 1 : 0,
        'feature_timesheets' => isset($_POST['feature_timesheets']) ? 1 : 0,
        'feature_invoices' => isset($_POST['feature_invoices']) ? 1 : 0,
        'feature_expenses' => isset($_POST['feature_expenses']) ? 1 : 0,
        'feature_portfolio' => isset($_POST['feature_portfolio']) ? 1 : 0,
        'feature_advisors' => isset($_POST['feature_advisors']) ? 1 : 0
    ];

    foreach ($settings as $key => $value) {
        $stmt = $pdo->prepare("REPLACE INTO admin_visibility_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
    }

    $_SESSION['msg'] = "Visibility settings saved successfully.";
    header("Location: modules_features.php");
    exit;
}
?>
