<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

if (!isset($_SESSION['tenant_id']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized.");
}

$tenant_id = $_SESSION['tenant_id'];
$result = $_SESSION['waterfall_result'] ?? [];
$exit_value = $_SESSION['waterfall_exit_value'] ?? 0;
$scenario_name = $_SESSION['waterfall_scenario_name'] ?? 'Unknown';
$format = $_GET['format'] ?? 'pdf';

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header("Content-Disposition: attachment; filename=Waterfall_{$scenario_name}.csv");
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Shareholder', 'Class', 'Distribution', 'Ownership %']);
    foreach ($result as $row) {
        fputcsv($output, [$row['shareholder'], $row['class'], $row['distribution'], $row['ownership_pct']]);
    }
    fclose($output);
    exit;
} else {
    $mpdf = new Mpdf();
    $mpdf->WriteHTML("<h1>Waterfall Distribution - {$scenario_name}</h1>");
    $mpdf->WriteHTML("<p><strong>Exit Value:</strong> \$" . number_format($exit_value, 2) . "</p>");
    $html = "<table border='1' cellpadding='8' cellspacing='0' width='100%' style='margin-top:15px;'>";
    $html .= "<thead><tr><th>Shareholder</th><th>Class</th><th>Distribution</th><th>Ownership %</th></tr></thead><tbody>";
    foreach ($result as $row) {
        $html .= "<tr>
            <td>{$row['shareholder']}</td>
            <td>{$row['class']}</td>
            <td>$" . number_format($row['distribution'], 2) . "</td>
            <td>" . number_format($row['ownership_pct'], 2) . "%</td>
        </tr>";
    }
    $html .= "</tbody></table>";
    $mpdf->WriteHTML($html);
    $mpdf->Output("Waterfall_{$scenario_name}.pdf", Destination::INLINE);
    exit;
}
