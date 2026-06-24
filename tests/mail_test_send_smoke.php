<?php
/**
 * Mail test-send smoke — verifies the /admin endpoint contract + the
 * React component wiring inside MailBrandingAdmin.
 *
 *   php -d zend.assertions=1 /app/tests/mail_test_send_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- endpoint
echo "/api/admin/mail_test_send.php\n";
$path = $ROOT . '/api/admin/mail_test_send.php';
$ep   = (string) file_get_contents($path);
$rc   = 0; $o = [];
exec('php -l ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
$a('php -l clean',                                       $rc === 0);
$a('requires api_bootstrap',                             $c($ep, "require_once __DIR__ . '/../../core/api_bootstrap.php'"));
$a('requires core/mailer.php',                           $c($ep, "require_once __DIR__ . '/../../core/mailer.php'"));
$a('POST-only',                                          $c($ep, "api_method() !== 'POST'"));
$a('admin gate (master_admin / tenant_admin / global)',
    $c($ep, "in_array(\$role, ['master_admin', 'tenant_admin']") && $c($ep, '$isGlobalAdmin'));
$a('requires active tenant',                             $c($ep, "if (\$tenantId <= 0) api_error('No active tenant'"));
$a('validates recipient email',                          $c($ep, 'FILTER_VALIDATE_EMAIL'));
$a('defaults subject when blank',                        $c($ep, 'CoreFlux mailer test'));
$a('defaults body_html when blank',                      $c($ep, 'This is a CoreFlux mailer test message.'));
$a('rate-limits to 10 seconds per admin',                $c($ep, '10 - $secs') && $c($ep, '429'));
$a('detects resend configuration (env OR define)',
    $c($ep, "getenv('RESEND_API_KEY') !== ''")
    && $c($ep, "defined('RESEND_API_KEY')"));
$a('delegates to mailerSend()',                          $c($ep, 'mailerSend(['));
$a('writes audit event on send attempt',                 $c($ep, 'mail.test_send') && $c($ep, 'platformAuditLogWrite('));
$a('returns ok/driver/message_id/error',                 $c($ep, "'ok'") && $c($ep, "'driver'")
                                                         && $c($ep, "'message_id'") && $c($ep, "'error'"));
$a('returns resend_configured flag',                     $c($ep, "'resend_configured'"));
$a('returns recipient + subject for echo',               $c($ep, "'recipient'") && $c($ep, "'subject'"));

// ----------------------------------------------------------------- React wiring
// Slice 3.2 — MailTestSendCard was extracted to its own file so it
// can be mounted on both the admin Branding page AND the tenant
// Mail Settings page.
// Slice 3.3.1 — Card also warns when the recipient is on the
// suppression list and offers a one-click un-suppress.
echo "\nMailTestSendCard.jsx — shared component\n";
$card = (string) file_get_contents($ROOT . '/dashboard/src/pages/MailTestSendCard.jsx');
$a('MailTestSendCard default export',                    $c($card, 'export default function MailTestSendCard'));
$a('root testid',                                        $c($card, 'data-testid="admin-mail-test-send"'));
$a('recipient input testid',                             $c($card, 'admin-mail-test-send-recipient'));
$a('submit button testid',                               $c($card, 'admin-mail-test-send-submit'));
$a('result panel testid',                                $c($card, 'admin-mail-test-send-result'));
$a('status pill testid',                                 $c($card, 'admin-mail-test-send-status'));
$a('driver pill testid',                                 $c($card, 'admin-mail-test-send-driver'));
$a('message_id row testid',                              $c($card, 'admin-mail-test-send-msgid'));
$a('fallback notice testid',                             $c($card, 'admin-mail-test-send-fallback'));
$a('error message testid',                               $c($card, 'admin-mail-test-send-msg-error'));
$a('RESEND key on/off badges',                           $c($card, 'admin-mail-test-send-resend-on')
                                                         && $c($card, 'admin-mail-test-send-resend-off'));
$a('POSTs to /api/admin/mail_test_send.php',             $c($card, "/api/admin/mail_test_send.php"));
$a('disables form when not canWrite or busy',            $c($card, 'disabled={!canWrite || busy'));
// Slice 3.3.1 — suppression warning surfaces
$a('debounces suppression lookup',                       $c($card, 'setTimeout(async () => {')
                                                         && $c($card, '350'));
$a('queries mail_suppressions for exact match',          $c($card, '/api/admin/mail_suppressions.php?q=')
                                                         && $c($card, '(r.rows || []).find((row) => row.email === email)'));
$a('renders warning banner when suppressed',             $c($card, 'data-testid="admin-mail-test-send-suppression-warn"'));
$a('exposes suppressed-email testid for assertions',     $c($card, 'data-testid="admin-mail-test-send-suppression-email"'));
$a('un-suppress button calls DELETE with ?id=',          $c($card, 'data-testid="admin-mail-test-send-unsuppress"')
                                                         && $c($card, "api.delete(`/api/admin/mail_suppressions.php?id=\${suppressedHit.id}`)"));
$a('suppression check tolerates fetch failure',          $c($card, '// Suppression list unreachable — never block the test send.'));

echo "\nMailBrandingAdmin.jsx — imports + mounts MailTestSendCard\n";
$pg = (string) file_get_contents($ROOT . '/dashboard/src/pages/MailBrandingAdmin.jsx');
$a('imports MailTestSendCard',                           $c($pg, "import MailTestSendCard from './MailTestSendCard'"));
$a('embedded after the branding form',                   $c($pg, '<MailTestSendCard'));

echo "\nMailSettingsPage.jsx — imports + mounts MailTestSendCard\n";
$ms = (string) file_get_contents($ROOT . '/dashboard/src/pages/MailSettingsPage.jsx');
$a('imports MailTestSendCard',                           $c($ms, "import MailTestSendCard from './MailTestSendCard'"));
$a('embedded on tenant mail settings page',              $c($ms, '<MailTestSendCard'));

// ----------------------------------------------------------------- summary
echo "\n=========================================\n";
echo "Mail test-send smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
