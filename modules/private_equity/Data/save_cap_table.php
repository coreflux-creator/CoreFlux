<?php
include_once '../../core/db_config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $scenario_id = $_POST['scenario_id'] ?? null;
    $tenant_id = $_SESSION['tenant_id'] ?? null;

    if (!$scenario_id || !$tenant_id) {
        http_response_code(400);
        echo "Missing scenario or tenant ID.";
        exit;
    }

    // Clear existing entries for this scenario
    $db->query("DELETE FROM pe_cap_tables WHERE scenario_id = ? AND tenant_id = ?", [$scenario_id, $tenant_id]);

    // Prepare insert query
    $rows = count($_POST['shareholder'] ?? []);
    for ($i = 0; $i < $rows; $i++) {
        $shareholder = $_POST['shareholder'][$i] ?? '';
        $class = $_POST['class'][$i] ?? 'Common';
        $ownership_pct = floatval($_POST['ownership_pct'][$i] ?? 0);
        $invested_amount = floatval($_POST['invested_amount'][$i] ?? 0);
        $convertible_note = isset($_POST['convertible_note'][$i]) ? 1 : 0;
        $memo_notes = $_POST['memo_notes'][$i] ?? '';

        if (trim($shareholder) !== '') {
            $db->query("INSERT INTO pe_cap_tables (tenant_id, scenario_id, shareholder, class, ownership_pct, invested_amount, convertible_note, memo_notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [$tenant_id, $scenario_id, $shareholder, $class, $ownership_pct, $invested_amount, $convertible_note, $memo_notes]);
        }
    }

    header("Location: ../cap_table.php?scenario_id=$scenario_id&success=1");
    exit;
} else {
    http_response_code(405);
    echo "Method Not Allowed";
}
?>
