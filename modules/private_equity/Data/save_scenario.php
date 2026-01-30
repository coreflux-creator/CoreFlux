<?php
include_once '../../core/db_config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenant_id = $_SESSION['tenant_id'] ?? null;
    $name = trim($_POST['scenario_name'] ?? '');
    $pre = floatval($_POST['pre_money'] ?? 0);
    $post = floatval($_POST['post_money'] ?? 0);
    $exit = floatval($_POST['exit_value'] ?? 0);
    $cap_rate = floatval($_POST['cap_rate'] ?? 0);
    $option_pool_pct = floatval($_POST['option_pool_pct'] ?? 0);
    $participation_cap = floatval($_POST['participation_cap'] ?? 2);
    $trigger_tiers = isset($_POST['trigger_tiers']) ? 1 : 0;

    if (!$tenant_id || !$name || $pre <= 0) {
        http_response_code(400);
        echo "Invalid data.";
        exit;
    }

    $db->query("INSERT INTO pe_scenarios
        (tenant_id, scenario_name, pre_money, post_money, exit_value, cap_rate, option_pool_pct, participation_cap, trigger_tiers)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$tenant_id, $name, $pre, $post, $exit, $cap_rate, $option_pool_pct, $participation_cap, $trigger_tiers]
    );

    header("Location: ../scenarios.php?success=1");
    exit;
}

http_response_code(405);
echo "Method Not Allowed";
