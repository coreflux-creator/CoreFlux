<?php
/**
 * /api/cfo_send_report — on-demand email of the current CFO dashboard
 * snapshot to one or more recipients.
 *
 *   POST {
 *     recipients:   ["cfo@example.com", "ceo@example.com"],
 *     subject?:     "Custom subject",
 *     intro?:       "Plain-text intro paragraph",
 *     snapshot:     { ...full dashboard payload as rendered on the client... },
 *     comparison?:  { ...compare block from /api/exec_dashboard.php... },
 *     widgets:      [ { key, title, value_display, secondary?, delta? }, ... ]
 *                   ↑ pre-flattened list the client wants in the email body
 *   }
 *
 * Auth: in-app session. Anyone authenticated; UI gates by role.
 * Delivery: routes through mailerSend(); falls back gracefully if the mailer
 * isn't wired (same pattern as the timesheet approver email).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/audit.php';

$ctx       = api_require_cfo();
$user      = $ctx['user'];
$tenantId  = (int) (currentTenantId() ?? 0);
$method    = api_method();
if (!$tenantId) api_error('No active tenant', 400);
if ($method !== 'POST') api_error('Method not allowed', 405);

$body = api_json_body();
$rawRecipients = $body['recipients'] ?? [];
if (!is_array($rawRecipients)) api_error('recipients must be an array', 422);

$recipients = [];
foreach ($rawRecipients as $r) {
    $r = trim((string) $r);
    if ($r === '' || !filter_var($r, FILTER_VALIDATE_EMAIL)) continue;
    $recipients[] = $r;
}
$recipients = array_values(array_unique($recipients));
if (!$recipients)            api_error('At least one valid recipient email is required', 422);
if (count($recipients) > 25) api_error('Too many recipients (max 25)', 422);

$subject   = trim((string) ($body['subject'] ?? 'CFO Dashboard snapshot'));
$intro     = trim((string) ($body['intro']   ?? ''));
$widgets   = is_array($body['widgets'] ?? null) ? $body['widgets'] : [];
$snapshot  = $body['snapshot']   ?? null;
$comparison= $body['comparison'] ?? null;
$range     = is_array($snapshot['range'] ?? null) ? $snapshot['range'] : ['from' => '', 'to' => ''];

// --- Build the email body --------------------------------------------------
$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$senderName = (string) ($user['name'] ?? $user['email_primary'] ?? 'A teammate');

$bodyHtml  = '<div style="font-family:system-ui;max-width:680px;margin:0 auto;padding:24px;color:#0f172a">';
$bodyHtml .= '<h2 style="margin:0 0 8px;color:#0f172a">CFO Dashboard snapshot</h2>';
$bodyHtml .= '<p style="margin:0 0 16px;color:#64748b;font-size:13px">Window: '
           . $h($range['from']) . ' → ' . $h($range['to'])
           . ($comparison ? ' &nbsp;·&nbsp; vs ' . $h($comparison['prev_from'] ?? '') . ' → ' . $h($comparison['prev_to'] ?? '') : '')
           . '</p>';

if ($intro !== '') {
    $bodyHtml .= '<p style="line-height:1.55;background:#f1f5f9;border-left:3px solid #2563eb;padding:12px 14px;border-radius:0 6px 6px 0">'
              . nl2br($h($intro)) . '</p>';
}

$bodyHtml .= '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;margin-top:8px">';
foreach ($widgets as $w) {
    $title    = $h((string) ($w['title'] ?? $w['key'] ?? ''));
    $valueStr = $h((string) ($w['value_display'] ?? $w['value'] ?? '—'));
    $secStr   = trim((string) ($w['secondary'] ?? ''));
    $deltaStr = trim((string) ($w['delta'] ?? ''));
    $bodyHtml .= '<tr>';
    $bodyHtml .= '<td style="padding:12px 14px;border:1px solid #e2e8f0;background:#fff;border-radius:6px;vertical-align:top">'
               . '<div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em">' . $title . '</div>'
               . '<div style="font-size:22px;font-weight:600;color:#0f172a;margin-top:4px">' . $valueStr . '</div>';
    if ($secStr !== '')   $bodyHtml .= '<div style="font-size:12px;color:#475569;margin-top:2px">' . $h($secStr) . '</div>';
    if ($deltaStr !== '') $bodyHtml .= '<div style="font-size:12px;font-weight:600;color:#2563eb;margin-top:6px">' . $h($deltaStr) . '</div>';
    if (!empty($w['annotation'])) {
        $bodyHtml .= '<p style="margin:10px 0 0;font-size:13px;line-height:1.55;color:#334155;border-top:1px dashed #e2e8f0;padding-top:8px">'
                   . '<em>AI:</em> ' . nl2br($h((string) $w['annotation'])) . '</p>';
    }
    if (!empty($w['note'])) {
        $bodyHtml .= '<p style="margin:6px 0 0;font-size:13px;line-height:1.55;color:#0f172a">'
                   . '<strong>Note:</strong> ' . nl2br($h((string) $w['note'])) . '</p>';
    }
    $bodyHtml .= '</td></tr><tr><td style="height:10px"></td></tr>';
}
$bodyHtml .= '</table>';
$bodyHtml .= '<p style="margin-top:24px;font-size:11px;color:#94a3b8">Sent on-demand from CoreFlux by ' . $h($senderName) . '.</p>';
$bodyHtml .= '</div>';

// --- Dispatch -------------------------------------------------------------
$sent = []; $failed = []; $mailerAvailable = function_exists('mailerSend');
foreach ($recipients as $em) {
    if (!$mailerAvailable) { $failed[] = ['email' => $em, 'reason' => 'mailer not configured']; continue; }
    try {
        mailerSend([
            'to'        => $em,
            'subject'   => $subject,
            'body_html' => $bodyHtml,
            'module'    => 'cfo',
            'purpose'   => 'cfo',
            'tenant_id' => $tenantId,
        ]);
        $sent[] = $em;
    } catch (\Throwable $e) {
        $failed[] = ['email' => $em, 'reason' => substr($e->getMessage(), 0, 250)];
    }
}

// Audit trail.
try {
    platformAuditLogWrite(
        $tenantId,
        (int) ($user['id'] ?? 0) ?: null,
        'cfo.report_sent',
        null,
        [
            'recipients_sent'   => $sent,
            'recipients_failed' => $failed,
            'subject'           => $subject,
            'range'             => $range,
            'widget_count'      => count($widgets),
        ],
        [
            'source' => 'cfo',
            'object_type' => 'dashboard_report',
        ]
    );
} catch (\Throwable $_) { /* audit best-effort */ }

api_ok([
    'sent'             => $sent,
    'failed'           => $failed,
    'mailer_available' => $mailerAvailable,
    'preview_html'     => $bodyHtml,   // Always returned so QA can inspect what would be sent.
]);
