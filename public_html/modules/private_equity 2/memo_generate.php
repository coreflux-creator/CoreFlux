<?php
require_once '../../vendor/autoload.php';
include_once '../../core/db_config.php';
include_once '../../core/helpers/email_helper.php';
session_start();

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

$tenant_id = $_SESSION['tenant_id'] ?? null;
$scenario_id = $_POST['scenario_id'] ?? null;
$cover_letter = $_POST['cover_letter'] ?? '';
$send_to = $_POST['send_to'] ?? '';

if (!$tenant_id || !$scenario_id) {
    die("Invalid request");
}

$scenario = $db->query("SELECT * FROM pe_scenarios WHERE id = ? AND tenant_id = ?", [$scenario_id, $tenant_id])[0] ?? null;
$cap_table = $db->query("SELECT * FROM pe_cap_tables WHERE scenario_id = ? AND tenant_id = ?", [$scenario_id, $tenant_id]);

$mpdf = new Mpdf();

// 1. Render Letterhead + Cover Letter
$mpdf->WriteHTML("<h1 style='border-bottom:1px solid #ccc;'>D24 Capital | Investor Memorandum</h1>");
$mpdf->WriteHTML("<p><strong>Scenario:</strong> {$scenario['scenario_name']}</p>");
$mpdf->WriteHTML("<div style='margin-top:30px; white-space: pre-wrap;'>" . nl2br(htmlspecialchars($cover_letter)) . "</div>");
$mpdf->AddPage();

// 2. Add memo summary
$html = "<h2>Memo Summary</h2>";
$html .= "<p><strong>Pre-Money Valuation:</strong> \$" . number_format($scenario['pre_money'], 0) . "</p>";
$html .= "<p><strong>Post-Money Valuation:</strong> \$" . number_format($scenario['post_money'], 0) . "</p>";
$html .= "<p><strong>Exit Value:</strong> \$" . number_format($scenario['exit_value'], 2) . "</p>";
$html .= "<p><strong>Cap Rate:</strong> {$scenario['cap_rate']} | <strong>Participation Cap:</strong> {$scenario['participation_cap']}x</p>";
$html .= "<table border='1' cellpadding='8' cellspacing='0' width='100%' style='margin-top:15px;'>";
$html .= "<thead><tr><th>Shareholder</th><th>Class</th><th>Ownership %</th><th>Invested</th><th>Convertible</th></tr></thead><tbody>";
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
$mpdf->WriteHTML($html);

// 3. Attach uploaded files
if (!empty($_FILES['attachments']['name'][0])) {
    foreach ($_FILES['attachments']['tmp_name'] as $i => $tmpFile) {
        if (is_uploaded_file($tmpFile)) {
            $mpdf->AddPage();
            $type = mime_content_type($tmpFile);
            if (str_starts_with($type, 'image/')) {
                $mpdf->WriteHTML("<img src='" . base64_encode(file_get_contents($tmpFile)) . "' style='width:100%' />");
            } elseif ($type === 'application/pdf') {
                $mpdf->SetImportUse();
                $pageCount = $mpdf->SetSourceFile($tmpFile);
                for ($p = 1; $p <= $pageCount; $p++) {
                    $tplId = $mpdf->ImportPage($p);
                    $mpdf->UseTemplate($tplId);
                    if ($p < $pageCount) $mpdf->AddPage();
                }
            }
        }
    }
}

$filename = "Investor_Memo_{$scenario['scenario_name']}.pdf";
$pdfData = $mpdf->Output($filename, Destination::STRING_RETURN);

if ($send_to) {
    send_investor_memo_email($send_to, $filename, $pdfData);
    echo "<div class='p-6'><h2 class='text-xl font-bold text-green-700'>Sent to $send_to</h2><a href='memo_customize.php' class='text-blue-600'>Back</a></div>";
} else {
    header("Content-Type: application/pdf");
    header("Content-Disposition: inline; filename=$filename");
    echo $pdfData;
    exit;
}
