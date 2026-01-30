<?php
require_once '../../vendor/autoload.php';
include_once '../../core/db_config.php';
session_start();

use Mpdf\Mpdf;

$tenant_id = $_SESSION['tenant_id'] ?? null;
$scenario_id = $_GET['scenario_id'] ?? null;

if (!$tenant_id || !$scenario_id) {
  die("Invalid access.");
}

$scenario = $db->query("SELECT * FROM pe_scenarios WHERE id = ? AND tenant_id = ?", [$scenario_id, $tenant_id])[0] ?? null;
$cap_table = $db->query("SELECT * FROM pe_cap_tables WHERE scenario_id = ? AND tenant_id = ?", [$scenario_id, $tenant_id]);

if (!$scenario || !$cap_table) {
  die("Scenario not found.");
}

$exit_value = number_format($scenario['exit_value'], 2);
$html = "<h1>Investor Summary Memo</h1>";
$html .= "<p><strong>Scenario:</strong> {$scenario['scenario_name']}</p>";
$html .= "<p><strong>Pre-Money Valuation:</strong> \$" . number_format($scenario['pre_money'], 0) . "</p>";
$html .= "<p><strong>Post-Money Valuation:</strong> \$" . number_format($scenario['post_money'], 0) . "</p>";
$html .= "<p><strong>Exit Value:</strong> \$${exit_value}</p>";
$html .= "<p><strong>Cap Rate:</strong> {$scenario['cap_rate']} | <strong>Participation Cap:</strong> {$scenario['participation_cap']}x</p>";
$html .= "<hr><table border='1' cellpadding='8' cellspacing='0' width='100%'>";
$html .= "<thead><tr><th>Shareholder</th><th>Class</th><th>Ownership %</th><th>Invested</th><th>Convertible Note</th></tr></thead><tbody>";

foreach ($cap_table as $row) {
  $html .= "<tr>
    <td>{$row['shareholder']}</td>
    <td>{$row['class']}</td>
    <td>" . number_format($row['ownership_pct'], 2) . "%</td>
    <td>$" . number_format($row['invested_amount'], 0) . "</td>
    <td>" . ($row['convertible_note'] ? 'Yes' : 'No') . "</td>
  </tr>";
}
$html .= "</tbody></table>";

$mpdf = new Mpdf();
$mpdf->WriteHTML($html);
$mpdf->Output("Investor_Memo_{$scenario['scenario_name']}.pdf", \Mpdf\Output\Destination::INLINE);
