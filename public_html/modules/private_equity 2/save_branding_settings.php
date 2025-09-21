<?php
session_start();
require_once('../core/db_config.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $logo_filename = $_FILES['tenant_logo']['name'] ?? '';
    if (!empty($logo_filename)) {
        $ext = pathinfo($logo_filename, PATHINFO_EXTENSION);
        $logo_name = 'tenant_logo_' . time() . '.' . $ext;
        move_uploaded_file($_FILES['tenant_logo']['tmp_name'], "../assets/logos/" . $logo_name);
    } else {
        $logo_name = $_POST['existing_logo'] ?? '';
    }

    $stmt = $pdo->prepare("REPLACE INTO admin_branding_settings 
        (id, tenant_logo, email_from_name, email_from, reply_to, primary_color, color_mode) 
        VALUES (1, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $logo_name,
        $_POST['email_from_name'],
        $_POST['email_from'],
        $_POST['reply_to'],
        $_POST['primary_color'],
        $_POST['color_mode']
    ]);

    $_SESSION['msg'] = "Branding settings saved.";
    header("Location: branding_settings.php");
    exit;
}
