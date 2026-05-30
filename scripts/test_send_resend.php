<?php
/**
 * CLI smoke: send a real email through the Resend API using the
 * production key defined in /app/core/config.local.php. Used to
 * verify the wiring end-to-end after Slice 3.1.
 *
 *   php /app/scripts/test_send_resend.php you@example.com other@example.com
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.local.php';
require_once __DIR__ . '/../core/mail/MailDriver.php';
require_once __DIR__ . '/../core/mail/ResendDriver.php';

$recipients = array_slice($argv, 1);
if (!$recipients) {
    fwrite(STDERR, "Usage: php test_send_resend.php <to1> [to2 ...]\n");
    exit(2);
}

$driver = new Core\Mail\ResendDriver();

$envelope = [
    'tenant_id' => 1,
    'module'    => 'admin',
    'purpose'   => 'mail_test_send_cli',
    'to'        => $recipients,
    'subject'   => 'CoreFlux Resend wiring test — ' . gmdate('Y-m-d H:i:s') . ' UTC',
    'body_html' =>
          '<div style="font-family:Inter,system-ui,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#0f172a">'
        . '<h2 style="margin:0 0 12px;color:#0f172a">CoreFlux mailer test</h2>'
        . '<p style="margin:0 0 12px">This message was sent through the live ResendDriver to verify the Slice 3.1 mail wiring.</p>'
        . '<table style="font-size:13px;border-collapse:collapse;margin-top:8px;width:100%">'
        . '<tr><td style="padding:4px 8px;color:#64748b">Driver</td><td style="padding:4px 8px;font-family:ui-monospace,monospace">resend</td></tr>'
        . '<tr><td style="padding:4px 8px;color:#64748b">From envelope</td><td style="padding:4px 8px;font-family:ui-monospace,monospace">' . htmlspecialchars(RESEND_FROM_EMAIL) . '</td></tr>'
        . '<tr><td style="padding:4px 8px;color:#64748b">Recipients</td><td style="padding:4px 8px;font-family:ui-monospace,monospace">' . htmlspecialchars(implode(', ', $recipients)) . '</td></tr>'
        . '<tr><td style="padding:4px 8px;color:#64748b">UTC</td><td style="padding:4px 8px;font-family:ui-monospace,monospace">' . gmdate('Y-m-d H:i:s') . '</td></tr>'
        . '</table>'
        . '<p style="margin:24px 0 0;color:#64748b;font-size:12px">If you received this, your Resend key, verified domain, and the <code>ResendDriver</code> outbound path are all healthy.</p>'
        . '</div>',
    'body_text' => "CoreFlux mailer test\nDriver: resend\nFrom: " . RESEND_FROM_EMAIL . "\nRecipients: " . implode(', ', $recipients) . "\nUTC: " . gmdate('Y-m-d H:i:s') . "\n",
    'tags' => [
        ['name' => 'app',     'value' => 'coreflux'],
        ['name' => 'purpose', 'value' => 'mail_test_send_cli'],
    ],
];

$result = $driver->send($envelope);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['status'] ?? '') === 'sent' ? 0 : 1);
